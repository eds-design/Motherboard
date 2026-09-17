<?php
require_once ROOT_PATH . '/core/Model.php';

/**
 * Per work order opt-out. Emails are on by default, so a row here means "send nothing for
 * this work order".
 */
class CustomerEmailOptOut extends Model {
    protected $table = 'customer_email_optouts';

    /** The work order view asks once per render; notify() asks again on the same request. */
    private static array $cache = [];

    public function isDisabled(int $workOrderId): bool {
        if ($workOrderId <= 0) {
            return false;
        }
        if (!array_key_exists($workOrderId, self::$cache)) {
            $stmt = $this->db->prepare("SELECT 1 FROM customer_email_optouts WHERE work_order_id = ? LIMIT 1");
            $stmt->execute([$workOrderId]);
            self::$cache[$workOrderId] = (bool) $stmt->fetch();
        }
        return self::$cache[$workOrderId];
    }

    public function setDisabled(int $workOrderId, bool $disabled): void {
        if ($workOrderId <= 0) {
            return;
        }
        if ($disabled) {
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO customer_email_optouts (work_order_id, created_at)
                VALUES (?, NOW())
            ");
        } else {
            $stmt = $this->db->prepare("DELETE FROM customer_email_optouts WHERE work_order_id = ?");
        }
        $stmt->execute([$workOrderId]);
        self::$cache[$workOrderId] = $disabled;
    }
}
