<?php
require_once ROOT_PATH . '/core/Model.php';

class CustomerEmailEvent extends Model {
    protected $table = 'customer_email_events';

    /**
     * Records that $event happened for the work order. Returns false when it was already
     * recorded, which is how every email is limited to once per work order.
     */
    public function claim(int $workOrderId, string $event): bool {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO customer_email_events (work_order_id, event, sent, created_at)
            VALUES (?, ?, 0, NOW())
        ");
        $stmt->execute([$workOrderId, $event]);
        return $stmt->rowCount() > 0;
    }

    public function markSent(int $workOrderId, string $event): void {
        $stmt = $this->db->prepare("UPDATE customer_email_events SET sent = 1 WHERE work_order_id = ? AND event = ?");
        $stmt->execute([$workOrderId, $event]);
    }

    /**
     * How many times the work order history shows a change into one of $statuses. Covers
     * work orders that moved before this module was enabled, which have no event row.
     */
    public function countStatusChangesTo(int $workOrderId, array $statuses): int {
        if (!$statuses) {
            return 0;
        }
        $clauses = [];
        $params = [$workOrderId];
        foreach ($statuses as $status) {
            // Matches the English entry WorkOrder::logWorkOrderChange() writes.
            $clauses[] = 'details LIKE ?';
            $params[] = "Changed Status from '%' to '" . str_replace(['%', '_'], ['\%', '\_'], $status) . "'";
        }
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS count
            FROM work_order_logs
            WHERE work_order_id = ? AND action = 'updated' AND (" . implode(' OR ', $clauses) . ")
        ");
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int) ($row['count'] ?? 0);
    }
}
