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
        ?string $admin = null,
        bool $strict = false
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
            if ($strict) throw new RuntimeException("Unable to fetch user page");
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
            $strategy = ($dateType === 'unlimited') ? 'never' : (($dateType === 'onhold') ? 'start_on_first_use' : 'fixed_date');
            $expireDate = ($strategy === 'fixed_date') ? gmdate('Y-m-d\TH:i:s\Z', $expireTimestamp ?? 0) : null;
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
     * $dateType: 'fixed' (days from now/current expiry), 'unlimited' (clear
     * expiry entirely), 'onhold' (after first use) - taken from the template
     * being used to recharge, so an "after first use" template actually
     * produces an on-hold recharge instead of silently falling back to a
     * fixed-date one.
     */
    public static function chargeUser(
        array $server,
        string $username,
        float|int $dataLimitGb,
        int $expireDays,
        bool $resetUsage = false,
        bool $additive = false,
        string $dateType = 'fixed',
        ?callable $beforeWrite = null
    ): ?array {
        $user = self::getUser($server, $username);
        if (!$user) return null;
        $payload = self::rechargePayload($server, $username, $user, $dataLimitGb, $expireDays, $additive, $dateType);
        if ($resetUsage) {
            if ($beforeWrite) $beforeWrite('reset_usage', $payload);
            if (!self::resetUsage($server, $username)) return null;
        }
        if ($beforeWrite) $beforeWrite('apply_recharge', $payload);
        $resp = $server['type'] === 'marzneshin'
            ? MarzneshinClient::modifyUser($server, $username, $payload)
            : MarzbanClient::modifyUser($server, $username, $payload);
        return $resp && isset($resp['username']) ? self::normalizeUser($server, $resp) : null;
    }

    /** Absolute desired values can be persisted before a queued recharge. */
    public static function rechargePayload(array $server, string $username, array $user, float|int $dataLimitGb, int $expireDays, bool $additive, string $dateType): array {
        $payload = self::datePayload($server, $username, $expireDays, $dateType);
        $bytes = (int)$dataLimitGb * (1024 ** 3);
        $currentBytes = $user['data_limit_bytes'];
        $payload['data_limit'] = $additive ? ($currentBytes ? $currentBytes + $bytes : 0) : $bytes;
        if ($additive) {
            $raw = $user['raw'];
            $seconds = $expireDays * 86400;
            if ($server['type'] === 'marzban') {
                if ($dateType === 'onhold') $payload['on_hold_expire_duration'] = ($raw['on_hold_expire_duration'] ?? 0) + $seconds;
                else $payload['expire'] = ($raw['expire'] ?? time()) + $seconds;
            } else {
                $remaining = match ($raw['expire_strategy'] ?? 'never') {
                    'fixed_date' => ($user['expire_timestamp'] ?? time()) - time(),
                    'start_on_first_use' => $raw['usage_duration'] ?? 0,
                    default => 0,
                };
                if ($dateType === 'onhold') $payload['usage_duration'] = $remaining + $seconds;
                elseif ($dateType === 'fixed') $payload['expire_date'] = gmdate('Y-m-d\TH:i:s\Z', time() + $remaining + $seconds);
            }
        }
        return $payload;
    }

    /** Payload shared by normal recharge and date-only edits. */
    public static function datePayload(array $server, string $username, int $days, string $type): array {
        if (!in_array($type, ['fixed', 'onhold', 'unlimited'], true)) throw new InvalidArgumentException('Invalid date type');
        if ($server['type'] === 'marzneshin') {
            return [
                'username' => $username,
                'expire_strategy' => ['fixed' => 'fixed_date', 'onhold' => 'start_on_first_use', 'unlimited' => 'never'][$type],
                'expire_date' => $type === 'fixed' ? gmdate('Y-m-d\TH:i:s\Z', $days ? time() + $days * 86400 : 0) : null,
                'usage_duration' => $type === 'onhold' ? $days * 86400 : null,
            ];
        }
        return [
            'status' => $type === 'onhold' ? 'on_hold' : 'active',
            'expire' => $type === 'onhold' ? null : ($type === 'unlimited' ? 0 : ($days ? time() + $days * 86400 : null)),
            'on_hold_expire_duration' => $type === 'onhold' ? $days * 86400 : null,
        ];
    }

    public static function updateDateLimit(array $server, string $username, int $days, string $type = 'fixed'): bool {
        $payload = self::datePayload($server, $username, $days, $type);
        $resp = $server['type'] === 'marzneshin'
            ? MarzneshinClient::modifyUser($server, $username, $payload)
            : MarzbanClient::modifyUser($server, $username, $payload);
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
        return self::updateDateLimit($server, $username, $days, 'fixed');
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
    private static function snapshotUsers(array $server, ?string $status, ?string $admin): array {
        $all = [];
        $size = self::pageSize($server);
        for ($page = 1; ; $page++) {
            $users = self::getUsers($server, $page, $size, null, $status, $admin === 'ALL' ? null : $admin);
            foreach ($users as $user) $all[$user['username']] = $user;
            if (count($users) < $size) break;
        }
        return array_values($all);
    }

    public static function deleteExpiredUsers(array $server, ?string $admin = null): array {
        $users = self::snapshotUsers($server, 'expired', $admin);
        $success = 0;
        $total = count($users);
        foreach ($users as $u) {
            if (self::deleteUser($server, $u['username'])) $success++;
        }
        return ['success' => $success, 'total' => $total];
    }

    /**
     * Delete all limited users.
     */
    public static function deleteLimitedUsers(array $server, ?string $admin = null): array {
        $users = self::snapshotUsers($server, 'limited', $admin);
        $success = 0;
        $total = count($users);
        foreach ($users as $u) {
            if (self::deleteUser($server, $u['username'])) $success++;
        }
        return ['success' => $success, 'total' => $total];
    }

    /**
     * Transfer all users from one admin to another.
     */
    public static function transferUsers(array $server, string $fromAdmin, string $toAdmin): array {
        $users = self::snapshotUsers($server, null, $fromAdmin);
        $success = 0;
        $total = count($users);
        foreach ($users as $u) {
            if (self::setOwner($server, $u['username'], $toAdmin)) $success++;
        }
        return ['success' => $success, 'total' => $total];
    }

    /**
     * Delete all users belonging to an admin.
     */
    public static function deleteAllAdminUsers(array $server, string $admin): array {
        $users = self::snapshotUsers($server, null, $admin);
        $success = 0;
        $total = count($users);
        foreach ($users as $u) {
            if (self::deleteUser($server, $u['username'])) $success++;
        }
        return ['success' => $success, 'total' => $total];
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
    public static function applyConfigToUsers(array $server, $serviceId, bool $add, string $admin = 'ALL'): array {
        $type = strtolower($server['type'] ?? 'marzban');
        $success = 0;
        $total = 0;
        $page = 1;
        $size = self::pageSize($server);
        while (true) {
            $users = self::getUsers($server, $page, $size, null, null, $admin === 'ALL' ? null : $admin);
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
                    $total++;
                    $resp = MarzneshinClient::modifyUser($server, $user['username'], [
                        'username' => $user['username'],
                        'service_ids' => $ids,
                    ]);
                    if ($resp) $success++;
                }
            }
            if (count($users) < $size) break;
            $page++;
        }
        return ['success' => $success, 'total' => $total];
    }

    /**
     * Fetch comprehensive server statistics with complete parity to Python HolderBot.
     */
    public static function statsForUsers(array $server, array $users, int $now, string $botUsername = ''): array {
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
        $todayExpired = [];

            foreach ($users as $u) {
                $total++;
                if ($u['is_enabled'] ?? $u['is_active']) {
                    $active++;
                } else {
                    $disabled++;
                }
                if ($u['raw']['expired'] ?? ($u['status'] === 'expired')) $expired++;
                if ($u['raw']['data_limit_reached'] ?? ($u['status'] === 'limited')) $limited++;

                // Remaining data percent
                if (!empty($u['data_limit_bytes']) && $u['data_limit_bytes'] > 0) {
                    $pctRemaining = max(0, (int)((($u['data_limit_bytes'] - $u['used_traffic_bytes']) / $u['data_limit_bytes']) * 100));
                    if ($pctRemaining <= 1.0) $data1++;
                    if ($pctRemaining <= 10.0) $data10++;
                }

                // Online hour windows
                if (!empty($u['online_at'])) {
                    $hoursAgo = (int)(($now - $u['online_at']) / 3600);
                    if ($hoursAgo !== 0) {
                        if ($hoursAgo < 24) $onlineDay++;
                        if ($hoursAgo < (24 * 7)) $onlineWeek++;
                        if ($hoursAgo < (24 * 31)) $onlineMonth++;
                    }
                }

                // Sub update hour windows
                if (!empty($u['sub_updated_at'])) {
                    $hoursAgo = (int)(($now - $u['sub_updated_at']) / 3600);
                    if ($hoursAgo !== 0) {
                        if ($hoursAgo < 24) $updateDay++;
                        if ($hoursAgo < (24 * 7)) $updateWeek++;
                        if ($hoursAgo < (24 * 31)) $updateMonth++;
                    }
                }

                // Scheduled to expire today: NOT YET expired, and due within the next
                // 24 hours (matches Python's last_expired_hour, which returns None
                // for a user already past their expiry and hours-remaining otherwise).
                if (!empty($u['expire_timestamp']) && ($server['type'] !== 'marzneshin' || ($u['raw']['expire_strategy'] ?? '') === 'fixed_date')) {
                    $hoursUntilExpiry = (int)(($u['expire_timestamp'] - $now) / 3600);
                    if ($hoursUntilExpiry > 0 && $hoursUntilExpiry <= 24) {
                        $todayExpired[] = $botUsername
                            ? "<a href='https://t.me/{$botUsername}?start=user_{$server['id']}_{$u['username']}'> <code>{$u['username']}</code> </a>"
                            : "<code>{$u['username']}</code>";
                    }
                }
            }

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
            'today_expired' => $todayExpired,
        ];
    }

    public static function getServerStats(array $server): array {
        $stats = self::statsForUsers($server, [], time());
        $now = time(); $size = self::pageSize($server); $bot = self::getBotUsername();
        for ($page = 1; ; $page++) {
            $users = self::getUsers($server, $page, $size);
            $part = self::statsForUsers($server, $users, $now, $bot);
            foreach ($part as $key => $value) $stats[$key] = is_array($value) ? array_merge($stats[$key], $value) : $stats[$key] + $value;
            if (count($users) < $size) break;
        }
        return $stats;
    }

    /**
     * Whether the server's credentials were successfully verified (sudo-checked
     * login) within the last 24 hours - the original bot's "is_online" concept,
     * used to gate background jobs against a server that's currently unreachable.
     */
    public static function isOnline(array $server): bool {
        $key = "online_" . ($server['id'] ?? md5($server['base_url']));
        return Storage::cacheGet($key) !== null;
    }

    /**
     * Per-panel page size used for full-table scans (matches the original
     * bot's server.size_value: 100 for Marzneshin, 25 for Marzban).
     */
    public static function pageSize(array $server): int {
        return strtolower($server['type'] ?? 'marzban') === 'marzneshin' ? 100 : 25;
    }

    public static function getBotUsername(): string {
        $cached = Storage::cacheGet('bot_username');
        if ($cached !== null) {
            return $cached;
        }
        $info = tgbot('getMe');
        $username = $info['result']['username'] ?? '';
        Storage::cacheSet('bot_username', $username, 86400);
        return $username;
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
     * Whether a node's status counts as a failure. A node an admin deliberately
     * disabled is not a failure - only a defined set of error states is, matching
     * the original bot (which never alerts/restarts on a "disabled" node).
     */
    public static function isNodeOk(array $node, ?string $type = null): bool {
        $status = strtolower($node['status'] ?? 'unknown');
        $badStatuses = match ($type) {
            'marzban' => ['error', 'connecting'],
            'marzneshin' => ['unhealthy'],
            default => ['error', 'connecting', 'unhealthy'],
        };
        return !in_array($status, $badStatuses, true);
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
    private static function utcTimestamp(string $value): ?int {
        try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp(); }
        catch (Exception) { return null; }
    }

    public static function normalizeUser(array $server, array $raw): array {
        $type = strtolower($server['type'] ?? 'marzban');
        $baseUrl = rtrim($server['base_url'], '/');

        if ($type === 'marzneshin') {
            // "Enabled" is the admin-controlled activated/enabled flag - independent
            // of expiry/limit state (an enabled user can still be expired or
            // limited at the same time). This matches the panel's own separation
            // and keeps stats buckets from conflating the two concepts.
            $isEnabled = array_key_exists('activated', $raw) ? !empty($raw['activated']) : !empty($raw['is_active']);
            $status = $isEnabled ? 'active' : 'disabled';
            if (!empty($raw['expired'])) {
                $status = 'expired';
            } elseif (!empty($raw['data_limit_reached'])) {
                $status = 'limited';
            }

            $expireTs = null;
            if (!empty($raw['expire_date'])) {
                $expireTs = self::utcTimestamp($raw['expire_date']) ?: null;
            }

            $subUrl = $raw['subscription_url'] ?? '';
            if ($subUrl && !str_starts_with($subUrl, 'http')) {
                $subUrl = $baseUrl . $subUrl;
            }

            $onlineAt = !empty($raw['last_online']) ? self::utcTimestamp($raw['last_online']) : (!empty($raw['online_at']) ? self::utcTimestamp($raw['online_at']) : null);
            $subUpdatedAt = !empty($raw['sub_updated_at']) ? self::utcTimestamp($raw['sub_updated_at']) : null;

            return [
                'username'          => $raw['username'],
                'status'            => $status,
                'is_active'         => (bool)($raw['is_active'] ?? $isEnabled),
                'is_enabled'        => $isEnabled,
                'data_limit_bytes'  => (int)($raw['data_limit'] ?? 0),
                'used_traffic_bytes'=> (int)($raw['used_traffic'] ?? 0),
                'lifetime_used_traffic_bytes' => (int)($raw['lifetime_used_traffic'] ?? 0),
                'expire_timestamp'  => $expireTs,
                'subscription_url'  => $subUrl,
                'note'              => $raw['note'] ?? '',
                'created_at'        => !empty($raw['created_at']) ? self::utcTimestamp($raw['created_at']) : null,
                'online_at'         => $onlineAt,
                'sub_updated_at'    => $subUpdatedAt,
                'owner_username'    => $raw['owner_username'] ?? ($raw['admin_username'] ?? ''),
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

        $onlineAt = !empty($raw['online_at']) ? self::utcTimestamp($raw['online_at']) : null;
        $subUpdatedAt = !empty($raw['sub_updated_at']) ? self::utcTimestamp($raw['sub_updated_at']) : null;

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
            'lifetime_used_traffic_bytes' => (int)($raw['lifetime_used_traffic'] ?? 0),
            'expire_timestamp'  => !empty($raw['expire']) ? (int)$raw['expire'] : null,
            'subscription_url'  => $subUrl,
            'note'              => $raw['note'] ?? '',
            'created_at'        => !empty($raw['created_at']) ? self::utcTimestamp($raw['created_at']) : null,
            'online_at'         => $onlineAt,
            'sub_updated_at'    => $subUpdatedAt,
            'owner_username'    => $raw['admin']['username'] ?? ($raw['owner'] ?? ''),
            'service_ids'       => $inboundTags,
            'raw'               => $raw,
        ];
    }
}
