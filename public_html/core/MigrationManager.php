<?php

class MigrationManager {
    private const LOCK_NAME = 'motherboard_schema_migration';

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
            self::beginPage(t('maintenance.in_progress_title'), t('maintenance.in_progress_help'));
            self::endPage(t('maintenance.try_again'), true);
        }

        ignore_user_abort(true);
        @set_time_limit(0);
        self::beginPage(t('maintenance.updating_title'), t('maintenance.updating_help'));

        try {
            Schema::ensure($database);
            Hooks::doAction('schema.migrate', $database);
            Schema::markCurrent($database);
            $database->releaseLock(self::LOCK_NAME);
            self::endPage(t('maintenance.complete'), false);
        } catch (Throwable $e) {
            error_log('Database migration failed: ' . $e->getMessage());
            $database->releaseLock(self::LOCK_NAME);
            self::endPage(t('maintenance.failed'), true);
        }
    }

    private static function beginPage(string $title, string $message): void {
        http_response_code(503);
        header('Retry-After: 5');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Accel-Buffering: no');

        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $stylesheet = htmlspecialchars(BASE_URL . '/assets/app.css', ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<meta http-equiv="refresh" content="5">';
        echo '<title>' . $safeTitle . '</title>';
        echo '<link rel="stylesheet" href="' . $stylesheet . '"></head>';
        echo '<body class="min-h-screen bg-gray-100 text-gray-900">';
        echo '<main class="min-h-screen flex items-center justify-center px-4 py-12">';
        echo '<div class="w-full max-w-2xl rounded-lg border border-blue-200 bg-blue-50 p-8">';
        echo '<h1 class="text-2xl font-bold text-blue-950">' . $safeTitle . '</h1>';
        echo '<p class="mt-4 text-blue-900">' . $safeMessage . '</p>';
        echo '<p id="migration-status" class="mt-3 text-sm text-blue-800">';
        echo htmlspecialchars(t('maintenance.keep_open'), ENT_QUOTES, 'UTF-8') . '</p>';
        @ob_flush();
        flush();
    }

    private static function endPage(string $status, bool $retry): never {
        $safeStatus = json_encode($status, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '</div></main>';
        echo '<script>document.getElementById("migration-status").textContent=' . $safeStatus . ';';
        if (!$retry) {
            echo 'setTimeout(function(){window.location.reload();},1000);';
        }
        echo '</script></body></html>';
        exit;
    }
}
