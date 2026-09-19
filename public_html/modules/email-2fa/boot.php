<?php
require_once ROOT_PATH . '/models/User.php';
require_once ROOT_PATH . '/models/Settings.php';
require_once ROOT_PATH . '/core/EmailSender.php';

define('MOTHERBOARD_2FA_COOKIE', 'mb_trusted_device');
define('MOTHERBOARD_2FA_TRUST_DAYS', 30);
// Shared workstations are normal (a front counter, a shop floor PC), so the cookie
// carries a set of users rather than one. Capped so it cannot grow without bound.
define('MOTHERBOARD_2FA_MAX_TRUSTED_USERS', 10);

/**
 * Signed marker proving this browser previously completed a second factor.
 *
 * Replaces the previous "have we seen this IP in 30 days" test, which trusted a property
 * of the network rather than the browser: anyone sharing the victim's NAT egress address
 * (an office, a VPN, carrier-grade NAT) skipped 2FA entirely with just the password.
 */
function motherboard_2fa_sign(string $payload): string {
    return hash_hmac('sha256', $payload, APP_ENCRYPTION_KEY);
}

/**
 * The still-valid trust entries in this browser's cookie, as [userId => expiry].
 * Returns an empty set if the signature does not verify.
 */
function motherboard_2fa_trusted_users(): array {
    $raw = $_COOKIE[MOTHERBOARD_2FA_COOKIE] ?? '';
    if (!is_string($raw) || substr_count($raw, '|') !== 2) {
        return [];
    }

    $parts = explode('|', $raw);
    $trusted = [];

    if ($parts[0] === 'v2') {
        [, $list, $signature] = $parts;
        if (!hash_equals(motherboard_2fa_sign($list), $signature)) {
            return [];
        }
        foreach (explode(',', $list) as $entry) {
            if (substr_count($entry, ':') !== 1) {
                continue;
            }
            [$userId, $expires] = explode(':', $entry);
            if ((int) $userId > 0) {
                $trusted[(int) $userId] = (int) $expires;
            }
        }
    } else {
        // Single-user cookie issued before shared devices were supported. Honour it
        // so nobody is re-challenged by the upgrade; it is rewritten on next trust.
        [$userId, $expires, $signature] = $parts;
        if (!hash_equals(motherboard_2fa_sign($userId . '|' . $expires), $signature)) {
            return [];
        }
        if ((int) $userId > 0) {
            $trusted[(int) $userId] = (int) $expires;
        }
    }

    $now = time();
    return array_filter($trusted, static fn(int $expires): bool => $expires > $now);
}

function motherboard_2fa_device_is_trusted(int $userId): bool {
    return isset(motherboard_2fa_trusted_users()[$userId]);
}

function motherboard_2fa_trust_device(int $userId): void {
    if (headers_sent()) {
        return;
    }

    $trusted = motherboard_2fa_trusted_users();
    $trusted[$userId] = time() + (MOTHERBOARD_2FA_TRUST_DAYS * 86400);

    // Trim the least recently trusted accounts first, so the person using the
    // machine today is never the one evicted.
    arsort($trusted);
    $trusted = array_slice($trusted, 0, MOTHERBOARD_2FA_MAX_TRUSTED_USERS, true);

    $entries = [];
    foreach ($trusted as $id => $expires) {
        $entries[] = $id . ':' . $expires;
    }
    $list = implode(',', $entries);

    $params = session_get_cookie_params();
    setcookie(MOTHERBOARD_2FA_COOKIE, 'v2|' . $list . '|' . motherboard_2fa_sign($list), [
        'expires' => max($trusted),
        'path' => '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool) ($params['secure'] ?? false),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

Hooks::addFilter('auth.login.after_credentials', function (array $gate, array $user, string $ip): array {
    $settings = new Settings();
    $userModel = new User();
    $always = (bool) $settings->getSetting('require_2fa', false);

    if (!$always && motherboard_2fa_device_is_trusted((int) $user['id'])) {
        return $gate;
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $userModel->store2FACode($user['id'], $code);

    $sent = false;
    try {
        $companyName = $settings->getSetting('company_name', APP_NAME);
        $companyName = !empty($companyName) ? $companyName : APP_NAME;
        $emailSender = new EmailSender($companyName);
        $sent = $emailSender->send2FACode($user['email'], $code, $user['username'] ?? 'User');
    } catch (Exception $e) {
        error_log('Failed to send 2FA email to ' . $user['email'] . ': ' . $e->getMessage());
    }

    $_SESSION['pending_2fa_user'] = $user['id'];
    $gate['proceed'] = false;
    $gate['requires_2fa'] = true;
    if ($sent) {
        $gate['message'] = $always ? t('auth.2fa_required') : t('auth.2fa_new_location');
    } else {
        $gate['message'] = t('auth.2fa_fallback');
    }
    return $gate;
});

Hooks::addFilter('auth.2fa.verify', function (array $result): array {
    $userModel = new User();
    $result['handled'] = true;
    if (!empty($result['user_id']) && $userModel->verify2FACode($result['user_id'], $result['code'] ?? '')) {
        $result['success'] = true;
        $result['error'] = '';
        motherboard_2fa_trust_device((int) $result['user_id']);
    } else {
        $result['success'] = false;
        $result['error'] = t('auth.invalid_code');
    }
    return $result;
});

Hooks::addFilter('module.settings.save.email-2fa', function (array $result, array $post, Settings $settings): array {
    $settings->setSetting('require_2fa', isset($post['require_2fa']) ? '1' : '0');
    return $result;
});
