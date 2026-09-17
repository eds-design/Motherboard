<?php
require_once ROOT_PATH . '/core/Controller.php';
require_once ROOT_PATH . '/models/User.php';
require_once dirname(__DIR__) . '/lib.php';

class CustomerEmailController extends Controller {
    private const SETTINGS_PATH = '/module-manager/customer-email/settings';

    /**
     * Sends the chosen email to the signed-in admin with placeholder work order details, so
     * the template can be checked without touching a real customer.
     */
    public function preview() {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect(self::SETTINGS_PATH);
        }

        try {
            $this->validateCSRF();

            $event = (string) ($_POST['preview_event'] ?? '');
            if (!motherboard_customer_email_is_event($event)) {
                throw new Exception(t('customer_email.preview_invalid'));
            }

            $user = (new User())->findById((int) $_SESSION['user_id']);
            $to = trim((string) ($user['email'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new Exception(t('customer_email.preview_no_address'));
            }

            $sent = motherboard_customer_email_send($event, $to, [
                'number' => '0',
                'name' => 'Test',
                'status' => $this->previewStatus($event),
            ]);
            if (!$sent) {
                throw new Exception(t('customer_email.preview_failed', ['email' => $to]));
            }

            $this->logger->log('customer_email_preview', 'Sent ' . $event . ' customer email preview to ' . $to, $_SESSION['user_id']);
            $this->redirectWithFlash(self::SETTINGS_PATH, t('customer_email.preview_sent', [
                'type' => t('customer_email.' . $event . '.name'),
                'email' => $to,
            ]));
        } catch (Exception $e) {
            $this->redirectWithFlash(self::SETTINGS_PATH, $e->getMessage(), 'error');
        }
    }

    private function previewStatus(string $event): string {
        return match ($event) {
            'initial_update' => 'In Progress',
            'completed' => 'Closed',
            'picked_up' => 'Picked Up',
            default => 'Open',
        };
    }
}
