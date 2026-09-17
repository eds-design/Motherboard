<?php

function motherboard_customer_email_ensure_schema(Database $database): void {
    $pdo = $database->connect();

    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote('customer_email_events'));
    if ($stmt && $stmt->rowCount() > 0) {
        return;
    }

    // One row per work order per event, written the first time the event happens whether or
    // not an email went out, so a status toggled back and forth never emails twice.
    $pdo->exec("CREATE TABLE customer_email_events (
        work_order_id INT NOT NULL,
        event VARCHAR(32) NOT NULL,
        sent TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (work_order_id, event),
        FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
