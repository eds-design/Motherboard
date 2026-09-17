<?php

function motherboard_customer_email_ensure_schema(Database $database): void {
    $pdo = $database->connect();

    // One row per work order per event, written the first time the event happens whether or
    // not an email went out, so a status toggled back and forth never emails twice.
    if (!motherboard_customer_email_table_exists($pdo, 'customer_email_events')) {
        $pdo->exec("CREATE TABLE customer_email_events (
            work_order_id INT NOT NULL,
            event VARCHAR(32) NOT NULL,
            sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (work_order_id, event),
            FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // A row here means this work order is opted out. Emails are on by default, so the
    // absence of a row is the normal case and needs no backfill.
    if (!motherboard_customer_email_table_exists($pdo, 'customer_email_optouts')) {
        $pdo->exec("CREATE TABLE customer_email_optouts (
            work_order_id INT NOT NULL PRIMARY KEY,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

function motherboard_customer_email_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return $stmt && $stmt->rowCount() > 0;
}
