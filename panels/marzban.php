<?php
/**
 * HolderBot PHP - Marzban Panel API Client
 *
 * Implements REST calls using native PHP cURL (zero external dependencies).
 */

declare(strict_types=1);
require_once __DIR__ . '/../helpers/request_budget.php';
require_once __DIR__ . '/../helpers/panel_url.php';

class MarzbanClient {
    public static string $lastError = '';
    public static int $lastHttpCode = 0;
    public static ?int $lastUsersTotal = null;
    public static ?Closure $transport = null;

    public static function getLastError(): string {
        return self::$lastError;
    }

    /**
     * Send HTTP request to Marzban API.
     */
    public static function request(
        array $server,
        string $method,
        string $endpoint,
        mixed $payload = null,
        bool $requiresAuth = true,
        bool $asFormUrlencoded = false,
        ?string $bearerOverride = null,
        int $timeoutSeconds = 5
    ): ?array {
        self::$lastError = '';
        self::$lastHttpCode = 0;
        if (self::$transport !== null) return (self::$transport)($server, $method, $endpoint, $payload, $timeoutSeconds);
        $headers = ['Accept: application/json'];

        if ($bearerOverride !== null) {
            $headers[] = "Authorization: Bearer {$bearerOverride}";
        } elseif ($requiresAuth) {
            $token = self::getToken($server);
            if (!$token) {
                error_log("MarzbanClient: Failed to obtain valid token for server [{$server['remark']}]");
                return null;
            }
            $headers[] = "Authorization: Bearer {$token}";
        }

        $baseUrl = PanelUrl::normalize((string)($server['base_url'] ?? ''));
        if ($baseUrl === null) { self::$lastError='Invalid panel URL.'; return null; }
        $url = $baseUrl . $endpoint;

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT_MS => RequestBudget::milliseconds(max(1,$timeoutSeconds)),
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
        self::$lastHttpCode = (int)$httpCode;
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            self::$lastError = "Connection error: " . $err;
            error_log("MarzbanClient cURL error ({$url}): {$err}");
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errData = json_decode($response, true);
            $msg = $errData['detail'] ?? "HTTP {$httpCode}: {$response}";
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            self::$lastError = (string)$msg;
            error_log("MarzbanClient HTTP {$httpCode} ({$url}): {$response}");
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
     * Get or refresh an administrator token and record its access scope.
     */
    public static function getToken(array &$server, bool $force = false): ?string {
        $candidates = PanelUrl::candidates((string)($server['base_url'] ?? ''));
        if ($candidates === []) { self::$lastError = 'Invalid panel URL. Use an HTTP or HTTPS URL without a query or fragment.'; return null; }
        $resolutionKey='marzban_base_' . hash('sha256',json_encode([$candidates[0],$server['username'],$server['password']]));
        $resolved=Storage::cacheGet($resolutionKey);
        if(is_string($resolved) && in_array($resolved,$candidates,true)){$candidates=array_values(array_unique(array_merge([$resolved],$candidates)));}
        $server['base_url'] = $candidates[0];
        $cacheKey = "marzban_token_" . hash('sha256', json_encode([$server['base_url'], $server['username'], $server['password']]));
        $cached = Storage::cacheGet($cacheKey);
        if ($cached && !$force && array_key_exists('panel_is_sudo',$server) && $server['panel_is_sudo'] !== null) {
            return $cached;
        }

        $errors=[];
        foreach ($candidates as $candidateBase) {
        $candidateServer=$server;$candidateServer['base_url']=$candidateBase;
        $resp = self::request(
            $candidateServer,
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

        if (empty($resp['access_token'])) { $errors[]=$candidateBase . ': ' . (self::$lastError ?: 'authentication failed'); continue; }
        $token = $resp['access_token'];

        // Verify sudo privilege via GET /api/admin using the freshly obtained token
        // (passed directly to avoid re-entering getToken()).
        $adminInfo = self::request(
            $candidateServer,
            'GET',
            '/api/admin',
            null,
            requiresAuth: false,
            asFormUrlencoded: false,
            bearerOverride: $token
        );
        if (!is_array($adminInfo) || !array_key_exists('is_sudo',$adminInfo)) { $errors[]=$candidateBase . ': ' . (self::$lastError ?: 'unable to determine administrator access level'); continue; }
        $server['base_url']=$candidateBase;
        $server['panel_admin_username']=(string)($adminInfo['username']??$server['username']);
        $server['panel_is_sudo']=!empty($adminInfo['is_sudo'])?1:0;

        $cacheKey = "marzban_token_" . hash('sha256', json_encode([$server['base_url'], $server['username'], $server['password']]));
        Storage::cacheSet($cacheKey, $token, 8 * 3600);
        Storage::cacheSet($resolutionKey,$server['base_url'],30*86400);
        Storage::cacheSet("online_" . ($server['id'] ?? md5($server['base_url'])), time(), 86400);
        return $token;
        }
        self::$lastError='Panel login failed for the supplied URL paths. ' . implode(' | ',array_slice($errors,-3));
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
        ?string $admin = null,
        int $timeoutSeconds = 5
    ): ?array {
        self::$lastUsersTotal = null;
        $endpoint = self::usersEndpoint($offset, $limit, $search, $status, $admin);
        $resp = self::request($server, 'GET', $endpoint, null, true, false, null, $timeoutSeconds);
        if (isset($resp['total']) && is_numeric($resp['total'])) self::$lastUsersTotal = (int)$resp['total'];
        return $resp['users'] ?? null;
    }

    public static function usersEndpoint(int $offset, int $limit, ?string $search=null, ?string $status=null, ?string $admin=null): string {
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

        return '/api/users?' . http_build_query($query);
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
        ?string $note = null,
        string $status = 'active',
        ?int $onHoldExpireDuration = null
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
                    $items = isset($list['tag']) ? [$list] : (is_array($list) ? $list : []);
                    foreach ($items as $item) {
                        if (is_array($item) && !empty($item['tag'])) {
                            $inbounds[$proto][] = $item['tag'];
                        }
                    }
                }
            }
        }

        $payload = [
            'username' => $username,
            'data_limit' => $dataLimitBytes,
            'inbounds' => !empty($inbounds) ? $inbounds : new stdClass(),
            'proxies' => !empty($proxies) ? $proxies : new stdClass(),
            'status' => $status,
        ];

        if ($status === 'on_hold') {
            $payload['expire'] = null;
            if ($onHoldExpireDuration !== null) {
                $payload['on_hold_expire_duration'] = $onHoldExpireDuration;
            }
        } else {
            $payload['expire'] = ($expireTimestamp && $expireTimestamp > 0) ? $expireTimestamp : null;
        }

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

    public static function createAdmin(array $server,string $username,string $password,bool $sudo): ?array {
        return self::request($server,'POST','/api/admin',['username'=>$username,'password'=>$password,'is_sudo'=>$sudo]);
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
}
