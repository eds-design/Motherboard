<?php
class Database {
    private $connection;
    private $host;
    private $dbname;
    private $username;
    private $password;
    
    public function __construct() {
        $this->host = DB_HOST;
        $this->dbname = DB_NAME;
        $this->username = DB_USER;
        $this->password = DB_PASS;
    }
    
    public function connect() {
        if ($this->connection === null) {
            try {
                $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->dbname . ";charset=utf8mb4";
                $this->connection = new PDO($dsn, $this->username, $this->password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]);
            } catch (PDOException $e) {
                // The driver message carries the host, database name and username.
                // Keep it in the log; hand the caller something safe to render.
                error_log('Database connection failed: ' . $e->getMessage());
                throw new Exception('Database connection failed.');
            }
        }
        return $this->connection;
    }
    
    public function isInstalled() {
        return $this->installationState() === 'installed';
    }

    /**
     * Installation is permitted only in a completely empty database. Once any table
     * exists, an interrupted setup must be repaired or cleared deliberately rather
     * than being silently overwritten by another installer run.
     */
    public function installationState(): string {
        try {
            $tables = $this->tableNames();
        } catch (Exception $e) {
            return 'unavailable';
        }

        if ($tables === []) {
            return 'empty';
        }

        $required = [
            'users',
            'customers',
            'work_orders',
            'work_order_logs',
            'user_logins',
            'login_attempts',
            'activity_logs',
            'settings',
        ];

        return array_diff($required, $tables) === [] ? 'installed' : 'incomplete';
    }

    public function tableNames(): array {
        $stmt = $this->connect()->query('SHOW TABLES');
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
    
    public function canConnect() {
        try {
            $this->connect();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function prepare($sql) {
        return $this->connect()->prepare($sql);
    }
    
    public function query($sql) {
        return $this->connect()->query($sql);
    }
    
    public function lastInsertId() {
        return $this->connect()->lastInsertId();
    }
    
    public function beginTransaction() {
        return $this->connect()->beginTransaction();
    }
    
    public function commit() {
        return $this->connect()->commit();
    }
    
    public function rollback() {
        return $this->connect()->rollback();
    }

    public function inTransaction(): bool {
        return $this->connect()->inTransaction();
    }

    public function acquireLock(string $name, int $timeoutSeconds = 0): bool {
        $stmt = $this->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$name, max(0, $timeoutSeconds)]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function releaseLock(string $name): void {
        $stmt = $this->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    }
}
