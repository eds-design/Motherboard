<?php
require_once ROOT_PATH . '/core/Controller.php';
require_once ROOT_PATH . '/models/WorkOrder.php';
require_once dirname(__DIR__) . '/models/Draft.php';
require_once dirname(__DIR__) . '/models/IssuedCard.php';

final class EdsWarrantyCardDraftController extends Controller {
    public function edit($id): void {
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
        $workOrder = (new WorkOrder($this->db))->getWorkOrderById((int) $id);
        if (!$workOrder) {
            $this->redirect('/404');
        }
        header('Cache-Control: no-store');
        $issued = (new EdsWarrantyCardIssued($this->db->connect()))->get((int) $id);
        if ($issued) {
            if ($method === 'POST') {
                try {
                    $this->validateCSRF();
                } catch (Throwable) {
                    $this->redirect('/403');
                }
            }
            $this->redirect('/work-orders/view/' . $id . '/eds-warranty-card');
        }
        $draftModel = new EdsWarrantyCardDraft($this->db->connect());
        $canEdit = in_array($_SESSION['user_group'], ['Admin', 'Technician'], true);
        $submitted = $method === 'POST' ? EdsWarrantyCardDraftForm::read($_POST) : null;
        $draftAction = 'save';
        if ($submitted !== null) {
            $requestedAction = $_POST['draft_action'] ?? 'save';
            if (!is_string($requestedAction) || !in_array($requestedAction, ['save', 'save_and_review'], true)) {
                $submitted['invalid'] = true;
            } else {
                $draftAction = $requestedAction;
            }
        }
        $error = '';
        if ($submitted !== null) {
            try {
                $this->validateCSRF();
                $draftModel->save((int) $id, $submitted);
                $this->logger->log('eds_warranty_card_draft_saved', 'Warranty card draft saved for work order #' . $id, $_SESSION['user_id']);
                $destination = $draftAction === 'save_and_review'
                    ? '/work-orders/view/' . $id . '/eds-warranty-card'
                    : '/work-orders/view/' . $id . '/eds-warranty-card/draft';
                $this->redirectWithFlash($destination, t('eds_warranty_cards.draft_saved'));
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('EDS warranty card draft save failed: ' . $exception->getMessage());
                $error = t('eds_warranty_cards.draft_save_failed');
            }
        }
        $saved = $draftModel->get((int) $id);
        if (!$canEdit && !$saved) {
            $this->redirect('/403');
        }
        $lines = $draftModel->lines((int) $id);
        $savedManualParts = $saved['content']['manual_parts'] ?? [];
        $savedWorkItems = $saved['content']['work_items'] ?? [];
        if (!is_array($savedManualParts) || count($savedManualParts) > EdsWarrantyCardDraftLimits::MANUAL_ROWS) {
            $savedManualParts = [];
            if ($error === '') {
                $error = t('eds_warranty_cards.too_many_parts');
            }
        }
        if (!is_array($savedWorkItems) || count($savedWorkItems) > EdsWarrantyCardDraftLimits::WORK_ITEMS) {
            $savedWorkItems = [];
            if ($error === '') {
                $error = t('eds_warranty_cards.too_many_work_items');
            }
        }
        $form = $submitted ?? [
            'revision' => $saved['revision'] ?? '0',
            'inventory_token' => EdsWarrantyCardDraftForm::token($lines),
            'units' => $saved['content']['units'] ?? [],
            'manual_parts' => $savedManualParts,
            'work_items' => $savedWorkItems,
        ];
        // The refreshed list is now shown to the user. A second save acknowledges
        // inventory changes, but a draft revision conflict is NEVER rebased here.
        $form['inventory_token'] = EdsWarrantyCardDraftForm::token($lines);
        try {
            $rows = EdsWarrantyCardDraftForm::rows($lines, $saved['content']['units'] ?? [], $submitted['units'] ?? null);
        } catch (InvalidArgumentException $exception) {
            $rows = [];
            if ($error === '') {
                $error = $exception->getMessage();
            }
        }
        $this->viewPath(dirname(__DIR__) . '/views/draft.php', [
            'workOrder' => $workOrder, 'form' => $form, 'canEdit' => $canEdit,
            'rows' => $rows,
            'manualParts' => $submitted['manual_parts'] ?? $savedManualParts,
            'workItems' => $submitted['work_items'] ?? $savedWorkItems,
            'hasSavedDraft' => $saved !== null,
            'error' => $error, 'csrf_token' => $this->generateCSRF(),
            'conflict' => $submitted !== null && $submitted['revision'] !== ($saved['revision'] ?? '0'),
            'inventoryChanged' => $submitted !== null && !hash_equals(EdsWarrantyCardDraftForm::token($lines), $submitted['inventory_token']),
        ], 'eds-warranty-cards/draft');
    }

    public function delete($id): void {
        $this->requireTechnician();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            return;
        }
        if (!is_string($id) || !preg_match('/\A[1-9][0-9]*\z/', $id) || strlen($id) > 10 || (int) $id > 2147483647) {
            $this->redirect('/404');
        }
        $workOrder = (new WorkOrder($this->db))->getWorkOrderById((int) $id);
        if (!$workOrder) {
            $this->redirect('/404');
        }
        try {
            $this->validateCSRF();
        } catch (Throwable) {
            $this->redirect('/403');
        }
        if (($_POST['confirm_delete'] ?? null) !== '1' || !is_string($_POST['revision'] ?? null)) {
            $this->redirectWithFlash('/work-orders/view/' . $id . '/eds-warranty-card/draft', t('eds_warranty_cards.draft_delete_confirmation_required'), 'error');
        }
        try {
            (new EdsWarrantyCardDraft($this->db->connect()))->delete((int) $id, $_POST['revision']);
            $this->logger->log('eds_warranty_card_draft_deleted', 'Warranty card draft deleted for work order #' . $id, $_SESSION['user_id']);
            $this->redirectWithFlash('/work-orders/view/' . $id, t('eds_warranty_cards.draft_deleted'));
        } catch (InvalidArgumentException $exception) {
            $destination = $exception->getMessage() === t('eds_warranty_cards.draft_delete_issued')
                ? '/work-orders/view/' . $id . '/eds-warranty-card'
                : ($exception->getMessage() === t('eds_warranty_cards.draft_delete_missing')
                    ? '/work-orders/view/' . $id
                    : '/work-orders/view/' . $id . '/eds-warranty-card/draft');
            $this->redirectWithFlash($destination, $exception->getMessage(), 'error');
        } catch (Throwable $exception) {
            error_log('EDS warranty card draft delete failed: ' . $exception->getMessage());
            $this->redirectWithFlash('/work-orders/view/' . $id . '/eds-warranty-card/draft', t('eds_warranty_cards.draft_delete_failed'), 'error');
        }
    }
}
