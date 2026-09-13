<?php
/**
 * HolderBot PHP - Unified Panel Manager
 *
 * Normalizes differences between Marzban and Marzneshin panels.
 */

declare(strict_types=1);

require_once __DIR__ . '/marzban.php';
require_once __DIR__ . '/marzneshin.php';

class PanelManager {
    /**
     * Get the last error from panel client.
     */
    public static function getLastError(array $server): string {
        $type = strtolower($server['type'] ?? 'marzban');
        return ($type === 'marzneshin')
            ? MarzneshinClient::getLastError()
            : MarzbanClient::getLastError();
    }

    /**
     * Get a single user.
     */
    public static function getUser(array $server, string $username): ?array {
        $type = strtolower($server['type'] ?? 'marzban');
        $raw = ($type === 'marzneshin')
            ? MarzneshinClient::getUser($server, $username)
            : MarzbanClient::getUser($server, $username);

        if (!$raw || empty($raw['username'])) {
            return null;
        }

        return self::normalizeUser($server, $raw);
    }

    /**
     * Get a paginated list of users with optional status and admin filters.
     */
    public static function getUsers(
        array $server,
        int $page = 1,
        int $limit = 20,
        ?string $search = null,
        ?string $status = null,
        ?string $admin = null
    ): array {
        $type = strtolower($server['type'] ?? 'marzban');
        if ($type === 'marzneshin') {
            $expired = ($status === 'expired') ? true : null;
            $limited = ($status === 'limited') ? true : null;
            $rawList = MarzneshinClient::getUsers($server, $page, $limit, $search, $status, $admin, $expired, $limited);
        } else {
            $offset = ($page - 1) * $limit;
            $rawList = MarzbanClient::getUsers($server, $offset, $limit, $search, $status, $admin);
        }

        if (!is_array($rawList)) {
            return [];
        }

        $users = [];
        foreach ($rawList as $raw) {
            $users[] = self::normalizeUser($server, $raw);
        }
        return $users;
    }

    /**
     * Create a user with specified data limit (GB) and duration (days).
     */
    public static function createUser(
        array $server,
        string $username,
        float|int $dataLimitGb,
        int $expireDays,
        ?string $note = null
    ): ?array {
        $type = strtolower($server['type'] ?? 'marzban');
        $bytes = ($dataLimitGb > 0) ? (int)round($dataLimitGb * 1024 * 1024 * 1024) : 0;
        $expireTimestamp = ($expireDays > 0) ? (time() + ($expireDays * 86400)) : null;

        if ($type === 'marzneshin') {
            $resp = MarzneshinClient::createUser($server, $username, $bytes, $expireTimestamp, [], $note);
        } else {
            $resp = MarzbanClient::createUser($server, $username, $bytes, $expireTimestamp, [], [], $note);
        }

        if (!$resp || empty($resp['username'])) {
            return null;
        }

        return self::normalizeUser($server, $resp);
    }

    /**
     * Bulk create N users with prefix.
     */
    public static function bulkCreateUsers(
        array $server,
        int $count,
        string $prefix,
        float|int $dataLimitGb,
        int $expireDays
    ): array {
        $created = [];
        for ($i = 1; $i <= $count; $i++) {
            $randomSuffix = substr(bin2hex(random_bytes(3)), 0, 4);
            $username = "{$prefix}_{$randomSuffix}";
            $user = self::createUser($server, $username, $dataLimitGb, $expireDays);
            if ($user) {
                $created[] = $user;
            }
        }
        return $created;
    }

    /**
     * Toggle user status (active/disabled).
     */
    public static function setStatus(array $server, string $username, bool $active): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        return ($type === 'marzneshin')
            ? MarzneshinClient::setStatus($server, $username, $active)
            : MarzbanClient::setStatus($server, $username, $active);
    }

    /**
     * Reset user usage.
     */
    public static function resetUsage(array $server, string $username): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        return ($type === 'marzneshin')
            ? MarzneshinClient::resetUsage($server, $username)
            : MarzbanClient::resetUsage($server, $username);
    }

    /**
     * Revoke user subscription link.
     */
    public static function revokeSub(array $server, string $username): ?array {
        $type = strtolower($server['type'] ?? 'marzban');
        $raw = ($type === 'marzneshin')
            ? MarzneshinClient::revokeSub($server, $username)
            : MarzbanClient::revokeSub($server, $username);

        if (!$raw || empty($raw['username'])) {
            return null;
        }

        return self::normalizeUser($server, $raw);
    }

    /**
     * Delete user from server.
     */
    public static function deleteUser(array $server, string $username): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        return ($type === 'marzneshin')
            ? MarzneshinClient::deleteUser($server, $username)
            : MarzbanClient::deleteUser($server, $username);
    }

    /**
     * Recharge user subscription (adds/replaces data limit & expiration).
     */
    public static function chargeUser(
        array $server,
        string $username,
        float|int $dataLimitGb,
        int $expireDays,
        bool $resetUsage = false
    ): ?array {
        $user = self::getUser($server, $username);
        if (!$user) {
            return null;
        }

        $type = strtolower($server['type'] ?? 'marzban');
        $bytes = ($dataLimitGb > 0) ? (int)round($dataLimitGb * 1024 * 1024 * 1024) : 0;

        // Calculate new expiration: if current user not expired, extend from current expire; otherwise from now
        $now = time();
        $currentExpire = $user['expire_timestamp'] ?? 0;
        $baseTime = ($currentExpire > $now) ? $currentExpire : $now;
        $newExpireTs = ($expireDays > 0) ? ($baseTime + ($expireDays * 86400)) : null;

        if ($type === 'marzneshin') {
            $payload = [
                'data_limit' => $bytes,
                'is_active' => true,
            ];
            if ($newExpireTs !== null) {
                $payload['expire_strategy'] = 'fixed_date';
                $payload['expire_date'] = gmdate('Y-m-d\TH:i:s\Z', $newExpireTs);
            }
            MarzneshinClient::modifyUser($server, $username, $payload);
            MarzneshinClient::setStatus($server, $username, true);
        } else {
            $payload = [
                'data_limit' => $bytes,
                'status' => 'active',
                'expire' => $newExpireTs,
            ];
            MarzbanClient::modifyUser($server, $username, $payload);
        }

        if ($resetUsage) {
            self::resetUsage($server, $username);
        }

        return self::getUser($server, $username);
    }

    /**
     * Modify user's data limit directly.
     */
    public static function modifyUserDataLimit(array $server, string $username, float|int $dataLimitGb): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        $bytes = ($dataLimitGb > 0) ? (int)round($dataLimitGb * 1024 * 1024 * 1024) : 0;

        $payload = ['data_limit' => $bytes];
        $resp = ($type === 'marzneshin')
            ? MarzneshinClient::modifyUser($server, $username, $payload)
            : MarzbanClient::modifyUser($server, $username, $payload);

        return $resp !== null;
    }

    /**
     * Modify user's expiration date directly (in days from now).
     */
    public static function modifyUserDateLimit(array $server, string $username, int $days): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        $newExpireTs = ($days > 0) ? (time() + ($days * 86400)) : 0;

        if ($type === 'marzneshin') {
            $payload = [
                'expire_strategy' => ($days > 0) ? 'fixed_date' : 'never',
                'expire_date' => ($days > 0) ? gmdate('Y-m-d\TH:i:s\Z', $newExpireTs) : null,
            ];
            $resp = MarzneshinClient::modifyUser($server, $username, $payload);
        } else {
            $payload = ['expire' => $newExpireTs];
            $resp = MarzbanClient::modifyUser($server, $username, $payload);
        }

        return $resp !== null;
    }

    /**
     * Modify user's note.
     */
    public static function modifyUserNote(array $server, string $username, string $note): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        $payload = ['note' => $note];
        $resp = ($type === 'marzneshin')
            ? MarzneshinClient::modifyUser($server, $username, $payload)
            : MarzbanClient::modifyUser($server, $username, $payload);

        return $resp !== null;
    }

    /**
     * Get list of admin usernames.
     */
    public static function getAdmins(array $server): array {
        $type = strtolower($server['type'] ?? 'marzban');
        $raw = ($type === 'marzneshin')
            ? MarzneshinClient::getAdmins($server)
            : MarzbanClient::getAdmins($server);

        if (!is_array($raw)) {
            return [];
        }

        $admins = [];
        foreach ($raw as $adm) {
            if (!empty($adm['username'])) {
                $admins[] = $adm['username'];
            }
        }
        return $admins;
    }

    /**
     * Set user owner admin.
     */
    public static function setOwner(array $server, string $username, string $admin): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        return ($type === 'marzneshin')
            ? MarzneshinClient::setOwner($server, $username, $admin)
            : MarzbanClient::setOwner($server, $username, $admin);
    }

    /**
     * Activate all users under an admin.
     */
    public static function activateAdminUsers(array $server, string $admin): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        return ($type === 'marzneshin')
            ? MarzneshinClient::activateUsersByAdmin($server, $admin)
            : MarzbanClient::activateUsersByAdmin($server, $admin);
    }

    /**
     * Disable all users under an admin.
     */
    public static function disableAdminUsers(array $server, string $admin): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        return ($type === 'marzneshin')
            ? MarzneshinClient::disableUsersByAdmin($server, $admin)
            : MarzbanClient::disableUsersByAdmin($server, $admin);
    }

    /**
     * Delete all expired users.
     */
    public static function deleteExpiredUsers(array $server, ?string $admin = null): int {
        $count = 0;
        $page = 1;
        while (true) {
            $users = self::getUsers($server, $page, 50, null, 'expired', $admin);
            if (empty($users)) {
                break;
            }
            foreach ($users as $u) {
                if (self::deleteUser($server, $u['username'])) {
                    $count++;
                }
            }
            $page++;
        }
        return $count;
    }

    /**
     * Delete all limited users.
     */
    public static function deleteLimitedUsers(array $server, ?string $admin = null): int {
        $count = 0;
        $page = 1;
        while (true) {
            $users = self::getUsers($server, $page, 50, null, 'limited', $admin);
            if (empty($users)) {
                break;
            }
            foreach ($users as $u) {
                if (self::deleteUser($server, $u['username'])) {
                    $count++;
                }
            }
            $page++;
        }
        return $count;
    }

    /**
     * Transfer all users from one admin to another.
     */
    public static function transferUsers(array $server, string $fromAdmin, string $toAdmin): int {
        $count = 0;
        $page = 1;
        while (true) {
            $users = self::getUsers($server, $page, 50, null, null, $fromAdmin);
            if (empty($users)) {
                break;
            }
            foreach ($users as $u) {
                if (self::setOwner($server, $u['username'], $toAdmin)) {
                    $count++;
                }
            }
            $page++;
        }
        return $count;
    }

    /**
     * Fetch comprehensive server statistics.
     */
    public static function getServerStats(array $server): array {
        $users = self::getUsers($server, 1, 500); // sample first 500 users for metrics
        $total = count($users);
        $active = 0;
        $disabled = 0;
        $expired = 0;
        $limited = 0;
        $onlineDay = 0;
        $totalTraffic = 0;

        $now = time();
        foreach ($users as $u) {
            if ($u['status'] === 'active') $active++;
            if ($u['status'] === 'disabled') $disabled++;
            if ($u['status'] === 'expired') $expired++;
            if ($u['status'] === 'limited') $limited++;
            $totalTraffic += $u['used_traffic_bytes'];
        }

        $type = strtolower($server['type'] ?? 'marzban');
        $system = ($type === 'marzban') ? MarzbanClient::getSystemStats($server) : null;

        return [
            'total_users'   => $total,
            'active_users'  => $active,
            'disabled_users'=> $disabled,
            'expired_users' => $expired,
            'limited_users' => $limited,
            'total_traffic' => $totalTraffic,
            'system'        => $system,
        ];
    }

    /**
     * Get nodes status.
     */
    public static function getNodes(array $server): array {
        $type = strtolower($server['type'] ?? 'marzban');
        $raw = ($type === 'marzneshin')
            ? MarzneshinClient::getNodes($server)
            : MarzbanClient::getNodes($server);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Restart/Resync node.
     */
    public static function restartNode(array $server, int $nodeId): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        return ($type === 'marzneshin')
            ? MarzneshinClient::restartNode($server, $nodeId)
            : MarzbanClient::restartNode($server, $nodeId);
    }

    /**
     * Normalize panel-specific raw user data into standard array.
     */
    public static function normalizeUser(array $server, array $raw): array {
        $type = strtolower($server['type'] ?? 'marzban');
        $baseUrl = rtrim($server['base_url'], '/');

        if ($type === 'marzneshin') {
            $isActive = !empty($raw['is_active']);
            $status = $isActive ? 'active' : 'disabled';
            if (!empty($raw['expired'])) {
                $status = 'expired';
            } elseif (!empty($raw['data_limit_reached'])) {
                $status = 'limited';
            }

            $expireTs = null;
            if (!empty($raw['expire_date'])) {
                $expireTs = strtotime($raw['expire_date']) ?: null;
            }

            $subUrl = $raw['subscription_url'] ?? '';
            if ($subUrl && !str_starts_with($subUrl, 'http')) {
                $subUrl = $baseUrl . $subUrl;
            }

            return [
                'username'          => $raw['username'],
                'status'            => $status,
                'is_active'         => $isActive,
                'data_limit_bytes'  => (int)($raw['data_limit'] ?? 0),
                'used_traffic_bytes'=> (int)($raw['used_traffic'] ?? 0),
                'expire_timestamp'  => $expireTs,
                'subscription_url'  => $subUrl,
                'note'              => $raw['note'] ?? '',
                'created_at'        => !empty($raw['created_at']) ? strtotime($raw['created_at']) : null,
                'raw'               => $raw,
            ];
        }

        // Marzban normalization
        $status = strtolower($raw['status'] ?? 'active');
        $isActive = in_array($status, ['active', 'on_hold']);
        $subUrl = $raw['subscription_url'] ?? '';
        if ($subUrl && !str_starts_with($subUrl, 'http')) {
            $subUrl = $baseUrl . $subUrl;
        }

        return [
            'username'          => $raw['username'],
            'status'            => $status,
            'is_active'         => $isActive,
            'data_limit_bytes'  => (int)($raw['data_limit'] ?? 0),
            'used_traffic_bytes'=> (int)($raw['used_traffic'] ?? 0),
            'expire_timestamp'  => !empty($raw['expire']) ? (int)$raw['expire'] : null,
            'subscription_url'  => $subUrl,
            'note'              => $raw['note'] ?? '',
            'created_at'        => !empty($raw['created_at']) ? strtotime($raw['created_at']) : null,
            'raw'               => $raw,
        ];
    }
}
