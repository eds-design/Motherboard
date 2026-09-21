<?php
require_once dirname(__DIR__) . '/schema.php';
require_once dirname(__DIR__) . '/lib.php';
require_once __DIR__ . '/DraftLimits.php';

final class EdsWarrantyCardDraftForm {
    public static function read(array $post): array {
        $invalid = false;
        $text = static function (mixed $value) use (&$invalid): string {
            if (!is_string($value)) {
                $invalid = true;
                return '';
            }
            return $value;
        };
        $checkbox = static function (mixed $value) use (&$invalid): bool {
            if ($value === null) {
                return false;
            }
            if ($value !== '1') {
                $invalid = true;
            }
            return $value === '1';
        };
        $units = $post['units'] ?? [];
        if (!is_array($units)) {
            $invalid = true;
            $units = [];
        }
        $manualParts = $post['manual_parts'] ?? [];
        if (!is_array($manualParts)) {
            $invalid = true;
            $manualParts = [];
        }
        $workItems = $post['work_items'] ?? [];
        if (!is_array($workItems)) {
            $invalid = true;
            $workItems = [];
        }
        $countError = '';
        if (count($units) > EdsWarrantyCardDraftLimits::INVENTORY_UNITS || count($manualParts) > EdsWarrantyCardDraftLimits::MANUAL_ROWS) {
            $countError = t('eds_warranty_cards.too_many_parts');
            $units = [];
            $manualParts = [];
        }
        if (count($workItems) > EdsWarrantyCardDraftLimits::WORK_ITEMS) {
            if ($countError === '') {
                $countError = t('eds_warranty_cards.too_many_work_items');
            }
            $workItems = [];
        }
        $form = [
            'revision' => $text($post['revision'] ?? ''),
            'inventory_token' => $text($post['inventory_token'] ?? ''),
            'units' => [],
            'manual_parts' => [],
            'work_items' => [],
            'validation_error' => $countError,
        ];
        foreach ($units as $key => $unit) {
            if (!is_array($unit)) {
                $invalid = true;
                $unit = [];
            }
            $form['units'][(string) $key] = ['selected' => $checkbox($unit['selected'] ?? null)];
            foreach (['serial_number', 'months', 'supplier', 'supplier_card'] as $field) {
                $form['units'][(string) $key][$field] = $text($unit[$field] ?? '');
            }
        }
        foreach ($manualParts as $key => $part) {
            if (!is_string($key) || !preg_match('/\Am_[a-f0-9]{32}\z/', $key) || !is_array($part)) {
                $invalid = true;
                continue;
            }
            $form['manual_parts'][$key] = [];
            foreach (['name', 'serial_number', 'months', 'supplier', 'supplier_card'] as $field) {
                $form['manual_parts'][$key][$field] = $text($part[$field] ?? '');
            }
        }
        foreach ($workItems as $key => $item) {
            if (!is_string($key) || !preg_match('/\Aw_[a-f0-9]{32}\z/', $key) || !is_array($item)) {
                $invalid = true;
                continue;
            }
            $form['work_items'][$key] = [
                'description' => $text($item['description'] ?? ''),
                'months' => $text($item['months'] ?? ''),
            ];
        }
        $form['invalid'] = $invalid || $countError !== '' || ($post['form_complete'] ?? null) !== '1';
        return $form;
    }

    public static function blank(): array {
        return ['selected' => false, 'serial_number' => '', 'months' => '', 'supplier' => '', 'supplier_card' => ''];
    }

    public static function blankManual(): array {
        return ['name' => '', 'serial_number' => '', 'months' => '', 'supplier' => '', 'supplier_card' => ''];
    }

    public static function blankWorkItem(): array {
        return ['description' => '', 'months' => ''];
    }

    public static function manualIsEmpty(array $part): bool {
        foreach (self::blankManual() as $field => $_) {
            if (($part[$field] ?? '') !== '') {
                return false;
            }
        }
        return true;
    }

    public static function workItemIsEmpty(array $item): bool {
        return ($item['description'] ?? '') === '' && ($item['months'] ?? '') === '';
    }

    public static function normalizeContent(array $content): array {
        $workItems = [];
        if (isset($content['work_items']) && is_array($content['work_items'])) {
            if (count($content['work_items']) > EdsWarrantyCardDraftLimits::WORK_ITEMS) {
                // Keep oversized legacy/external data available to issuance validation
                // without walking it here. The editor renders a safe empty replacement.
                $workItems = $content['work_items'];
            } else {
                foreach ($content['work_items'] as $key => $item) {
                    if (!is_string($key) || !preg_match('/\Aw_[a-f0-9]{32}\z/', $key) || !is_array($item)) {
                        continue;
                    }
                    $workItems[$key] = [
                        'description' => is_scalar($item['description'] ?? '') ? (string) ($item['description'] ?? '') : '',
                        'months' => is_scalar($item['months'] ?? '') ? (string) ($item['months'] ?? '') : '',
                    ];
                }
            }
        } elseif (!empty($content['work_enabled'])) {
            $description = is_scalar($content['work_description'] ?? '') ? (string) ($content['work_description'] ?? '') : '';
            $months = is_scalar($content['work_months'] ?? '') ? (string) ($content['work_months'] ?? '') : '';
            if ($description !== '' || $months !== '') {
                $workItems['w_00000000000000000000000000000001'] = [
                    'description' => $description,
                    'months' => $months,
                ];
            }
        }
        $content['work_items'] = $workItems;
        unset($content['work_enabled'], $content['work_description'], $content['work_months']);
        return $content;
    }

    public static function token(array $lines): string {
        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function rows(array $lines, array $savedUnits = [], ?array $submittedUnits = null): array {
        EdsWarrantyCardDraftLimits::inventoryUnitCount($lines);
        if ($submittedUnits !== null && count($submittedUnits) > EdsWarrantyCardDraftLimits::INVENTORY_UNITS) {
            throw new InvalidArgumentException(t('eds_warranty_cards.too_many_parts'));
        }
        if (count($savedUnits) > EdsWarrantyCardDraftLimits::INVENTORY_UNITS) {
            if ($submittedUnits === null) {
                throw new InvalidArgumentException(t('eds_warranty_cards.too_many_parts'));
            }
            // A bounded empty submission lets an old invalid draft be corrected.
            $savedUnits = [];
        }
        $rows = [];
        foreach ($lines as $line) {
            for ($unit = 1; $unit <= (int) $line['quantity']; $unit++) {
                $key = $line['id'] . '_' . $unit;
                $rows[$key] = [
                    'name' => $line['product_name'], 'unit' => $unit,
                    'quantity' => (int) $line['quantity'], 'available' => true, 'saved' => isset($savedUnits[$key]),
                    'fields' => $submittedUnits === null
                        ? ($savedUnits[$key] ?? self::blank())
                        : ($submittedUnits[$key] ?? self::blank()),
                ];
            }
        }
        // Keep unavailable saved/submitted units visible until the user deselects them.
        foreach (array_unique(array_merge(array_keys($savedUnits), array_keys($submittedUnits ?? []))) as $key) {
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'name' => $savedUnits[$key]['name'] ?? t('eds_warranty_cards.unavailable_unit'),
                    'unit' => null, 'quantity' => null, 'available' => false, 'saved' => isset($savedUnits[$key]),
                    'fields' => $submittedUnits === null
                        ? $savedUnits[$key]
                        : ($submittedUnits[$key] ?? self::blank()),
                ];
            }
        }
        return $rows;
    }
}

final class EdsWarrantyCardDraft {
    public function __construct(private PDO $pdo) {}

    public function get(int $workOrderId, bool $lock = false): ?array {
        $stmt = $this->pdo->prepare('SELECT revision, content FROM eds_warranty_cards_drafts WHERE work_order_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$workOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $content = json_decode($row['content'], true, 512, JSON_THROW_ON_ERROR);
        return ['revision' => (string) $row['revision'], 'content' => EdsWarrantyCardDraftForm::normalizeContent($content)];
    }

    public function lines(int $workOrderId, bool $lock = false): array {
        if (!eds_warranty_cards_table_exists($this->pdo, 'work_order_products')) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT id, product_name, quantity FROM work_order_products WHERE work_order_id = ? ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
            $stmt->execute([$workOrderId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {
            // Inventory may be removed while this optional module remains active.
            if (in_array($error->getCode(), ['42S02', '42S22'], true)) {
                return [];
            }
            throw $error;
        }
    }

    public function save(int $workOrderId, array $form): void {
        if (!empty($form['validation_error'])) {
            throw new InvalidArgumentException($form['validation_error']);
        }
        if ($form['invalid'] || !preg_match('/\A(?:0|[1-9][0-9]*)\z/', $form['revision'])) {
            throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
        }
        if (!is_array($form['units'] ?? null) || !is_array($form['manual_parts'] ?? null) || !is_array($form['work_items'] ?? null)) {
            throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Draft save must own its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            // Keep the same lock order as issuance: issued row/gap, parent, draft.
            if (eds_warranty_cards_table_exists($this->pdo, 'eds_warranty_cards_issued')) {
                $issued = $this->pdo->prepare('SELECT work_order_id FROM eds_warranty_cards_issued WHERE work_order_id = ? FOR UPDATE');
                $issued->execute([$workOrderId]);
                if ($issued->fetchColumn() !== false) {
                    throw new InvalidArgumentException(t('eds_warranty_cards.draft_locked_issued'));
                }
            }
            // Lock the parent even before a draft exists, serializing first saves and deletion.
            $stmt = $this->pdo->prepare('SELECT id FROM work_orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$workOrderId]);
            if ($stmt->fetchColumn() === false) {
                throw new InvalidArgumentException(t('eds_warranty_cards.task_missing'));
            }
            $saved = $this->get($workOrderId, true);
            if ($form['revision'] !== ($saved['revision'] ?? '0')) {
                throw new InvalidArgumentException(t('eds_warranty_cards.edit_conflict'));
            }
            $lines = $this->lines($workOrderId, true);
            if (!hash_equals(EdsWarrantyCardDraftForm::token($lines), $form['inventory_token'])) {
                throw new InvalidArgumentException(t('eds_warranty_cards.inventory_conflict'));
            }
            EdsWarrantyCardDraftLimits::assertRawCounts($form['units'], $form['manual_parts'], $form['work_items']);
            $rows = EdsWarrantyCardDraftForm::rows($lines, $saved['content']['units'] ?? [], $form['units']);
            $units = [];
            $selectedInventory = 0;
            foreach ($rows as $key => $row) {
                if (!is_array($row['fields'])) {
                    throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
                }
                if (!$row['available'] && !$row['saved']) {
                    throw new InvalidArgumentException(t('eds_warranty_cards.unavailable_selected'));
                }
                $fields = ['selected' => !empty($row['fields']['selected'])]
                    + EdsWarrantyCardDraftLimits::normalizePartFields($row['fields'], false);
                if ($fields['selected']) {
                    $selectedInventory++;
                }
                if ($row['available'] || $row['saved']) {
                    $units[$key] = [
                        'name' => EdsWarrantyCardDraftLimits::normalizeField('inventory_name', $row['name']),
                        'source' => 'inventory',
                    ] + $fields;
                }
            }
            $manualParts = [];
            $nonEmptyManual = 0;
            foreach (($form['manual_parts'] ?? []) as $key => $part) {
                if (!is_array($part)) {
                    throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
                }
                $normalized = ['source' => 'manual'] + EdsWarrantyCardDraftLimits::normalizePartFields($part, true);
                $manualParts[$key] = $normalized;
                if (!EdsWarrantyCardDraftForm::manualIsEmpty($normalized)) {
                    $nonEmptyManual++;
                }
            }
            $workItems = [];
            foreach (($form['work_items'] ?? []) as $key => $item) {
                if (!is_array($item)) {
                    throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
                }
                $workItems[$key] = EdsWarrantyCardDraftLimits::normalizeWorkItem($item);
            }
            EdsWarrantyCardDraftLimits::assertPartCount($selectedInventory, $nonEmptyManual);
            $content = EdsWarrantyCardDraftLimits::encodeCanonical([
                'units' => $units,
                'manual_parts' => $manualParts,
                'work_items' => $workItems,
            ]);
            if ($saved) {
                $stmt = $this->pdo->prepare('UPDATE eds_warranty_cards_drafts SET revision = revision + 1, content = ?, updated_at = NOW() WHERE work_order_id = ?');
            } else {
                $stmt = $this->pdo->prepare('INSERT INTO eds_warranty_cards_drafts (content, work_order_id, revision, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())');
            }
            $stmt->execute([$content, $workOrderId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function delete(int $workOrderId, string $expectedRevision): void {
        if (!preg_match('/\A[1-9][0-9]*\z/', $expectedRevision)) {
            throw new InvalidArgumentException(t('eds_warranty_cards.draft_delete_request_invalid'));
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Draft deletion must own its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            // Issuance locks the counter first. Take the same lock without changing
            // its value so deletion and issuance cannot deadlock or cross each other.
            $counter = $this->pdo->query('SELECT id FROM eds_warranty_cards_counter WHERE id = 1 FOR UPDATE');
            if ($counter->fetchColumn() === false) {
                throw new RuntimeException('Warranty card counter is not initialized.');
            }
            $issued = $this->pdo->prepare('SELECT work_order_id FROM eds_warranty_cards_issued WHERE work_order_id = ? FOR UPDATE');
            $issued->execute([$workOrderId]);
            if ($issued->fetchColumn() !== false) {
                throw new InvalidArgumentException(t('eds_warranty_cards.draft_delete_issued'));
            }
            $parent = $this->pdo->prepare('SELECT id FROM work_orders WHERE id = ? FOR UPDATE');
            $parent->execute([$workOrderId]);
            if ($parent->fetchColumn() === false) {
                throw new InvalidArgumentException(t('eds_warranty_cards.task_missing'));
            }
            $saved = $this->get($workOrderId, true);
            if (!$saved) {
                throw new InvalidArgumentException(t('eds_warranty_cards.draft_delete_missing'));
            }
            if ($saved['revision'] !== $expectedRevision) {
                throw new InvalidArgumentException(t('eds_warranty_cards.draft_delete_conflict'));
            }
            $stmt = $this->pdo->prepare('DELETE FROM eds_warranty_cards_drafts WHERE work_order_id = ? AND revision = ?');
            $stmt->execute([$workOrderId, $expectedRevision]);
            if ($stmt->rowCount() !== 1) {
                throw new InvalidArgumentException(t('eds_warranty_cards.draft_delete_conflict'));
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

}
