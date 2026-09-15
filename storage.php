<?php
/**
 * HolderBot PHP - Zero-Dependency Storage Layer
 *
 * MySQL-only storage. JSON is used only for structured MySQL column values and
 * HTTP protocol payloads; no application state is written to local files.
 */

declare(strict_types=1);

class Storage {
    private static ?PDO $pdo = null;
    public static function db(): PDO {
        if (self::$pdo === null) throw new RuntimeException('MySQL storage is not initialized');
        return self::$pdo;
    }
    private static int|string|null $chatContext = null;
    public static function setChatContext(int|string|null $chatId): void { self::$chatContext = $chatId; }
    private static function stateChat(int $userId): int|string { return self::$chatContext ?? $userId; }

    /**
     * Initialize storage and auto-migrate if needed.
     */
    public static function init(): void {
        global $config;
        if (($config['storage_type'] ?? 'mysql') !== 'mysql') throw new RuntimeException('MySQL storage is required; JSON storage is disabled');
        if (!extension_loaded('pdo_mysql')) throw new RuntimeException('PDO MySQL extension is required');
        self::initMysql();
    }

    // =========================================================================
    // MySQL Implementation
    // =========================================================================

    private static function initMysql(): void {
        global $config;
        if (self::$pdo !== null) {
            return;
        }

        $mc = $config['mysql'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $mc['host'] ?? '127.0.0.1',
            $mc['port'] ?? 3306,
            $mc['database'] ?? 'holderbot',
            $mc['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO($dsn, $mc['username'] ?? 'root', $mc['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Serialize schema upgrades across concurrent webhook/cron workers.
            $schemaLock = 'holderbot-schema-' . substr(hash('sha256', $mc['database'] ?? 'holderbot'), 0, 32);
            $lockStatement = self::$pdo->prepare('SELECT GET_LOCK(?, 30)');
            $lockStatement->execute([$schemaLock]);
            if ((int)$lockStatement->fetchColumn() !== 1) throw new RuntimeException('Unable to lock schema upgrade');
            try {
                // Auto-create tables if they do not exist
                self::$pdo->exec("
                    CREATE TABLE IF NOT EXISTS servers (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        remark VARCHAR(64) NOT NULL,
                        type VARCHAR(32) NOT NULL,
                        base_url VARCHAR(255) NOT NULL,
                        username VARCHAR(64) NOT NULL,
                        password VARCHAR(64) NOT NULL,
                        is_active TINYINT(1) DEFAULT 1,
                        node_monitoring TINYINT(1) DEFAULT 0,
                        node_restart TINYINT(1) DEFAULT 0,
                        expired_stats TINYINT(1) DEFAULT 0,
                        cached_token TEXT NULL,
                        token_expires_at INT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                    CREATE TABLE IF NOT EXISTS templates (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        remark VARCHAR(64) NOT NULL,
                        data_limit INT NOT NULL,
                        date_limit INT NOT NULL,
                        date_type VARCHAR(16) NOT NULL DEFAULT 'fixed',
                        is_active TINYINT(1) DEFAULT 1
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                    CREATE TABLE IF NOT EXISTS bot_states (
                        user_id BIGINT NOT NULL,
                        chat_id BIGINT NOT NULL,
                        PRIMARY KEY (user_id, chat_id),
                        step VARCHAR(64) NOT NULL,
                        data JSON NULL,
                        updated_at INT NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                    CREATE TABLE IF NOT EXISTS bot_cache (
                        cache_key VARCHAR(128) PRIMARY KEY,
                        cache_value MEDIUMTEXT NOT NULL,
                        expires_at INT NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                    CREATE TABLE IF NOT EXISTS bot_queue (
                        id CHAR(32) PRIMARY KEY,
                        kind VARCHAR(32) NOT NULL,
                        server_id INT NOT NULL DEFAULT 0,
                        server_fingerprint CHAR(64) NOT NULL,
                        chat_id BIGINT NOT NULL,
                        user_id BIGINT NOT NULL,
                        submission_key CHAR(64) NOT NULL,
                        status VARCHAR(20) NOT NULL,
                        payload JSON NOT NULL,
                        cursor_pos INT NOT NULL DEFAULT 0,
                        total INT NOT NULL DEFAULT 0,
                        success_count INT NOT NULL DEFAULT 0,
                        unconfirmed_count INT NOT NULL DEFAULT 0,
                        skipped_count INT NOT NULL DEFAULT 0,
                        error_text VARCHAR(1000) NULL,
                        next_run INT NOT NULL DEFAULT 0,
                        active_phase VARCHAR(32) NULL,
                        notification_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                        lease_until INT NOT NULL DEFAULT 0,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY queue_submission (submission_key),
                        KEY queue_runnable (status, next_run, created_at),
                        KEY queue_owner (chat_id, user_id, created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                self::$pdo->exec("CREATE TABLE IF NOT EXISTS bot_queue_items (
                    job_id CHAR(32) NOT NULL,
                    position INT NOT NULL,
                    username VARCHAR(255) NOT NULL,
                    payload JSON NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    PRIMARY KEY (job_id, position),
                    UNIQUE KEY job_username (job_id, username)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                self::$pdo->exec("CREATE TABLE IF NOT EXISTS bot_queue_imports (
                    job_id CHAR(32) PRIMARY KEY,
                    content MEDIUMBLOB NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $stateColumns = self::$pdo->query('SHOW COLUMNS FROM bot_states')->fetchAll(PDO::FETCH_COLUMN);
                self::$pdo->exec("CREATE TABLE IF NOT EXISTS bot_notifications (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    job_id CHAR(32) NOT NULL,
                    delivery_key VARCHAR(100) NOT NULL,
                    chat_id BIGINT NOT NULL,
                    payload JSON NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    attempts INT NOT NULL DEFAULT 0,
                    next_run INT NOT NULL DEFAULT 0,
                    error_text VARCHAR(1000) NULL,
                    telegram_message_id BIGINT NULL,
                    UNIQUE KEY notification_identity(job_id,delivery_key),
                    KEY notification_pending(status,next_run,id),
                    KEY notification_order(job_id,status,id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                if (!in_array('chat_id', $stateColumns, true)) {
                    self::$pdo->exec('ALTER TABLE bot_states ADD COLUMN chat_id BIGINT NOT NULL DEFAULT 0');
                }
                // Resume safely if an earlier process stopped after adding chat_id.
                $primaryColumns = self::$pdo->query("SHOW INDEX FROM bot_states WHERE Key_name = 'PRIMARY'")->fetchAll();
                usort($primaryColumns, fn($a, $b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
                if (array_column($primaryColumns, 'Column_name') !== ['user_id', 'chat_id']) {
                    self::$pdo->exec('UPDATE bot_states SET chat_id = user_id WHERE chat_id = 0');
                    $dropPrimary = $primaryColumns ? 'DROP PRIMARY KEY, ' : '';
                    self::$pdo->exec('ALTER TABLE bot_states ' . $dropPrimary . 'ADD PRIMARY KEY (user_id, chat_id)');
                }

                foreach (['servers', 'templates'] as $table) {
                    $columns = self::$pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
                    if (!in_array('created_at', $columns, true)) self::$pdo->exec("ALTER TABLE {$table} ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
                    if (!in_array('updated_at', $columns, true)) self::$pdo->exec("ALTER TABLE {$table} ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
                }

                // Auto-migrate newly added columns if upgrading an existing database
                try { self::$pdo->exec("ALTER TABLE servers ADD COLUMN expired_stats TINYINT(1) DEFAULT 0"); } catch (Throwable) {}
                try { self::$pdo->exec("ALTER TABLE templates ADD COLUMN is_active TINYINT(1) DEFAULT 1"); } catch (Throwable) {}
                try { self::$pdo->exec("ALTER TABLE templates ADD COLUMN date_type VARCHAR(16) NOT NULL DEFAULT 'fixed'"); } catch (Throwable) {}
                try { self::$pdo->exec("ALTER TABLE bot_queue ADD COLUMN lease_until INT NOT NULL DEFAULT 0 AFTER notification_status"); } catch (Throwable) {}
                $cacheColumn=self::$pdo->query("SHOW COLUMNS FROM bot_cache LIKE 'cache_value'")->fetch();
                if(strtolower((string)($cacheColumn['Type'] ?? ''))==='text') self::$pdo->exec('ALTER TABLE bot_cache MODIFY cache_value MEDIUMTEXT NOT NULL');
                $queueColumns = self::$pdo->query('SHOW COLUMNS FROM bot_queue')->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('cancel_requested', $queueColumns, true)) self::$pdo->exec('ALTER TABLE bot_queue ADD COLUMN cancel_requested TINYINT NOT NULL DEFAULT 0');
                if (!in_array('last_served', $queueColumns, true)) self::$pdo->exec('ALTER TABLE bot_queue ADD COLUMN last_served DOUBLE NOT NULL DEFAULT 0, ADD INDEX queue_fair (last_served)');
                $itemIndexes = self::$pdo->query('SHOW INDEX FROM bot_queue_items')->fetchAll(PDO::FETCH_ASSOC);
                if (!in_array('queue_item_status', array_column($itemIndexes, 'Key_name'), true)) {
                    self::$pdo->exec('ALTER TABLE bot_queue_items ADD INDEX queue_item_status (job_id,status,position)');
                }

                // Seed optional servers from config.php once. Credentials remain
                // under operator control and are persisted in MySQL for runtime use.
                foreach (($config['servers'] ?? []) as $configuredServer) {
                    if (!is_array($configuredServer) || empty($configuredServer['remark']) || empty($configuredServer['base_url'])) continue;
                    $serverId = (int)($configuredServer['id'] ?? 0);
                    if ($serverId > 0) {
                        $check = self::$pdo->prepare('SELECT id FROM servers WHERE id=?');
                        $check->execute([$serverId]);
                        if ($check->fetchColumn()) {
                            $update = self::$pdo->prepare('UPDATE servers SET remark=?,type=?,base_url=?,username=?,password=?,is_active=?,node_monitoring=?,node_restart=?,expired_stats=? WHERE id=?');
                            $update->execute([
                                $configuredServer['remark'], $configuredServer['type'] ?? 'marzban', rtrim((string)$configuredServer['base_url'], '/'),
                                $configuredServer['username'] ?? '', $configuredServer['password'] ?? '', (int)($configuredServer['is_active'] ?? 1),
                                (int)($configuredServer['node_monitoring'] ?? 0), (int)($configuredServer['node_restart'] ?? 0), (int)($configuredServer['expired_stats'] ?? 0), $serverId
                            ]);
                            continue;
                        }
                    }
                    $check = self::$pdo->prepare('SELECT id FROM servers WHERE base_url=? AND username=?');
                    $check->execute([rtrim((string)$configuredServer['base_url'], '/'), (string)($configuredServer['username'] ?? '')]);
                    if ($check->fetchColumn()) continue;
                    $insert = self::$pdo->prepare('INSERT INTO servers (remark,type,base_url,username,password,is_active,node_monitoring,node_restart,expired_stats) VALUES (?,?,?,?,?,?,?,?,?)');
                    $insert->execute([
                        $configuredServer['remark'], $configuredServer['type'] ?? 'marzban', rtrim((string)$configuredServer['base_url'], '/'),
                        $configuredServer['username'] ?? '', $configuredServer['password'] ?? '', (int)($configuredServer['is_active'] ?? 1),
                        (int)($configuredServer['node_monitoring'] ?? 0), (int)($configuredServer['node_restart'] ?? 0), (int)($configuredServer['expired_stats'] ?? 0)
                    ]);
                }
            } finally {
                $releaseStatement = self::$pdo->prepare('SELECT RELEASE_LOCK(?)');
                $releaseStatement->execute([$schemaLock]);
            }
        } catch (Throwable $e) {
            error_log("Storage MySQL initialization error: " . $e->getMessage());
            self::$pdo = null;
            throw new RuntimeException('MySQL storage initialization failed', 0, $e);
        }
    }

    // =========================================================================
    // Public Server Methods
    // =========================================================================

    public static function getServers(): array {
        $stmt = self::db()->query("SELECT * FROM servers ORDER BY id ASC");
        return $stmt->fetchAll() ?: [];
    }

    public static function getServer(int $id): ?array {
        $servers = self::getServers();
        foreach ($servers as $s) {
            if ((int)$s['id'] === $id) {
                return $s;
            }
        }
        return null;
    }

    public static function saveServer(array $server): int {
        if (!empty($server['id'])) {
                $stmt = self::$pdo->prepare("
                    UPDATE servers SET remark = ?, type = ?, base_url = ?, username = ?, password = ?,
                    is_active = ?, node_monitoring = ?, node_restart = ?, expired_stats = ?, cached_token = ?, token_expires_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $server['remark'], $server['type'], rtrim($server['base_url'], '/'),
                    $server['username'], $server['password'], (int)($server['is_active'] ?? 1),
                    (int)($server['node_monitoring'] ?? 0), (int)($server['node_restart'] ?? 0),
                    (int)($server['expired_stats'] ?? 0),
                    $server['cached_token'] ?? null, $server['token_expires_at'] ?? null,
                    $server['id']
                ]);
                self::cacheDelete('stats_result_'.(int)$server['id']);
                return (int)$server['id'];
        }

            $stmt = self::$pdo->prepare("
                INSERT INTO servers (remark, type, base_url, username, password, is_active, node_monitoring, node_restart, expired_stats)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $server['remark'], $server['type'], rtrim($server['base_url'], '/'),
                $server['username'], $server['password'], (int)($server['is_active'] ?? 1),
                (int)($server['node_monitoring'] ?? 0), (int)($server['node_restart'] ?? 0),
                (int)($server['expired_stats'] ?? 0)
            ]);
            return (int)self::$pdo->lastInsertId();
    }

    public static function deleteServer(int $id): bool {
        $stmt = self::db()->prepare("DELETE FROM servers WHERE id = ?");
        $ok=$stmt->execute([$id]);
        if($ok) self::cacheDelete('stats_result_'.$id);
        return $ok;
    }

    // =========================================================================
    // Public Template Methods
    // =========================================================================

    public static function getTemplates(): array {
        $stmt = self::db()->query("SELECT * FROM templates ORDER BY id ASC");
        return $stmt->fetchAll() ?: [];
    }

    public static function getActiveTemplates(): array {
        $templates = self::getTemplates();
        return array_values(array_filter($templates, fn($t) => !isset($t['is_active']) || !empty($t['is_active'])));
    }

    public static function getTemplate(int $id): ?array {
        $templates = self::getTemplates();
        foreach ($templates as $t) {
            if ((int)$t['id'] === $id) {
                return $t;
            }
        }
        return null;
    }

    public static function saveTemplate(array $template): int {
        if (!empty($template['id'])) {
                $stmt = self::$pdo->prepare("
                    UPDATE templates SET remark = ?, data_limit = ?, date_limit = ?, date_type = ?, is_active = ? WHERE id = ?
                ");
                $stmt->execute([
                    $template['remark'],
                    $template['data_limit'],
                    $template['date_limit'],
                    $template['date_type'] ?? 'fixed',
                    (int)($template['is_active'] ?? 1),
                    $template['id']
                ]);
                return (int)$template['id'];
        }
            $stmt = self::$pdo->prepare("
                INSERT INTO templates (remark, data_limit, date_limit, date_type, is_active) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $template['remark'],
                $template['data_limit'],
                $template['date_limit'],
                $template['date_type'] ?? 'fixed',
                (int)($template['is_active'] ?? 1)
            ]);
            return (int)self::$pdo->lastInsertId();
    }

    public static function deleteTemplate(int $id): bool {
        $stmt = self::db()->prepare("DELETE FROM templates WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // =========================================================================
    // FSM State Management
    // =========================================================================

    public static function getState(int $userId): ?array {
        $stmt = self::db()->prepare("SELECT * FROM bot_states WHERE user_id = ? AND chat_id = ?");
        $stmt->execute([$userId, self::stateChat($userId)]);
        $row = $stmt->fetch();
        return $row ? ['step' => $row['step'], 'data' => json_decode($row['data'] ?: '[]', true)] : null;
    }

    public static function setState(int $userId, string $step, array $stateData = []): void {
        $stmt = self::db()->prepare("INSERT INTO bot_states (user_id, chat_id, step, data, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE step=VALUES(step), data=VALUES(data), updated_at=VALUES(updated_at)");
        $stmt->execute([$userId, self::stateChat($userId), $step, json_encode($stateData, JSON_THROW_ON_ERROR), time()]);
    }

    public static function clearState(int $userId): void {
        self::db()->prepare("DELETE FROM bot_states WHERE user_id = ? AND chat_id = ?")->execute([$userId, self::stateChat($userId)]);
    }

    // =========================================================================
    // Cache Key-Value
    // =========================================================================

    public static function cacheGet(string $key): mixed {
        $stmt = self::db()->prepare("SELECT cache_value, expires_at FROM bot_cache WHERE cache_key = ?");
        $stmt->execute([$key]); $row = $stmt->fetch();
        if (!$row) return null;
        if ((int)$row['expires_at'] <= time()) {
            self::cacheDelete($key);
            return null;
        }
        return json_decode($row['cache_value'], true);
    }

    public static function cacheSet(string $key, mixed $value, int $ttlSeconds = 3600): void {
        $stmt = self::db()->prepare("INSERT INTO bot_cache (cache_key, cache_value, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cache_value=VALUES(cache_value), expires_at=VALUES(expires_at)");
        $stmt->execute([$key, json_encode($value, JSON_THROW_ON_ERROR), time() + $ttlSeconds]);
    }
    public static function cacheDelete(string $key): void {
        self::db()->prepare('DELETE FROM bot_cache WHERE cache_key=?')->execute([$key]);
    }
}
