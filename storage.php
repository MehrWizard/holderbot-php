<?php
/**
 * HolderBot PHP - Zero-Dependency Storage Layer
 *
 * Supports JSON file storage (default, zero setup) and MySQL (PDO).
 */

declare(strict_types=1);

class Storage {
    private static ?PDO $pdo = null;
    private static string $jsonFile = __DIR__ . '/data/storage.json';

    /**
     * Initialize storage and auto-migrate if needed.
     */
    public static function init(): void {
        global $config;
        $type = $config['storage_type'] ?? 'json';

        if ($type === 'mysql' && extension_loaded('pdo_mysql')) {
            self::initMysql();
        } else {
            self::initJson();
        }
    }

    // =========================================================================
    // JSON File Storage Implementation
    // =========================================================================

    private static function initJson(): void {
        if (!file_exists(dirname(self::$jsonFile))) {
            mkdir(dirname(self::$jsonFile), 0755, true);
        }

        if (!file_exists(self::$jsonFile)) {
            global $config;
            $initialData = [
                'servers' => $config['servers'] ?? [],
                'templates' => [
                    [
                        'id' => 1,
                        'remark' => 'Standard 30D / 50GB',
                        'data_limit' => 50,
                        'date_limit' => 30,
                    ],
                    [
                        'id' => 2,
                        'remark' => 'Heavy 30D / 100GB',
                        'data_limit' => 100,
                        'date_limit' => 30,
                    ],
                ],
                'states' => [],
                'cache' => [],
            ];
            self::writeJson($initialData);
        }
    }

    private static function readJson(): array {
        if (!file_exists(self::$jsonFile)) {
            self::initJson();
        }
        $fp = fopen(self::$jsonFile, 'rb');
        if (!$fp) {
            return ['servers' => [], 'templates' => [], 'states' => [], 'cache' => []];
        }
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode($content ?: '{}', true);
        return is_array($data) ? $data : ['servers' => [], 'templates' => [], 'states' => [], 'cache' => []];
    }

    private static function writeJson(array $data): bool {
        $fp = fopen(self::$jsonFile, 'c+b');
        if (!$fp) {
            return false;
        }
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return true;
        }
        fclose($fp);
        return false;
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
                    is_active TINYINT(1) DEFAULT 1
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS bot_states (
                    user_id BIGINT PRIMARY KEY,
                    step VARCHAR(64) NOT NULL,
                    data JSON NULL,
                    updated_at INT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS bot_cache (
                    cache_key VARCHAR(128) PRIMARY KEY,
                    cache_value TEXT NOT NULL,
                    expires_at INT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // Auto-migrate newly added columns if upgrading an existing database
            try { self::$pdo->exec("ALTER TABLE servers ADD COLUMN expired_stats TINYINT(1) DEFAULT 0"); } catch (Throwable) {}
            try { self::$pdo->exec("ALTER TABLE templates ADD COLUMN is_active TINYINT(1) DEFAULT 1"); } catch (Throwable) {}
        } catch (Throwable $e) {
            error_log("Storage MySQL initialization error: " . $e->getMessage());
            self::$pdo = null;
            self::initJson();
        }
    }

    // =========================================================================
    // Public Server Methods
    // =========================================================================

    public static function getServers(): array {
        if (self::$pdo) {
            $stmt = self::$pdo->query("SELECT * FROM servers ORDER BY id ASC");
            return $stmt->fetchAll() ?: [];
        }
        $data = self::readJson();
        return $data['servers'] ?? [];
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
        if (self::$pdo) {
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

        $data = self::readJson();
        $servers = $data['servers'] ?? [];
        if (!empty($server['id'])) {
            foreach ($servers as $k => $s) {
                if ((int)$s['id'] === (int)$server['id']) {
                    $servers[$k] = array_merge($s, $server);
                    $data['servers'] = $servers;
                    self::writeJson($data);
                    return (int)$server['id'];
                }
            }
        }

        $maxId = 0;
        foreach ($servers as $s) {
            if ((int)$s['id'] > $maxId) {
                $maxId = (int)$s['id'];
            }
        }
        $server['id'] = $maxId + 1;
        $server['is_active'] = (int)($server['is_active'] ?? 1);
        $server['node_monitoring'] = (int)($server['node_monitoring'] ?? 0);
        $server['node_restart'] = (int)($server['node_restart'] ?? 0);
        $server['expired_stats'] = (int)($server['expired_stats'] ?? 0);
        $servers[] = $server;
        $data['servers'] = $servers;
        self::writeJson($data);
        return $server['id'];
    }

    public static function deleteServer(int $id): bool {
        if (self::$pdo) {
            $stmt = self::$pdo->prepare("DELETE FROM servers WHERE id = ?");
            return $stmt->execute([$id]);
        }

        $data = self::readJson();
        $servers = $data['servers'] ?? [];
        $data['servers'] = array_values(array_filter($servers, fn($s) => (int)$s['id'] !== $id));
        return self::writeJson($data);
    }

    // =========================================================================
    // Public Template Methods
    // =========================================================================

    public static function getTemplates(): array {
        if (self::$pdo) {
            $stmt = self::$pdo->query("SELECT * FROM templates ORDER BY id ASC");
            return $stmt->fetchAll() ?: [];
        }
        $data = self::readJson();
        return $data['templates'] ?? [];
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
        if (self::$pdo) {
            if (!empty($template['id'])) {
                $stmt = self::$pdo->prepare("
                    UPDATE templates SET remark = ?, data_limit = ?, date_limit = ?, is_active = ? WHERE id = ?
                ");
                $stmt->execute([
                    $template['remark'],
                    $template['data_limit'],
                    $template['date_limit'],
                    (int)($template['is_active'] ?? 1),
                    $template['id']
                ]);
                return (int)$template['id'];
            }
            $stmt = self::$pdo->prepare("
                INSERT INTO templates (remark, data_limit, date_limit, is_active) VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $template['remark'],
                $template['data_limit'],
                $template['date_limit'],
                (int)($template['is_active'] ?? 1)
            ]);
            return (int)self::$pdo->lastInsertId();
        }

        $data = self::readJson();
        $templates = $data['templates'] ?? [];
        if (!empty($template['id'])) {
            foreach ($templates as $k => $t) {
                if ((int)$t['id'] === (int)$template['id']) {
                    $templates[$k] = array_merge($t, $template);
                    $data['templates'] = $templates;
                    self::writeJson($data);
                    return (int)$template['id'];
                }
            }
        }

        $maxId = 0;
        foreach ($templates as $t) {
            if ((int)$t['id'] > $maxId) {
                $maxId = (int)$t['id'];
            }
        }
        $template['id'] = $maxId + 1;
        $template['is_active'] = (int)($template['is_active'] ?? 1);
        $templates[] = $template;
        $data['templates'] = $templates;
        self::writeJson($data);
        return $template['id'];
    }

    public static function deleteTemplate(int $id): bool {
        if (self::$pdo) {
            $stmt = self::$pdo->prepare("DELETE FROM templates WHERE id = ?");
            return $stmt->execute([$id]);
        }

        $data = self::readJson();
        $templates = $data['templates'] ?? [];
        $data['templates'] = array_values(array_filter($templates, fn($t) => (int)$t['id'] !== $id));
        return self::writeJson($data);
    }

    // =========================================================================
    // FSM State Management
    // =========================================================================

    public static function getState(int $userId): ?array {
        if (self::$pdo) {
            $stmt = self::$pdo->prepare("SELECT * FROM bot_states WHERE user_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row) {
                return [
                    'step' => $row['step'],
                    'data' => json_decode($row['data'] ?: '[]', true),
                ];
            }
            return null;
        }

        $data = self::readJson();
        return $data['states'][$userId] ?? null;
    }

    public static function setState(int $userId, string $step, array $stateData = []): void {
        if (self::$pdo) {
            $stmt = self::$pdo->prepare("
                INSERT INTO bot_states (user_id, step, data, updated_at)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE step = VALUES(step), data = VALUES(data), updated_at = VALUES(updated_at)
            ");
            $stmt->execute([$userId, $step, json_encode($stateData), time()]);
            return;
        }

        $data = self::readJson();
        $data['states'][$userId] = [
            'step' => $step,
            'data' => $stateData,
            'updated_at' => time(),
        ];
        self::writeJson($data);
    }

    public static function clearState(int $userId): void {
        if (self::$pdo) {
            $stmt = self::$pdo->prepare("DELETE FROM bot_states WHERE user_id = ?");
            $stmt->execute([$userId]);
            return;
        }

        $data = self::readJson();
        unset($data['states'][$userId]);
        self::writeJson($data);
    }

    // =========================================================================
    // Cache Key-Value
    // =========================================================================

    public static function cacheGet(string $key): mixed {
        if (self::$pdo) {
            $stmt = self::$pdo->prepare("SELECT cache_value, expires_at FROM bot_cache WHERE cache_key = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if ($row && (int)$row['expires_at'] > time()) {
                return json_decode($row['cache_value'], true);
            }
            return null;
        }

        $data = self::readJson();
        $item = $data['cache'][$key] ?? null;
        if ($item && ($item['expires_at'] ?? 0) > time()) {
            return $item['value'];
        }
        return null;
    }

    public static function cacheSet(string $key, mixed $value, int $ttlSeconds = 3600): void {
        if (self::$pdo) {
            $stmt = self::$pdo->prepare("
                INSERT INTO bot_cache (cache_key, cache_value, expires_at)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expires_at = VALUES(expires_at)
            ");
            $stmt->execute([$key, json_encode($value), time() + $ttlSeconds]);
            return;
        }

        $data = self::readJson();
        $data['cache'][$key] = [
            'value' => $value,
            'expires_at' => time() + $ttlSeconds,
        ];
        self::writeJson($data);
    }
}
