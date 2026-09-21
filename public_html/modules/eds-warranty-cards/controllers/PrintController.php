<?php
require_once ROOT_PATH . '/core/Controller.php';
require_once dirname(__DIR__) . '/models/IssuedCard.php';

final class EdsWarrantyCardPrintController extends Controller {
    public function show($id): void {
        $this->requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            header('Allow: GET');
            return;
        }
        if (!is_string($id) || !preg_match('/\A[1-9][0-9]*\z/', $id) || strlen($id) > 10 || (int) $id > 2147483647) {
            $this->redirect('/404');
        }
        $clientCard = (new EdsWarrantyCardIssued($this->db->connect()))->clientContent((int) $id);
        if (!$clientCard) {
            $this->redirect('/404');
        }
        applyPrintLanguage($this->settingsModel);
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow');
        $this->viewPath(dirname(__DIR__) . '/views/print.php', [
            // Intentionally pass only the public projection to this template.
            'clientCard' => $clientCard,
        ], 'eds-warranty-cards/print');
    }
}
