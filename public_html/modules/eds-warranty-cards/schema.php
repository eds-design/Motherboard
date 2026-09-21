<?php

const EDS_WARRANTY_CARDS_SCHEMA_VERSION = 5;

function eds_warranty_cards_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function eds_warranty_cards_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function eds_warranty_cards_needs_migration(PDO $pdo): bool {
    if (!eds_warranty_cards_table_exists($pdo, 'eds_warranty_cards_counter')
        || !eds_warranty_cards_table_exists($pdo, 'eds_warranty_cards_settings')
        || !eds_warranty_cards_table_exists($pdo, 'eds_warranty_cards_drafts')
        || !eds_warranty_cards_table_exists($pdo, 'eds_warranty_cards_issued')) {
        return true;
    }
    foreach (['issuer_service_name', 'issuer_legal_name', 'issuer_registration_number', 'issuer_representative', 'issuer_address', 'issuer_phone', 'issuer_email', 'issuer_website', 'issuer_logo_url'] as $column) {
        if (!eds_warranty_cards_column_exists($pdo, 'eds_warranty_cards_settings', $column)) {
            return true;
        }
    }
    $version = $pdo->query('SELECT schema_version FROM eds_warranty_cards_counter WHERE id = 1')->fetchColumn();
    return $version === false || (int) $version < EDS_WARRANTY_CARDS_SCHEMA_VERSION;
}

function eds_warranty_cards_migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS eds_warranty_cards_counter (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        next_number LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        schema_version INT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS eds_warranty_cards_drafts (
        work_order_id INT NOT NULL PRIMARY KEY,
        revision INT UNSIGNED NOT NULL,
        content LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS eds_warranty_cards_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        terms_html LONGTEXT NOT NULL,
        issuer_service_name VARCHAR(255) NOT NULL DEFAULT '',
        issuer_legal_name VARCHAR(255) NOT NULL DEFAULT '',
        issuer_registration_number VARCHAR(100) NOT NULL DEFAULT '',
        issuer_representative VARCHAR(255) NOT NULL DEFAULT '',
        issuer_address VARCHAR(2000) NOT NULL DEFAULT '',
        issuer_phone VARCHAR(100) NOT NULL DEFAULT '',
        issuer_email VARCHAR(254) NOT NULL DEFAULT '',
        issuer_website VARCHAR(2048) NOT NULL DEFAULT '',
        issuer_logo_url VARCHAR(2048) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS eds_warranty_cards_issued (
        work_order_id INT NOT NULL PRIMARY KEY,
        card_number LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        number_key BINARY(32) NOT NULL,
        issued_at DATETIME NOT NULL,
        issued_by INT NULL,
        public_content LONGTEXT NOT NULL,
        internal_content LONGTEXT NOT NULL,
        UNIQUE KEY unique_eds_warranty_card_number (number_key),
        FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE RESTRICT,
        FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $stmt = $pdo->prepare('INSERT IGNORE INTO eds_warranty_cards_counter (id, next_number, schema_version, updated_at) VALUES (1, ?, ?, NOW())');
    $stmt->execute(['1', EDS_WARRANTY_CARDS_SCHEMA_VERSION]);
    $pdo->exec("INSERT IGNORE INTO eds_warranty_cards_settings (id, terms_html, updated_at) VALUES (1, '', NOW())");
    $issuerColumns = [
        'issuer_service_name' => 'VARCHAR(255)',
        'issuer_legal_name' => 'VARCHAR(255)',
        'issuer_registration_number' => 'VARCHAR(100)',
        'issuer_representative' => 'VARCHAR(255)',
        'issuer_address' => 'VARCHAR(2000)',
        'issuer_phone' => 'VARCHAR(100)',
        'issuer_email' => 'VARCHAR(254)',
        'issuer_website' => 'VARCHAR(2048)',
        'issuer_logo_url' => 'VARCHAR(2048)',
    ];
    foreach ($issuerColumns as $column => $type) {
        if (!eds_warranty_cards_column_exists($pdo, 'eds_warranty_cards_settings', $column)) {
            $pdo->exec('ALTER TABLE eds_warranty_cards_settings ADD COLUMN ' . $column . ' ' . $type . " NOT NULL DEFAULT ''");
        }
    }
    $stmt = $pdo->prepare('UPDATE eds_warranty_cards_counter SET schema_version = ? WHERE id = 1 AND schema_version < ?');
    $stmt->execute([EDS_WARRANTY_CARDS_SCHEMA_VERSION, EDS_WARRANTY_CARDS_SCHEMA_VERSION]);
}
