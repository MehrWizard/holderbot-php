<?php
/**
 * HolderBot PHP - Marzban Panel API Client
 *
 * Implements REST calls using native PHP cURL (zero external dependencies).
 */

declare(strict_types=1);

class MarzbanClient {
    /**
     * Send HTTP request to Marzban API.
     */
    private static function request(
        array $server,
        string $method,
        string $endpoint,
        mixed $payload = null,
        bool $requiresAuth = true,
        bool $asFormUrlencoded = false
    ): ?array {
        $baseUrl = rtrim($server['base_url'], '/');
        $url = $baseUrl . $endpoint;

        $headers = ['Accept: application/json'];

        if ($requiresAuth) {
            $token = self::getToken($server);
            if (!$token) {
                error_log("MarzbanClient: Failed to obtain valid token for server [{$server['remark']}]");
                return null;
            }
            $headers[] = "Authorization: Bearer {$token}";
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];

        if ($payload !== null) {
            if ($asFormUrlencoded && is_array($payload)) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($payload);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $options[CURLOPT_POSTFIELDS] = is_string($payload) ? $payload : json_encode($payload);
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
            error_log("MarzbanClient cURL error ({$url}): {$err}");
            return null;
        }

        if ($httpCode >= 400) {
            $errData = json_decode($response, true);
            $msg = $errData['detail'] ?? "HTTP {$httpCode}: {$response}";
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            self::$lastError = (string)$msg;
            error_log("MarzbanClient HTTP {$httpCode} ({$url}): {$response}");
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['raw' => $response, 'code' => $httpCode];
    }

    /**
     * Get or refresh admin authentication token.
     */
    public static function getToken(array &$server): ?string {
        $cacheKey = "marzban_token_" . ($server['id'] ?? md5($server['base_url']));
        $cached = Storage::cacheGet($cacheKey);
        if ($cached) {
            return $cached;
        }

        $resp = self::request(
            $server,
            'POST',
            '/api/admin/token',
            [
                'username' => $server['username'],
                'password' => $server['password'],
                'grant_type' => 'password',
            ],
            requiresAuth: false,
            asFormUrlencoded: true
        );

        if (!empty($resp['access_token'])) {
            $token = $resp['access_token'];
            Storage::cacheSet($cacheKey, $token, 7 * 3600);
            return $token;
        }

        return null;
    }

    /**
     * Get user details by username.
     */
    public static function getUser(array $server, string $username): ?array {
        return self::request($server, 'GET', '/api/user/' . rawurlencode($username));
    }

    /**
     * Get paginated list of users.
     */
    public static function getUsers(
        array $server,
        int $offset = 0,
        int $limit = 20,
        ?string $search = null,
        ?string $status = null,
        ?string $admin = null
    ): ?array {
        $query = [
            'offset' => $offset,
            'limit' => $limit,
            'sort' => '-created_at',
        ];
        if (!empty($search)) {
            $query['search'] = $search;
        }
        if (!empty($status)) {
            $query['status'] = $status;
        }
        if (!empty($admin) && $admin !== 'ALL') {
            $query['admin'] = $admin;
        }

        $endpoint = '/api/users?' . http_build_query($query);
        $resp = self::request($server, 'GET', $endpoint);
        return $resp['users'] ?? null;
    }

    /**
    public static string $lastError = '';

    public static function getLastError(): string {
        return self::$lastError;
    }

    /**
     * Create a new user.
     */
    public static function createUser(
        array $server,
        string $username,
        int $dataLimitBytes,
        ?int $expireTimestamp,
        array $inbounds = [],
        array $proxies = [],
        ?string $note = null
    ): ?array {
        // In Marzban, a user must have at least one proxy/inbound protocol configured.
        // If not specified, automatically fetch all available inbounds from the panel.
        if (empty($inbounds) && empty($proxies)) {
            $allInbounds = self::getInbounds($server);
            if (is_array($allInbounds)) {
                $proxies = [];
                $inbounds = [];
                foreach ($allInbounds as $proto => $list) {
                    $proxies[$proto] = new stdClass();
                    $inbounds[$proto] = [];
                    if (is_array($list)) {
                        foreach ($list as $item) {
                            if (!empty($item['tag'])) {
                                $inbounds[$proto][] = $item['tag'];
                            }
                        }
                    }
                }
            }
        }

        $payload = [
            'username' => $username,
            'data_limit' => $dataLimitBytes,
            'expire' => $expireTimestamp ?: 0,
            'inbounds' => !empty($inbounds) ? $inbounds : new stdClass(),
            'proxies' => !empty($proxies) ? $proxies : new stdClass(),
            'status' => 'active',
        ];
        if ($note !== null && $note !== '') {
            $payload['note'] = $note;
        }
        return self::request($server, 'POST', '/api/user', $payload);
    }

    /**
     * Modify existing user data.
     */
    public static function modifyUser(array $server, string $username, array $data): ?array {
        return self::request($server, 'PUT', '/api/user/' . rawurlencode($username), $data);
    }

    /**
     * Toggle user status (active/disabled).
     */
    public static function setStatus(array $server, string $username, bool $active): bool {
        $status = $active ? 'active' : 'disabled';
        $resp = self::request($server, 'PUT', '/api/user/' . rawurlencode($username), ['status' => $status]);
        return !empty($resp);
    }

    /**
     * Reset user usage statistics.
     */
    public static function resetUsage(array $server, string $username): bool {
        $resp = self::request($server, 'POST', '/api/user/' . rawurlencode($username) . '/reset');
        return $resp !== null;
    }

    /**
     * Revoke user subscription (regenerates token).
     */
    public static function revokeSub(array $server, string $username): ?array {
        return self::request($server, 'POST', '/api/user/' . rawurlencode($username) . '/revoke_sub');
    }

    /**
     * Delete user from panel.
     */
    public static function deleteUser(array $server, string $username): bool {
        $resp = self::request($server, 'DELETE', '/api/user/' . rawurlencode($username));
        return $resp !== null;
    }

    /**
     * Get list of panel administrators.
     */
    public static function getAdmins(array $server): ?array {
        $resp = self::request($server, 'GET', '/api/admins');
        return is_array($resp) ? $resp : null;
    }

    /**
     * Set / change user owner admin.
     */
    public static function setOwner(array $server, string $username, string $adminUsername): bool {
        $endpoint = '/api/user/' . rawurlencode($username) . '/set-owner?' . http_build_query([
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
        $resp = self::request($server, 'POST', '/api/admin/' . rawurlencode($adminUsername) . '/users/activate');
        return $resp !== null;
    }

    /**
     * Disable all users belonging to an admin.
     */
    public static function disableUsersByAdmin(array $server, string $adminUsername): bool {
        $resp = self::request($server, 'POST', '/api/admin/' . rawurlencode($adminUsername) . '/users/disable');
        return $resp !== null;
    }

    /**
     * Get inbounds configuration.
     */
    public static function getInbounds(array $server): ?array {
        return self::request($server, 'GET', '/api/inbounds');
    }

    /**
     * Get nodes list and their statuses.
     */
    public static function getNodes(array $server): ?array {
        return self::request($server, 'GET', '/api/nodes');
    }

    /**
     * Restart/Reconnect a node.
     */
    public static function restartNode(array $server, int $nodeId): bool {
        $resp = self::request($server, 'POST', "/api/node/{$nodeId}/reconnect");
        return $resp !== null;
    }

    /**
     * Get panel system statistics.
     */
    public static function getSystemStats(array $server): ?array {
        return self::request($server, 'GET', '/api/system');
    }
}
