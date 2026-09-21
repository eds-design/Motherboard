<?php
require_once dirname(__DIR__) . '/models/Draft.php';
require_once dirname(__DIR__) . '/models/NumberCounter.php';
require_once dirname(__DIR__) . '/models/Terms.php';
require_once dirname(__DIR__) . '/models/Issuer.php';

final class EdsWarrantyCardIssued {
    public function __construct(private PDO $pdo) {}

    public function get(int $workOrderId): ?array {
        $stmt = $this->pdo->prepare('SELECT work_order_id, card_number, issued_at, public_content, internal_content FROM eds_warranty_cards_issued WHERE work_order_id = ?');
        $stmt->execute([$workOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decode($row) : null;
    }

    public function clientContent(int $workOrderId): ?array {
        $stmt = $this->pdo->prepare('SELECT card_number, issued_at, public_content FROM eds_warranty_cards_issued WHERE work_order_id = ?');
        $stmt->execute([$workOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'card_number' => (string) $row['card_number'],
            'issued_at' => (string) $row['issued_at'],
            'content' => json_decode($row['public_content'], true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    public static function workWarranties(array $public): array {
        if (isset($public['work_warranties']) && is_array($public['work_warranties'])) {
            $items = $public['work_warranties'];
        } elseif (isset($public['work_warranty']) && is_array($public['work_warranty'])) {
            $items = [$public['work_warranty']];
        } else {
            return [];
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $result[] = [
                'description' => is_scalar($item['description'] ?? '') ? (string) ($item['description'] ?? '') : '',
                'months' => is_scalar($item['months'] ?? '') ? (string) ($item['months'] ?? '') : '',
            ];
        }
        return $result;
    }

    public function preview(int $workOrderId): array {
        $draftModel = new EdsWarrantyCardDraft($this->pdo);
        $draft = $draftModel->get($workOrderId);
        if (!$draft) {
            throw new InvalidArgumentException(t('eds_warranty_cards.no_draft_to_issue'));
        }
        [$public, $internal] = $this->buildSnapshot($workOrderId, $draft['content'], false);
        return [
            'revision' => $draft['revision'],
            'public' => $public,
            'internal' => $internal,
            'token' => $this->previewToken($draft['revision'], $public, $internal),
        ];
    }

    public function validatePreview(array $public): void {
        $parts = $public['parts'] ?? [];
        $workWarranties = self::workWarranties($public);
        if ($parts === [] && $workWarranties === []) {
            throw new InvalidArgumentException(t('eds_warranty_cards.issue_empty'));
        }
        foreach ($parts as $part) {
            if (trim((string) ($part['name'] ?? '')) === '') {
                throw new InvalidArgumentException(t('eds_warranty_cards.issue_part_name_required'));
            }
            $this->requirePositiveMonths((string) ($part['months'] ?? ''));
        }
        foreach ($workWarranties as $work) {
            if (trim($work['description']) === '') {
                throw new InvalidArgumentException(t('eds_warranty_cards.issue_work_item_description_required'));
            }
            $this->requirePositiveMonths($work['months'], 'eds_warranty_cards.issue_work_item_months_required');
        }
    }

    /** Returns the immutable card and whether this request created it. */
    public function issue(int $workOrderId, string $expectedRevision, string $expectedToken, int $userId, ?callable $afterInsert = null): array {
        if (!preg_match('/\A[1-9][0-9]*\z/', $expectedRevision) || !preg_match('/\A[a-f0-9]{64}\z/', $expectedToken)) {
            throw new InvalidArgumentException(t('eds_warranty_cards.issue_request_invalid'));
        }
        $counter = new EdsWarrantyCardCounter($this->pdo);
        $number = $counter->issue(function (string $currentNumber, PDO $pdo) use ($workOrderId, $expectedRevision, $expectedToken, $userId, $afterInsert): bool {
            $existing = $pdo->prepare('SELECT work_order_id FROM eds_warranty_cards_issued WHERE work_order_id = ? FOR UPDATE');
            $existing->execute([$workOrderId]);
            if ($existing->fetchColumn() !== false) {
                return false;
            }
            $parent = $pdo->prepare('SELECT id FROM work_orders WHERE id = ? FOR UPDATE');
            $parent->execute([$workOrderId]);
            if ($parent->fetchColumn() === false) {
                throw new InvalidArgumentException(t('eds_warranty_cards.task_missing'));
            }
            $draftModel = new EdsWarrantyCardDraft($pdo);
            $draft = $draftModel->get($workOrderId, true);
            if (!$draft) {
                throw new InvalidArgumentException(t('eds_warranty_cards.no_draft_to_issue'));
            }
            if ($draft['revision'] !== $expectedRevision) {
                throw new InvalidArgumentException(t('eds_warranty_cards.issue_preview_changed'));
            }
            [$public, $internal] = $this->buildSnapshot($workOrderId, $draft['content'], true);
            if (!hash_equals($this->previewToken($draft['revision'], $public, $internal), $expectedToken)) {
                throw new InvalidArgumentException(t('eds_warranty_cards.issue_preview_changed'));
            }
            $this->validatePreview($public);
            $issuedAt = (string) $pdo->query('SELECT NOW()')->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO eds_warranty_cards_issued (work_order_id, card_number, number_key, issued_at, issued_by, public_content, internal_content) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $workOrderId, $currentNumber, EdsWarrantyCardNumber::identityKey($currentNumber),
                $issuedAt, $userId,
                json_encode($public, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                json_encode($internal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            if ($afterInsert !== null) {
                $afterInsert($currentNumber, $pdo);
            }
            return true;
        });
        $card = $this->get($workOrderId);
        if (!$card) {
            throw new RuntimeException(t('eds_warranty_cards.issue_failed'));
        }
        return ['card' => $card, 'created' => $number !== null];
    }

    private function buildSnapshot(int $workOrderId, array $draft, bool $lock): array {
        $draft = EdsWarrantyCardDraftLimits::normalizeStoredForIssuance($draft);
        $sql = "SELECT wo.id,
                       c.name AS customer_name, c.company AS customer_company,
                       c.email AS customer_email, c.phone AS customer_phone
                FROM work_orders wo JOIN customers c ON c.id = wo.customer_id
                WHERE wo.id = ?" . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$workOrderId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$source) {
            throw new InvalidArgumentException(t('eds_warranty_cards.task_missing'));
        }
        $issuer = EdsWarrantyCardIssuer::effective($this->pdo, $lock);
        $terms = EdsWarrantyCardTerms::read($this->pdo, $lock);
        $parts = [];
        $internalParts = [];
        foreach (($draft['units'] ?? []) as $key => $unit) {
            if (empty($unit['selected'])) {
                continue;
            }
            $parts[] = [
                'name' => (string) ($unit['name'] ?? ''),
                'serial_number' => (string) ($unit['serial_number'] ?? ''),
                'months' => (string) ($unit['months'] ?? ''),
            ];
            $internalParts[] = [
                'unit_key' => (string) $key,
                'source' => 'inventory',
                'supplier' => (string) ($unit['supplier'] ?? ''),
                'supplier_card' => (string) ($unit['supplier_card'] ?? ''),
            ];
        }
        foreach (($draft['manual_parts'] ?? []) as $key => $part) {
            if (!is_array($part) || EdsWarrantyCardDraftForm::manualIsEmpty($part)) {
                continue;
            }
            $parts[] = [
                'name' => (string) ($part['name'] ?? ''),
                'serial_number' => (string) ($part['serial_number'] ?? ''),
                'months' => (string) ($part['months'] ?? ''),
            ];
            $internalParts[] = [
                'unit_key' => (string) $key,
                'source' => 'manual',
                'supplier' => (string) ($part['supplier'] ?? ''),
                'supplier_card' => (string) ($part['supplier_card'] ?? ''),
            ];
        }
        $workWarranties = [];
        foreach (($draft['work_items'] ?? []) as $item) {
            if (!is_array($item) || EdsWarrantyCardDraftForm::workItemIsEmpty($item)) {
                continue;
            }
            $workWarranties[] = [
                'description' => (string) ($item['description'] ?? ''),
                'months' => (string) ($item['months'] ?? ''),
            ];
        }
        $public = [
            'issuer' => $issuer,
            'customer' => [
                'name' => (string) $source['customer_name'], 'company' => (string) ($source['customer_company'] ?? ''),
                'email' => (string) ($source['customer_email'] ?? ''), 'phone' => (string) ($source['customer_phone'] ?? ''),
            ],
            'parts' => $parts,
            'work_warranties' => $workWarranties,
            'terms_html' => $terms,
        ];
        return [$public, ['parts' => $internalParts]];
    }

    private function previewToken(string $revision, array $public, array $internal): string {
        return hash('sha256', json_encode([$revision, $public, $internal], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function requirePositiveMonths(string $months, string $messageKey = 'eds_warranty_cards.issue_months_required'): void {
        try {
            EdsWarrantyCardNumber::validate($months);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(t($messageKey));
        }
    }

    private function decode(array $row): array {
        return [
            'work_order_id' => (int) $row['work_order_id'],
            'card_number' => (string) $row['card_number'],
            'issued_at' => (string) $row['issued_at'],
            'public' => json_decode($row['public_content'], true, 512, JSON_THROW_ON_ERROR),
            'internal' => json_decode($row['internal_content'], true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
