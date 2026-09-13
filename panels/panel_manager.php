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
        ?string $note = null,
        array $selectedConfigs = [],
        string $dateType = 'fixed',
        ?string $admin = null
    ): ?array {
        $type = strtolower($server['type'] ?? 'marzban');
        $bytes = ($dataLimitGb > 0) ? (int)round($dataLimitGb * 1024 * 1024 * 1024) : 0;

        $expireTimestamp = null;
        if ($dateType === 'unlimited') {
            $expireTimestamp = 0;
        } elseif ($dateType === 'onhold') {
            $expireTimestamp = 0;
        } elseif ($expireDays > 0) {
            $expireTimestamp = time() + ($expireDays * 86400);
        }

        if ($type === 'marzneshin') {
            $strategy = ($dateType === 'unlimited') ? 'never' : (($dateType === 'onhold') ? 'start_on_first_use' : ($expireTimestamp ? 'fixed_date' : 'never'));
            $expireDate = ($strategy === 'fixed_date' && $expireTimestamp) ? gmdate('Y-m-d\TH:i:s\Z', $expireTimestamp) : null;
            $usageDuration = ($strategy === 'start_on_first_use') ? ($expireDays * 86400) : null;
            $serviceIds = !empty($selectedConfigs) ? array_map('intval', $selectedConfigs) : [];

            if (empty($serviceIds)) {
                $services = MarzneshinClient::getServices($server);
                if (!empty($services)) {
                    $serviceIds = array_column($services, 'id');
                }
            }

            $payload = [
                'username' => $username,
                'data_limit' => $bytes,
                'service_ids' => $serviceIds,
                'expire_strategy' => $strategy,
                'expire_date' => $expireDate,
                'usage_duration' => $usageDuration,
            ];
            if ($note !== null && $note !== '') {
                $payload['note'] = $note;
            }
            $resp = MarzneshinClient::request($server, 'POST', '/api/users', $payload);
        } else {
            // Marzban
            $inbounds = [];
            $proxies = [];
            if (!empty($selectedConfigs)) {
                $allInbounds = MarzbanClient::getInbounds($server);
                if (is_array($allInbounds)) {
                    foreach ($allInbounds as $proto => $list) {
                        $items = isset($list['tag']) ? [$list] : (is_array($list) ? $list : []);
                        foreach ($items as $item) {
                            if (is_array($item) && !empty($item['tag']) && in_array($item['tag'], $selectedConfigs)) {
                                if (!isset($proxies[$proto])) {
                                    $proxies[$proto] = new stdClass();
                                    $inbounds[$proto] = [];
                                }
                                $inbounds[$proto][] = $item['tag'];
                            }
                        }
                    }
                }
            }

            $status = ($dateType === 'onhold') ? 'on_hold' : 'active';
            $onHoldDuration = ($dateType === 'onhold') ? ($expireDays * 86400) : null;
            $resp = MarzbanClient::createUser($server, $username, $bytes, $expireTimestamp, $inbounds, $proxies, $note, $status, $onHoldDuration);
        }

        if (!$resp || empty($resp['username'])) {
            return null;
        }

        if (!empty($admin)) {
            self::setOwner($server, $resp['username'], $admin);
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
        bool $resetUsage = false,
        bool $additive = false
    ): ?array {
        $user = self::getUser($server, $username);
        if (!$user) {
            return null;
        }

        $type = strtolower($server['type'] ?? 'marzban');
        $addedBytes = ($dataLimitGb > 0) ? (int)round($dataLimitGb * 1024 * 1024 * 1024) : 0;
        if ($additive) {
            $bytes = $addedBytes + (int)($user['data_limit_bytes'] ?? 0);
        } else {
            $bytes = $addedBytes;
        }

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
     * Update only the date limit / expire strategy for a user.
     * $type: 'fixed' (days from now), 'unlimited' (remove expiry), 'onhold' (after first use)
     */
    public static function updateDateLimit(array $server, string $username, int $days, string $type = 'fixed'): bool {
        $serverType = strtolower($server['type'] ?? 'marzban');

        if ($serverType === 'marzneshin') {
            if ($type === 'unlimited') {
                $payload = ['expire_strategy' => 'never', 'expire_date' => null, 'usage_duration' => null];
            } elseif ($type === 'onhold') {
                $payload = ['expire_strategy' => 'start_on_first_use', 'usage_duration' => $days * 86400, 'expire_date' => null];
            } else {
                $expireDate = ($days > 0) ? gmdate('Y-m-d\TH:i:s\Z', time() + $days * 86400) : null;
                $payload = ['expire_strategy' => ($days > 0 ? 'fixed_date' : 'never'), 'expire_date' => $expireDate, 'usage_duration' => null];
            }
            $resp = MarzneshinClient::modifyUser($server, $username, $payload);
        } else {
            // Marzban
            if ($type === 'unlimited') {
                $payload = ['expire' => 0];
            } elseif ($type === 'onhold') {
                $payload = ['status' => 'on_hold', 'on_hold_expire_duration' => $days * 86400];
            } else {
                $payload = ['expire' => ($days > 0 ? time() + $days * 86400 : 0)];
            }
            $resp = MarzbanClient::modifyUser($server, $username, $payload);
        }

        return $resp !== null;
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
            if (count($users) < 50) break;
            $page++;
        }
        return $count;
    }

    /**
     * Delete all users belonging to an admin.
     */
    public static function deleteAllAdminUsers(array $server, string $admin): int {
        $count = 0;
        $page = 1;
        while (true) {
            $users = self::getUsers($server, $page, 50, null, null, $admin === 'ALL' ? null : $admin);
            if (empty($users)) {
                break;
            }
            foreach ($users as $u) {
                if (self::deleteUser($server, $u['username'])) {
                    $count++;
                }
            }
            if (count($users) < 50) break;
            $page++;
        }
        return $count;
    }

    /**
     * Get available services/configs from the panel.
     */
    public static function getServices(array $server): array {
        $type = strtolower($server['type'] ?? 'marzban');
        if ($type === 'marzneshin') {
            $services = MarzneshinClient::getServices($server);
            return is_array($services) ? $services : [];
        } else {
            $allInbounds = MarzbanClient::getInbounds($server);
            if (!is_array($allInbounds)) return [];
            $result = [];
            foreach ($allInbounds as $proto => $list) {
                $items = isset($list['tag']) ? [$list] : (is_array($list) ? $list : []);
                foreach ($items as $item) {
                    if (is_array($item) && !empty($item['tag'])) {
                        $result[] = [
                            'id' => $item['tag'],
                            'name' => $item['tag'],
                            'remark' => $item['tag'],
                            'protocol' => $proto,
                        ];
                    }
                }
            }
            return $result;
        }
    }

    /**
     * Update user configs/inbounds.
     */
    public static function updateUserConfigs(array $server, string $username, array $serviceIds): bool {
        $type = strtolower($server['type'] ?? 'marzban');
        if ($type === 'marzneshin') {
            $resp = MarzneshinClient::modifyUser($server, $username, [
                'username' => $username,
                'service_ids' => array_values(array_map('intval', $serviceIds)),
            ]);
            return $resp !== null;
        } else {
            $allInbounds = MarzbanClient::getInbounds($server);
            $proxies = [];
            $inbounds = [];
            if (is_array($allInbounds)) {
                foreach ($allInbounds as $proto => $list) {
                    $items = isset($list['tag']) ? [$list] : (is_array($list) ? $list : []);
                    foreach ($items as $item) {
                        if (is_array($item) && !empty($item['tag']) && in_array($item['tag'], $serviceIds)) {
                            if (!isset($proxies[$proto])) {
                                $proxies[$proto] = new stdClass();
                                $inbounds[$proto] = [];
                            }
                            $inbounds[$proto][] = $item['tag'];
                        }
                    }
                }
            }
            if (empty($proxies)) return false;
            $resp = MarzbanClient::modifyUser($server, $username, [
                'proxies' => $proxies,
                'inbounds' => $inbounds,
            ]);
            return $resp !== null;
        }
    }

    /**
     * Add or remove a config across users of an admin.
     */
    public static function applyConfigToUsers(array $server, $serviceId, bool $add, string $admin = 'ALL'): int {
        $type = strtolower($server['type'] ?? 'marzban');
        $success = 0;
        $page = 1;
        while (true) {
            $users = self::getUsers($server, $page, 50, null, null, $admin === 'ALL' ? null : $admin);
            if (empty($users)) break;
            foreach ($users as $user) {
                if ($type === 'marzneshin') {
                    $ids = $user['service_ids'] ?? [];
                    if ($add && !in_array((int)$serviceId, $ids)) {
                        $ids[] = (int)$serviceId;
                    } elseif (!$add && in_array((int)$serviceId, $ids)) {
                        $ids = array_values(array_filter($ids, fn($id) => (int)$id !== (int)$serviceId));
                    } else {
                        continue;
                    }
                    $resp = MarzneshinClient::modifyUser($server, $user['username'], [
                        'username' => $user['username'],
                        'service_ids' => $ids,
                    ]);
                    if ($resp) $success++;
                }
            }
            if (count($users) < 50) break;
            $page++;
        }
        return $success;
    }

    /**
     * Fetch comprehensive server statistics with complete parity to Python HolderBot.
     */
    public static function getServerStats(array $server): array {
        $total = 0;
        $active = 0;
        $disabled = 0;
        $expired = 0;
        $limited = 0;
        $data1 = 0;
        $data10 = 0;
        $onlineDay = 0;
        $onlineWeek = 0;
        $onlineMonth = 0;
        $updateDay = 0;
        $updateWeek = 0;
        $updateMonth = 0;
        $totalTraffic = 0;
        $todayExpired = [];

        $now = time();
        $page = 1;

        while ($page <= 10) { // paginate up to 500 users for responsive dashboard
            $users = self::getUsers($server, $page, 50);
            if (empty($users)) break;

            foreach ($users as $u) {
                $total++;
                if ($u['is_active']) {
                    $active++;
                } else {
                    $disabled++;
                }
                if ($u['status'] === 'expired') $expired++;
                if ($u['status'] === 'limited') $limited++;

                $totalTraffic += $u['used_traffic_bytes'];

                // Remaining data percent
                if (!empty($u['data_limit_bytes']) && $u['data_limit_bytes'] > 0) {
                    $pctRemaining = (1.0 - ($u['used_traffic_bytes'] / $u['data_limit_bytes'])) * 100.0;
                    if ($pctRemaining <= 1.0) $data1++;
                    if ($pctRemaining <= 10.0) $data10++;
                }

                // Online hour windows
                if (!empty($u['online_at'])) {
                    $hoursAgo = ($now - $u['online_at']) / 3600.0;
                    if ($hoursAgo >= 0) {
                        if ($hoursAgo < 24) $onlineDay++;
                        if ($hoursAgo < (24 * 7)) $onlineWeek++;
                        if ($hoursAgo < (24 * 31)) $onlineMonth++;
                    }
                }

                // Sub update hour windows
                if (!empty($u['sub_updated_at'])) {
                    $hoursAgo = ($now - $u['sub_updated_at']) / 3600.0;
                    if ($hoursAgo >= 0) {
                        if ($hoursAgo < 24) $updateDay++;
                        if ($hoursAgo < (24 * 7)) $updateWeek++;
                        if ($hoursAgo < (24 * 31)) $updateMonth++;
                    }
                }

                // Expired in 24 hours
                if (!empty($u['expire_timestamp'])) {
                    $hoursToExpire = ($u['expire_timestamp'] - $now) / 3600.0;
                    if ($hoursToExpire >= 0 && $hoursToExpire <= 24) {
                        $todayExpired[] = "<code>{$u['username']}</code>";
                    }
                }
            }

            if (count($users) < 50) break;
            $page++;
        }

        $type = strtolower($server['type'] ?? 'marzban');
        $system = ($type === 'marzban') ? MarzbanClient::getSystemStats($server) : null;

        return [
            'total_users'   => $total,
            'active_users'  => $active,
            'disabled_users'=> $disabled,
            'expired_users' => $expired,
            'limited_users' => $limited,
            'data_1'        => $data1,
            'data_10'       => $data10,
            'online_day'    => $onlineDay,
            'online_week'   => $onlineWeek,
            'online_month'  => $onlineMonth,
            'update_day'    => $updateDay,
            'update_week'   => $updateWeek,
            'update_month'  => $updateMonth,
            'today_expired' => array_slice($todayExpired, 0, 10),
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

            $onlineAt = !empty($raw['last_online']) ? strtotime($raw['last_online']) : (!empty($raw['online_at']) ? strtotime($raw['online_at']) : null);
            $subUpdatedAt = !empty($raw['sub_updated_at']) ? strtotime($raw['sub_updated_at']) : null;

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
                'online_at'         => $onlineAt,
                'sub_updated_at'    => $subUpdatedAt,
                'owner_username'    => $raw['admin_username'] ?? ($raw['admin'] ?? ''),
                'service_ids'       => $raw['service_ids'] ?? [],
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

        $onlineAt = !empty($raw['online_at']) ? strtotime($raw['online_at']) : null;
        $subUpdatedAt = !empty($raw['sub_updated_at']) ? strtotime($raw['sub_updated_at']) : null;

        // Inbounds / service_ids for Marzban: extract inbound tags
        $inboundTags = [];
        if (!empty($raw['inbounds']) && is_array($raw['inbounds'])) {
            foreach ($raw['inbounds'] as $proto => $tags) {
                if (is_array($tags)) {
                    foreach ($tags as $t) $inboundTags[] = $t;
                }
            }
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
            'online_at'         => $onlineAt,
            'sub_updated_at'    => $subUpdatedAt,
            'owner_username'    => $raw['admin'] ?? ($raw['owner'] ?? ''),
            'service_ids'       => $inboundTags,
            'raw'               => $raw,
        ];
    }
}
