<?php

final class EdsWarrantyCardIssuer {
    private const FIELDS = [
        'service_name' => ['post' => 'eds_warranty_cards_issuer_service_name', 'column' => 'issuer_service_name', 'max' => 255, 'label' => 'eds_warranty_cards.issuer_service_name'],
        'legal_name' => ['post' => 'eds_warranty_cards_issuer_legal_name', 'column' => 'issuer_legal_name', 'max' => 255, 'label' => 'eds_warranty_cards.issuer_legal_name'],
        'registration_number' => ['post' => 'eds_warranty_cards_issuer_registration_number', 'column' => 'issuer_registration_number', 'max' => 100, 'label' => 'eds_warranty_cards.issuer_registration_number'],
        'representative' => ['post' => 'eds_warranty_cards_issuer_representative', 'column' => 'issuer_representative', 'max' => 255, 'label' => 'eds_warranty_cards.issuer_representative'],
        'address' => ['post' => 'eds_warranty_cards_issuer_address', 'column' => 'issuer_address', 'max' => 2000, 'label' => 'eds_warranty_cards.issuer_address'],
        'phone' => ['post' => 'eds_warranty_cards_issuer_phone', 'column' => 'issuer_phone', 'max' => 100, 'label' => 'eds_warranty_cards.issuer_phone'],
        'email' => ['post' => 'eds_warranty_cards_issuer_email', 'column' => 'issuer_email', 'max' => 254, 'label' => 'eds_warranty_cards.issuer_email'],
        'website' => ['post' => 'eds_warranty_cards_issuer_website', 'column' => 'issuer_website', 'max' => 2048, 'label' => 'eds_warranty_cards.issuer_website'],
        'logo_url' => ['post' => 'eds_warranty_cards_issuer_logo_url', 'column' => 'issuer_logo_url', 'max' => 2048, 'label' => 'eds_warranty_cards.issuer_logo_url'],
    ];

    private const FALLBACKS = [
        'service_name' => 'company_name',
        'address' => 'company_address',
        'phone' => 'company_phone',
        'email' => 'company_email',
        'website' => 'company_website',
        'logo_url' => 'company_logo_url',
    ];

    public static function blank(): array {
        return array_fill_keys(array_keys(self::FIELDS), '');
    }

    public static function postNames(): array {
        return array_column(self::FIELDS, 'post');
    }

    public static function fromPost(array $post): array {
        $issuer = [];
        foreach (self::FIELDS as $key => $config) {
            $value = $post[$config['post']] ?? '';
            if (!is_string($value) || preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException(t('eds_warranty_cards.issuer_invalid_text', ['field' => t($config['label'])]));
            }
            $value = $key === 'address'
                ? trim(str_replace(["\r\n", "\r"], "\n", $value))
                : trim($value);
            if (str_contains($value, '<') || str_contains($value, '>') || self::hasDisallowedControlCharacters($value, $key === 'address')) {
                throw new InvalidArgumentException(t('eds_warranty_cards.issuer_invalid_text', ['field' => t($config['label'])]));
            }
            if (self::characterCount($value) > $config['max']) {
                throw new InvalidArgumentException(t('eds_warranty_cards.issuer_too_long', [
                    'field' => t($config['label']),
                    'max' => $config['max'],
                ]));
            }
            $issuer[$key] = $value;
        }
        if ($issuer['email'] !== '' && filter_var($issuer['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(t('eds_warranty_cards.issuer_invalid_email'));
        }
        if ($issuer['website'] !== '' && !self::isAbsoluteHttpUrl($issuer['website'])) {
            throw new InvalidArgumentException(t('eds_warranty_cards.issuer_invalid_website'));
        }
        if ($issuer['logo_url'] !== '' && !self::isSafeLogoUrl($issuer['logo_url'])) {
            throw new InvalidArgumentException(t('eds_warranty_cards.issuer_invalid_logo_url'));
        }
        return $issuer;
    }

    public static function submittedForForm(array $post): array {
        $issuer = self::blank();
        foreach (self::FIELDS as $key => $config) {
            $issuer[$key] = is_string($post[$config['post']] ?? null) ? $post[$config['post']] : '';
        }
        return $issuer;
    }

    public static function read(PDO $pdo, bool $lock = false): array {
        $columns = array_column(self::FIELDS, 'column');
        $stmt = $pdo->query('SELECT ' . implode(',', $columns) . ' FROM eds_warranty_cards_settings WHERE id = 1' . ($lock ? ' FOR UPDATE' : ''));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $issuer = self::blank();
        if (!$row) {
            return $issuer;
        }
        foreach (self::FIELDS as $key => $config) {
            $issuer[$key] = is_string($row[$config['column']] ?? null) ? $row[$config['column']] : '';
        }
        return $issuer;
    }

    public static function write(PDO $pdo, array $issuer): void {
        $issuer = self::normalizeComplete($issuer);
        $columns = array_column(self::FIELDS, 'column');
        $assignments = implode(', ', array_map(static fn(string $column): string => $column . ' = VALUES(' . $column . ')', $columns));
        $sql = 'INSERT INTO eds_warranty_cards_settings (id, ' . implode(', ', $columns) . ', terms_html, updated_at) VALUES (1, '
            . implode(', ', array_fill(0, count($columns), '?')) . ", '', NOW()) ON DUPLICATE KEY UPDATE " . $assignments . ', updated_at = NOW()';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($issuer));
    }

    public static function effective(PDO $pdo, bool $lock = false): array {
        $issuer = self::read($pdo, $lock);
        $fallbackKeys = array_values(self::FALLBACKS);
        $placeholders = implode(',', array_fill(0, count($fallbackKeys), '?'));
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key IN (' . $placeholders . ')' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute($fallbackKeys);
        $general = array_fill_keys($fallbackKeys, '');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $setting) {
            $general[(string) $setting['setting_key']] = trim((string) ($setting['setting_value'] ?? ''));
        }
        foreach (self::FALLBACKS as $field => $settingKey) {
            if ($issuer[$field] === '') {
                $issuer[$field] = $general[$settingKey];
            }
        }
        $issuer['logo_url'] = self::safeLogoOrEmpty($issuer['logo_url']);
        return $issuer;
    }

    public static function fromPublicContent(array $public): array {
        if (isset($public['issuer']) && is_array($public['issuer'])) {
            $issuer = self::normalizeSnapshot($public['issuer']);
        } else {
            $company = is_array($public['company'] ?? null) ? $public['company'] : [];
            $issuer = self::blank();
            $issuer['service_name'] = self::snapshotText($company['company_name'] ?? '');
            $issuer['address'] = self::snapshotText($company['company_address'] ?? '');
            $issuer['phone'] = self::snapshotText($company['company_phone'] ?? '');
            $issuer['email'] = self::snapshotText($company['company_email'] ?? '');
            $issuer['website'] = self::snapshotText($company['company_website'] ?? '');
            $issuer['logo_url'] = self::snapshotText($company['company_logo_url'] ?? '');
        }
        $issuer['logo_url'] = self::safeLogoOrEmpty($issuer['logo_url']);
        return $issuer;
    }

    public static function showLegalName(array $issuer): bool {
        $service = trim((string) ($issuer['service_name'] ?? ''));
        $legal = trim((string) ($issuer['legal_name'] ?? ''));
        if ($legal === '') {
            return false;
        }
        $lower = static fn(string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return $lower($service) !== $lower($legal);
    }

    public static function isSafeLogoUrl(string $value): bool {
        if ($value === '' || str_starts_with($value, '//')) {
            return false;
        }
        if (str_starts_with($value, '/')) {
            return preg_match('/\A\/(?!\/)[^\x00-\x20\\\\]*\z/u', $value) === 1;
        }
        return self::isAbsoluteHttpUrl($value);
    }

    private static function isAbsoluteHttpUrl(string $value): bool {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($value);
        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            && (string) $parts['host'] !== '';
    }

    private static function safeLogoOrEmpty(string $value): string {
        return self::isSafeLogoUrl($value) ? $value : '';
    }

    private static function normalizeComplete(array $issuer): array {
        $normalized = [];
        foreach (self::FIELDS as $key => $_config) {
            if (!array_key_exists($key, $issuer) || !is_string($issuer[$key])) {
                throw new InvalidArgumentException(t('eds_warranty_cards.issuer_invalid_text', ['field' => $key]));
            }
            $normalized[$key] = $issuer[$key];
        }
        return $normalized;
    }

    private static function normalizeSnapshot(array $snapshot): array {
        $issuer = self::blank();
        foreach (self::FIELDS as $key => $_config) {
            $issuer[$key] = self::snapshotText($snapshot[$key] ?? '');
        }
        return $issuer;
    }

    private static function snapshotText(mixed $value): string {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function characterCount(string $value): int {
        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? PHP_INT_MAX : $count;
    }

    private static function hasDisallowedControlCharacters(string $value, bool $allowNewlines): bool {
        $pattern = $allowNewlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        return preg_match($pattern, $value) === 1;
    }
}
