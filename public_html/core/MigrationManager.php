<?php

class MigrationManager {
    private const LOCK_NAME = 'motherboard_schema_migration';
    private const MIN_DISPLAY_MS = 3000;

    public static function handleIfNeeded(Database $database): void {
        $needsMigration = Hooks::applyFilters(
            'schema.needs_migration',
            Schema::needsMigration($database),
            $database
        );
        if (!$needsMigration) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $lockAcquired = $database->acquireLock(self::LOCK_NAME, 0);
        if (!$lockAcquired) {
            // Another request is migrating; keep showing the spinner and let the meta refresh retry.
            self::beginPage();
            self::endPage(null);
        }

        ignore_user_abort(true);
        @set_time_limit(0);
        self::beginPage();

        try {
            Schema::ensure($database);
            Hooks::doAction('schema.migrate', $database);
            Schema::markCurrent($database);
            $database->releaseLock(self::LOCK_NAME);
            self::endPage(null, true);
        } catch (Throwable $e) {
            error_log('Database migration failed: ' . $e->getMessage());
            $database->releaseLock(self::LOCK_NAME);
            self::endPage(t('maintenance.failed'));
        }
    }

    private static function beginPage(): void {
        http_response_code(503);
        header('Retry-After: 5');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Accel-Buffering: no');

        $safeTitle = htmlspecialchars(t('maintenance.updating_title'), ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars(t('maintenance.please_wait'), ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<meta http-equiv="refresh" content="5">';
        echo '<title>' . $safeTitle . '</title>';
        echo '<script>window.migrationStartedAt=Date.now();</script>';
        echo '<style>';
        echo 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;';
        echo 'background:#f3f4f6;color:#1f2937;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;}';
        echo '.migration{display:flex;flex-direction:column;align-items:center;gap:1.5rem;padding:1rem;text-align:center;}';
        echo '.migration-spinner{width:3rem;height:3rem;border:4px solid #bfdbfe;border-top-color:#2563eb;';
        echo 'border-radius:50%;animation:migration-spin .8s linear infinite;}';
        echo '@keyframes migration-spin{to{transform:rotate(360deg);}}';
        echo '.migration-message{margin:0;font-size:1.125rem;}';
        echo '.migration-failed .migration-spinner{display:none;}';
        echo '.migration-failed .migration-message{color:#b91c1c;max-width:36rem;}';
        echo '</style></head><body>';
        echo '<main id="migration" class="migration">';
        echo '<div class="migration-spinner" role="status" aria-label="' . $safeMessage . '"></div>';
        echo '<p id="migration-message" class="migration-message">' . $safeMessage . '</p>';
        @ob_flush();
        flush();
    }

    private static function endPage(?string $failure, bool $reload = false): never {
        echo '</main><script>';
        if ($failure !== null) {
            $safeFailure = json_encode($failure, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            echo 'document.getElementById("migration").classList.add("migration-failed");';
            echo 'document.getElementById("migration-message").textContent=' . $safeFailure . ';';
        }
        if ($reload) {
            // Keep the screen up for a minimum time so it can be read even when the update is instant.
            echo 'setTimeout(function(){window.location.reload();},';
            echo 'Math.max(0,' . self::MIN_DISPLAY_MS . '-(Date.now()-window.migrationStartedAt)));';
        }
        echo '</script></body></html>';
        exit;
    }
}
