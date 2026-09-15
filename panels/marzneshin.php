<?php
/**
 * HolderBot PHP - Marzneshin Panel API Client
 *
 * Implements REST calls using native PHP cURL (zero external dependencies).
 */

declare(strict_types=1);
require_once __DIR__ . '/../helpers/request_budget.php';

class MarzneshinClient {
    public static string $lastError = '';
    public static ?Closure $transport = null;

    public static function getLastError(): string {
        return self::$lastError;
    }

    /**
     * Send HTTP request to Marzneshin API.
     */
    public static function request(
        array $server,
        string $method,
        string $endpoint,
        mixed $payload = null,
        bool $requiresAuth = true,
        bool $asFormUrlencoded = false
    ): ?array {
        self::$lastError = '';
        if (self::$transport !== null) return (self::$transport)($server, $method, $endpoint, $payload);
        $baseUrl = rtrim($server['base_url'], '/');
        $url = $baseUrl . $endpoint;

        $headers = ['Accept: application/json'];

        if ($requiresAuth) {
            $token = self::getToken($server);
            if (!$token) {
                error_log("MarzneshinClient: Failed to obtain valid token for server [{$server['remark']}]");
                return null;
            }
            $headers[] = "Authorization: Bearer {$token}";
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT_MS => RequestBudget::milliseconds(5),
            CURLOPT_CONNECTTIMEOUT_MS => RequestBudget::milliseconds(5),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($payload !== null) {
            if ($asFormUrlencoded && is_array($payload)) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($payload);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $options[CURLOPT_POSTFIELDS] = is_string($payload) ? $payload : json_encode(self::stripNulls($payload));
                $headers[] = 'Content-Type: application/json';
            }
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            self::$lastError = "Connection error: " . $err;
            error_log("MarzneshinClient cURL error ({$url}): {$err}");
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errData = json_decode($response, true);
            $msg = $errData['detail'] ?? "HTTP {$httpCode}: {$response}";
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            self::$lastError = (string)$msg;
            error_log("MarzneshinClient HTTP {$httpCode} ({$url}): {$response}");
            return null;
        }

        if (trim($response) === '') {
            return ['success' => true];
        }
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::$lastError = 'Invalid JSON response: ' . json_last_error_msg();
            return null;
        }
        return is_array($decoded) ? ($decoded === [] && str_starts_with(ltrim($response), '{') ? ['success' => true] : $decoded) : null;
    }

    /** Recursively remove null values so they are omitted from the JSON body entirely. */
    private static function stripNulls(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if ($v === null) {
                continue;
            }
            $out[$k] = self::stripNulls($v);
        }
        return $out;
    }

    /**
     * Get or refresh admin authentication token. Only a sudo admin account is
     * accepted - a non-sudo credential is rejected outright, matching the
     * original bot, which refuses to onboard (or keep using) a non-sudo panel
     * account.
     */
    public static function getToken(array &$server, bool $force = false): ?string {
        $cacheKey = "marzneshin_token_" . hash('sha256', json_encode([$server['base_url'], $server['username'], $server['password']]));
        $cached = Storage::cacheGet($cacheKey);
        if ($cached && !$force) {
            return $cached;
        }

        $resp = self::request(
            $server,
            'POST',
            '/api/admins/token',
            [
                'username' => $server['username'],
                'password' => $server['password'],
                'grant_type' => 'password',
            ],
            requiresAuth: false,
            asFormUrlencoded: true
        );

        if (empty($resp['access_token'])) {
            return null;
        }

        if (empty($resp['is_sudo'])) {
            self::$lastError = 'Admin account is not a sudo admin.';
            error_log("MarzneshinClient: rejecting non-sudo credentials for server [{$server['remark']}]");
            return null;
        }

        $token = $resp['access_token'];
        Storage::cacheSet($cacheKey, $token, 8 * 3600);
        Storage::cacheSet("online_" . ($server['id'] ?? md5($server['base_url'])), time(), 86400);
        return $token;
    }

    /**
     * Get user details by username.
     */
    public static function getUser(array $server, string $username): ?array {
        return self::request($server, 'GET', '/api/users/' . rawurlencode($username));
    }

    /**
     * Get paginated list of users.
     */
    public static function getUsers(
        array $server,
        int $page = 1,
        int $size = 20,
        ?string $search = null,
        ?string $status = null,
        ?string $ownerUsername = null,
        ?bool $expired = null,
        ?bool $limited = null
    ): ?array {
        $query = [
            'page' => $page,
            'size' => $size,
            'order_by' => 'created_at',
            'descending' => 'true',
        ];
        if (!empty($search)) {
            $query['username'] = $search;
        }
        if (!empty($ownerUsername) && $ownerUsername !== 'ALL') {
            $query['owner_username'] = $ownerUsername;
        }
        if ($expired !== null) {
            $query['expired'] = $expired ? 'true' : 'false';
        }
        if ($limited !== null) {
            $query['data_limit_reached'] = $limited ? 'true' : 'false';
        }
        if (!empty($status)) {
            if ($status === 'active') {
                $query['is_active'] = 'true';
            } elseif ($status === 'disabled') {
                $query['is_active'] = 'false';
            }
        }

        $endpoint = '/api/users?' . http_build_query($query);
        $resp = self::request($server, 'GET', $endpoint);
        return $resp['items'] ?? null;
    }

    /**
     * Create a new user.
     */
    public static function createUser(
        array $server,
        string $username,
        int $dataLimitBytes,
        ?int $expireTimestamp,
        array $serviceIds = [],
        ?string $note = null
    ): ?array {
        $strategy = 'never';
        $expireDate = null;
        $usageDuration = null;

        if ($expireTimestamp !== null && $expireTimestamp > 0) {
            $strategy = 'fixed_date';
            $expireDate = gmdate('Y-m-d\TH:i:s\Z', $expireTimestamp);
        }

        if (empty($serviceIds)) {
            $services = self::getServices($server);
            if (!empty($services)) {
                $serviceIds = array_column($services, 'id');
            }
        }

        $payload = [
            'username' => $username,
            'data_limit' => $dataLimitBytes,
            'service_ids' => $serviceIds,
            'expire_strategy' => $strategy,
            'expire_date' => $expireDate,
            'usage_duration' => $usageDuration,
        ];
        if ($note !== null) {
            $payload['note'] = $note;
        }
        return self::request($server, 'POST', '/api/users', $payload);
    }

    /**
     * Modify existing user data.
     */
    public static function modifyUser(array $server, string $username, array $data): ?array {
        $data['username'] = $username;
        return self::request($server, 'PUT', '/api/users/' . rawurlencode($username), $data);
    }

    /**
     * Toggle user status (active/disabled).
     */
    public static function setStatus(array $server, string $username, bool $active): bool {
        $encoded = rawurlencode($username);
        $endpoint = $active ? "/api/users/{$encoded}/enable" : "/api/users/{$encoded}/disable";
        $resp = self::request($server, 'POST', $endpoint);
        return $resp !== null;
    }

    /**
     * Reset user usage statistics.
     */
    public static function resetUsage(array $server, string $username): bool {
        $resp = self::request($server, 'POST', '/api/users/' . rawurlencode($username) . '/reset');
        return $resp !== null;
    }

    /**
     * Revoke user subscription (regenerates token).
     */
    public static function revokeSub(array $server, string $username): ?array {
        return self::request($server, 'POST', '/api/users/' . rawurlencode($username) . '/revoke_sub');
    }

    /**
     * Delete user from panel.
     */
    public static function deleteUser(array $server, string $username): bool {
        $resp = self::request($server, 'DELETE', '/api/users/' . rawurlencode($username));
        return $resp !== null;
    }

    /**
     * Get list of panel administrators.
     */
    public static function getAdmins(array $server): ?array {
        $resp = self::request($server, 'GET', '/api/admins');
        return $resp['items'] ?? (is_array($resp) ? $resp : null);
    }

    /**
     * Set / change user owner admin.
     */
    public static function setOwner(array $server, string $username, string $adminUsername): bool {
        $endpoint = '/api/users/' . rawurlencode($username) . '/set-owner?' . http_build_query([
            'username' => $username,
            'admin_username' => $adminUsername,
        ]);
        $resp = self::request($server, 'PUT', $endpoint);
        return $resp !== null;
    }

    /**
     * Activate all users belonging to an admin.
     */
    public static function activateUsersByAdmin(array $server, string $adminUsername): bool {
        $resp = self::request($server, 'POST', '/api/admins/' . rawurlencode($adminUsername) . '/enable_users');
        return $resp !== null;
    }

    /**
     * Disable all users belonging to an admin.
     */
    public static function disableUsersByAdmin(array $server, string $adminUsername): bool {
        $resp = self::request($server, 'POST', '/api/admins/' . rawurlencode($adminUsername) . '/disable_users');
        return $resp !== null;
    }

    /**
     * Get services list.
     */
    public static function getServices(array $server): ?array {
        $resp = self::request($server, 'GET', '/api/services');
        return $resp['items'] ?? null;
    }

    /**
     * Get nodes list and their statuses.
     */
    public static function getNodes(array $server): ?array {
        $resp = self::request($server, 'GET', '/api/nodes');
        return $resp['items'] ?? null;
    }

    /**
     * Restart/Resync a node.
     */
    public static function restartNode(array $server, int $nodeId): bool {
        $resp = self::request($server, 'POST', "/api/nodes/{$nodeId}/resync");
        return $resp !== null;
    }
}
