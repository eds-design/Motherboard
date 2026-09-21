<?php
require_once ROOT_PATH . '/core/Controller.php';
require_once dirname(__DIR__) . '/models/IssuedCard.php';

final class EdsWarrantyCardController extends Controller {
    public function show($id): void {
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'POST') {
            $this->requireTechnician();
        } else {
            $this->requireAuth();
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            http_response_code(405);
            header('Allow: GET, POST');
            return;
        }
        if (!is_string($id) || !preg_match('/\A[1-9][0-9]*\z/', $id) || strlen($id) > 10 || (int) $id > 2147483647) {
            $this->redirect('/404');
        }
        header('Cache-Control: no-store');
        $model = new EdsWarrantyCardIssued($this->db->connect());
        $canIssue = in_array($_SESSION['user_group'], ['Admin', 'Technician'], true);
        $error = '';
        $card = $model->get((int) $id);
        if ($method === 'POST') {
            try {
                $this->validateCSRF();
                if (($_POST['confirm_issue'] ?? null) !== '1') {
                    throw new InvalidArgumentException(t('eds_warranty_cards.issue_confirmation_required'));
                }
                $result = $model->issue(
                    (int) $id,
                    is_string($_POST['revision'] ?? null) ? $_POST['revision'] : '',
                    is_string($_POST['preview_token'] ?? null) ? $_POST['preview_token'] : '',
                    (int) $_SESSION['user_id']
                );
                if ($result['created']) {
                    $this->logger->log('eds_warranty_card_issued', 'Warranty card ' . $result['card']['card_number'] . ' issued for work order #' . $id, $_SESSION['user_id']);
                    $this->redirectWithFlash('/work-orders/view/' . $id . '/eds-warranty-card', t('eds_warranty_cards.issue_success'));
                }
                $this->redirectWithFlash('/work-orders/view/' . $id . '/eds-warranty-card', t('eds_warranty_cards.already_issued'));
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('EDS warranty card issuance failed: ' . $exception->getMessage());
                $error = t('eds_warranty_cards.issue_failed');
            }
            $card = $model->get((int) $id);
        }
        $preview = null;
        $validationError = '';
        if (!$card) {
            try {
                $preview = $model->preview((int) $id);
                $model->validatePreview($preview['public']);
            } catch (InvalidArgumentException $exception) {
                $validationError = $exception->getMessage();
                if ($preview === null) {
                    $this->redirectWithFlash('/work-orders/view/' . $id, $validationError, 'error');
                }
            }
        }
        $publicContent = $card ? $card['public'] : $preview['public'];
        $internalContent = $canIssue ? ($card ? $card['internal'] : $preview['internal']) : [];
        $this->viewPath(dirname(__DIR__) . '/views/card.php', [
            'card' => $card, 'preview' => $preview, 'publicContent' => $publicContent,
            'internalContent' => $internalContent, 'canIssue' => $canIssue,
            'validationError' => $validationError, 'error' => $error,
            'csrf_token' => $this->generateCSRF(), 'workOrderId' => (int) $id,
        ], 'eds-warranty-cards/card');
    }
}
