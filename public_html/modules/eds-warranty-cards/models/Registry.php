<?php
require_once dirname(__DIR__) . '/lib.php';

final class EdsWarrantyCardRegistry {
    public const PAGE_SIZE = 50;
    public const SEARCH_MAX_CHARACTERS = 4096;

    public function __construct(private PDO $pdo) {}

    public static function normalizeSearch(mixed $value): string {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return '';
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > self::SEARCH_MAX_CHARACTERS) {
            $value = mb_substr($value, 0, self::SEARCH_MAX_CHARACTERS, 'UTF-8');
        }
        return $value;
    }

    public function count(string $search = ''): int {
        [$where, $params] = $this->filter($search);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM eds_warranty_cards_issued i WHERE ' . $where);
        $this->execute($stmt, $params);
        return (int) $stmt->fetchColumn();
    }

    public function page(string $search, int $limit, int $offset): array {
        $limit = max(1, min(self::PAGE_SIZE, $limit));
        $offset = max(0, $offset);
        [$where, $params, $numberKey] = $this->filter($search);
        $customerName = $this->jsonStringExpression('$.customer.name');
        $sql = "SELECT i.work_order_id, i.card_number, i.issued_at,
                       {$customerName} AS customer_name
                FROM eds_warranty_cards_issued i
                WHERE {$where}";
        if ($numberKey !== null) {
            $sql .= ' ORDER BY CASE WHEN i.number_key = ? THEN 0 ELSE 1 END, i.issued_at DESC, i.work_order_id DESC';
            $params[] = $numberKey;
        } else {
            $sql .= ' ORDER BY i.issued_at DESC, i.work_order_id DESC';
        }
        $sql .= ' LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->pdo->prepare($sql);
        $this->execute($stmt, $params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'work_order_id' => (int) $row['work_order_id'],
                'card_number' => is_string($row['card_number'] ?? null) ? $row['card_number'] : '',
                'customer_name' => is_string($row['customer_name'] ?? null) ? $row['customer_name'] : '',
                'issued_at' => is_string($row['issued_at'] ?? null) ? $row['issued_at'] : '',
            ];
        }
        return $rows;
    }

    private function filter(string $search): array {
        $search = self::normalizeSearch($search);
        if ($search === '') {
            return ['1 = 1', [], null];
        }

        $customerName = $this->jsonStringExpression('$.customer.name');
        $customerPhone = $this->jsonStringExpression('$.customer.phone');
        $conditions = [
            "LOCATE(LOWER(?), LOWER({$customerName})) > 0",
            "LOCATE(?, {$customerPhone}) > 0",
        ];
        $params = [$search, $search];
        $numberKey = null;
        if (preg_match('/\A[0-9]+\z/', $search) === 1 && ltrim($search, '0') !== '') {
            $numberKey = EdsWarrantyCardNumber::identityKey($search);
            $conditions[] = 'i.number_key = ?';
            $params[] = $numberKey;
        }
        return ['(' . implode(' OR ', $conditions) . ')', $params, $numberKey];
    }

    private function jsonStringExpression(string $path): string {
        $safeJson = "CASE WHEN JSON_VALID(i.public_content) THEN i.public_content ELSE '{}' END";
        $quotedPath = $this->pdo->quote($path);
        $value = "JSON_EXTRACT({$safeJson}, {$quotedPath})";
        return "CASE WHEN JSON_TYPE({$value}) = 'STRING' THEN COALESCE(JSON_UNQUOTE({$value}), '') ELSE '' END";
    }

    private function execute(PDOStatement $stmt, array $params): void {
        foreach (array_values($params) as $index => $value) {
            $stmt->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
    }
}
