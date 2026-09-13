<?php
/**
 * HolderBot PHP - Inline Keyboard Builders
 */

declare(strict_types=1);

class Keyboards {
    /**
     * Build Main Menu Keyboard.
     */
    public static function home(array $servers): array {
        $inlineKeyboard = [];

        $serverButtons = [];
        foreach ($servers as $s) {
            $activePrefix = !empty($s['is_active']) ? '🟢 ' : '🔴 ';
            $serverButtons[] = [
                'text' => $activePrefix . $s['remark'],
                'callback_data' => "srv:{$s['id']}",
            ];
        }

        $chunks = array_chunk($serverButtons, 2);
        foreach ($chunks as $row) {
            $inlineKeyboard[] = $row;
        }

        $inlineKeyboard[] = [
            ['text' => '🗃 Templates', 'callback_data' => 'tmpls'],
            ['text' => '➕ Add Server', 'callback_data' => 'add_srv'],
        ];

        $inlineKeyboard[] = [
            ['text' => '👀 Check Update', 'callback_data' => 'check_update'],
        ];

        return ['inline_keyboard' => $inlineKeyboard];
    }

    /**
     * Server Management Menu Keyboard.
     */
    public static function serverMenu(int $serverId): array {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '👥 Users List', 'callback_data' => "users:{$serverId}:1:all"],
                    ['text' => '🔍 Search User', 'callback_data' => "srch_usr:{$serverId}"],
                ],
                [
                    ['text' => '➕ Create User', 'callback_data' => "new_usr:{$serverId}"],
                    ['text' => '📦 Bulk Create', 'callback_data' => "bulk_usr:{$serverId}"],
                ],
                [
                    ['text' => '⚡ Actions', 'callback_data' => "act_menu:{$serverId}"],
                    ['text' => '📊 Statistics', 'callback_data' => "stats:{$serverId}"],
                ],
                [
                    ['text' => '📡 Node Health', 'callback_data' => "nodes:{$serverId}"],
                    ['text' => '⚙️ Settings', 'callback_data' => "srv_cfg:{$serverId}"],
                ],
                [
                    ['text' => '« Back to Home', 'callback_data' => 'home'],
                ],
            ],
        ];
    }

    /**
     * Server Settings / Configuration Keyboard.
     */
    public static function serverSettings(array $server): array {
        $id = $server['id'];
        $actText = !empty($server['is_active']) ? '🟢 Enabled' : '🔴 Disabled';
        $monText = !empty($server['node_monitoring']) ? '🟢 Monitor: ON' : '🔴 Monitor: OFF';
        $resText = !empty($server['node_restart']) ? '🟢 Restart: ON' : '🔴 Restart: OFF';
        $expText = !empty($server['expired_stats']) ? '🟢 Expired Report: ON' : '🔴 Expired Report: OFF';

        return [
            'inline_keyboard' => [
                [
                    ['text' => "Status: {$actText}", 'callback_data' => "tgl_srv_act:{$id}"],
                ],
                [
                    ['text' => $monText, 'callback_data' => "tgl_srv_mon:{$id}"],
                    ['text' => $resText, 'callback_data' => "tgl_srv_res:{$id}"],
                ],
                [
                    ['text' => $expText, 'callback_data' => "tgl_srv_exp:{$id}"],
                ],
                [
                    ['text' => '✏️ Edit Remark', 'callback_data' => "srv_edit_remark:{$id}"],
                    ['text' => '🔑 Edit Credentials', 'callback_data' => "srv_edit_creds:{$id}"],
                ],
                [
                    ['text' => '🗑️ Delete Server', 'callback_data' => "del_srv_ask:{$id}"],
                ],
                [
                    ['text' => '« Server Menu', 'callback_data' => "srv:{$id}"],
                ],
            ],
        ];
    }

    /**
     * Paginated Users List Keyboard with Status Filters.
     */
    public static function usersList(
        int $serverId,
        array $users,
        int $page = 1,
        bool $hasMore = false,
        string $filter = 'all'
    ): array {
        $inlineKeyboard = [];

        // Filter buttons row
        $inlineKeyboard[] = [
            ['text' => ($filter === 'all' ? '🔘 All' : 'All'), 'callback_data' => "users:{$serverId}:1:all"],
            ['text' => ($filter === 'active' ? '🔘 Active' : 'Active'), 'callback_data' => "users:{$serverId}:1:active"],
            ['text' => ($filter === 'expired' ? '🔘 Expired' : 'Expired'), 'callback_data' => "users:{$serverId}:1:expired"],
            ['text' => ($filter === 'limited' ? '🔘 Limited' : 'Limited'), 'callback_data' => "users:{$serverId}:1:limited"],
        ];

        // User list rows
        foreach ($users as $u) {
            $statusEmoji = match ($u['status']) {
                'active' => '🟢',
                'disabled' => '🔴',
                'expired' => '⏱️',
                'limited' => '🚫',
                default => '⚪',
            };
            $inlineKeyboard[] = [
                [
                    'text' => "{$statusEmoji} {$u['username']}",
                    'callback_data' => "usr:{$serverId}:" . substr($u['username'], 0, 32),
                ],
            ];
        }

        // Pagination buttons
        $navRow = [];
        if ($page > 1) {
            $prevPage = $page - 1;
            $navRow[] = ['text' => '⬅️ Prev', 'callback_data' => "users:{$serverId}:{$prevPage}:{$filter}"];
        }
        $navRow[] = ['text' => "Page {$page}", 'callback_data' => 'noop'];
        if ($hasMore) {
            $nextPage = $page + 1;
            $navRow[] = ['text' => 'Next ➡️', 'callback_data' => "users:{$serverId}:{$nextPage}:{$filter}"];
        }
        $inlineKeyboard[] = $navRow;

        // Navigation
        $inlineKeyboard[] = [
            ['text' => '➕ Create User', 'callback_data' => "new_usr:{$serverId}"],
            ['text' => '« Server Menu', 'callback_data' => "srv:{$serverId}"],
        ];

        return ['inline_keyboard' => $inlineKeyboard];
    }

    /**
     * User Action Buttons Keyboard.
     */
    public static function userActions(int $serverId, string $username, bool $isActive, string $status): array {
        $toggleText = $isActive ? '❌ Disable' : '✅ Activate';
        $shortUser = substr($username, 0, 30);

        return [
            'inline_keyboard' => [
                [
                    ['text' => $toggleText, 'callback_data' => "act:{$serverId}:{$shortUser}:tgl_ask"],
                    ['text' => '🧪 Recharge', 'callback_data' => "act:{$serverId}:{$shortUser}:chg"],
                ],
                [
                    ['text' => '📊 Data Limit', 'callback_data' => "act:{$serverId}:{$shortUser}:dl"],
                    ['text' => '⏱️ Date Limit', 'callback_data' => "act:{$serverId}:{$shortUser}:dt"],
                ],
                [
                    ['text' => '📂 Configs', 'callback_data' => "act:{$serverId}:{$shortUser}:cfg"],
                    ['text' => '🗒️ Note', 'callback_data' => "act:{$serverId}:{$shortUser}:nt"],
                ],
                [
                    ['text' => '👤 Set Owner', 'callback_data' => "act:{$serverId}:{$shortUser}:own"],
                    ['text' => '🔁 Reset Usage', 'callback_data' => "act:{$serverId}:{$shortUser}:rst_ask"],
                ],
                [
                    ['text' => '⛓️ Revoke Sub', 'callback_data' => "act:{$serverId}:{$shortUser}:rvk_ask"],
                    ['text' => '🖼️ QR Code', 'callback_data' => "act:{$serverId}:{$shortUser}:qr"],
                ],
                [
                    ['text' => '🗑️ Delete User', 'callback_data' => "act:{$serverId}:{$shortUser}:del"],
                ],
                [
                    ['text' => '« Back to Users', 'callback_data' => "users:{$serverId}:1:all"],
                    ['text' => '« Server Menu', 'callback_data' => "srv:{$serverId}"],
                ],
            ],
        ];
    }

    /**
     * Server Batch Actions Menu Keyboard.
     */
    public static function actionsMenu(int $serverId, string $serverType = 'marzban'): array {
        $rows = [
            [
                ['text' => '🗑️ Delete Expired', 'callback_data' => "act_item:{$serverId}:del_exp"],
                ['text' => '🗑️ Delete Limited', 'callback_data' => "act_item:{$serverId}:del_lim"],
            ],
            [
                ['text' => '🗑️ Delete All (Admin)', 'callback_data' => "act_item:{$serverId}:del_all"],
            ],
            [
                ['text' => '✔️ Activate Admin Users', 'callback_data' => "act_item:{$serverId}:act_adm"],
                ['text' => '✖️ Disable Admin Users', 'callback_data' => "act_item:{$serverId}:dis_adm"],
            ],
            [
                ['text' => '💱 Transfer Users', 'callback_data' => "act_item:{$serverId}:xfer_adm"],
            ],
        ];

        if (strtolower($serverType) === 'marzneshin') {
            $rows[] = [
                ['text' => '➕ Add Config to Users', 'callback_data' => "act_item:{$serverId}:add_cfg"],
                ['text' => '➖ Remove Config from Users', 'callback_data' => "act_item:{$serverId}:del_cfg"],
            ];
        }

        $rows[] = [['text' => '« Server Menu', 'callback_data' => "srv:{$serverId}"]];
        return ['inline_keyboard' => $rows];
    }

    /**
     * Admins Selector Keyboard.
     */
    public static function adminsSelector(int $serverId, array $admins, string $actionPrefix, bool $includeAll = false, ?string $backData = null): array {
        $rows = [];
        if ($includeAll) {
            $rows[] = [['text' => '🌐 ALL ADMINS', 'callback_data' => "{$actionPrefix}:{$serverId}:ALL"]];
        }

        $buttons = [];
        foreach ($admins as $adm) {
            $buttons[] = [
                'text' => "👤 {$adm}",
                'callback_data' => "{$actionPrefix}:{$serverId}:{$adm}",
            ];
        }

        $chunks = array_chunk($buttons, 2);
        foreach ($chunks as $chunk) {
            $rows[] = $chunk;
        }

        $back = $backData ?: "act_menu:{$serverId}";
        $rows[] = [['text' => '« Back', 'callback_data' => $back]];
        return ['inline_keyboard' => $rows];
    }

    /**
     * Templates Management Menu Keyboard.
     */
    public static function templatesMenu(array $templates): array {
        $rows = [];
        foreach ($templates as $t) {
            $activeIcon = (!isset($t['is_active']) || !empty($t['is_active'])) ? '✅' : '❌';
            $dlText = ($t['data_limit'] > 0) ? "{$t['data_limit']}GB" : 'Unlimited';
            $dtText = ($t['date_limit'] > 0) ? "{$t['date_limit']}d" : 'Unlimited';
            $rows[] = [
                [
                    'text' => "{$activeIcon} {$t['remark']} ({$dlText} / {$dtText})",
                    'callback_data' => "tmpl_view:{$t['id']}",
                ]
            ];
        }

        $rows[] = [
            ['text' => '➕ Add Template', 'callback_data' => 'new_tmpl'],
            ['text' => '« Back to Home', 'callback_data' => 'home'],
        ];

        return ['inline_keyboard' => $rows];
    }

    /**
     * Template Actions Keyboard.
     */
    public static function templateActions(int $tmplId, bool $isActive = true): array {
        $toggleText = $isActive ? '❌ Disable Template' : '✅ Enable Template';
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✏️ Edit Remark', 'callback_data' => "tmpl_edit_remark:{$tmplId}"],
                    ['text' => '📊 Edit Data Limit', 'callback_data' => "tmpl_edit_data:{$tmplId}"],
                ],
                [
                    ['text' => '⏱️ Edit Date Limit', 'callback_data' => "tmpl_edit_date:{$tmplId}"],
                    ['text' => $toggleText, 'callback_data' => "tmpl_tgl_act:{$tmplId}"],
                ],
                [
                    ['text' => '🗑️ Delete Template', 'callback_data' => "tmpl_del_ask:{$tmplId}"],
                ],
                [
                    ['text' => '« Back to Templates', 'callback_data' => 'tmpls'],
                ],
            ],
        ];
    }

    /**
     * Confirm / Cancel prompt keyboard.
     */
    public static function confirm(string $confirmData, string $cancelData = 'home'): array {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✔️ Yes, Confirm', 'callback_data' => $confirmData],
                    ['text' => '❌ Cancel', 'callback_data' => $cancelData],
                ],
            ],
        ];
    }

    /**
     * Date Type Selector Keyboard.
     */
    public static function dateTypeSelector(int $serverId, string $username, string $prefix = 'dt_type'): array {
        return [
            'inline_keyboard' => [
                [['text' => '♾️ Unlimited (No Expiry)', 'callback_data' => "{$prefix}:{$serverId}:{$username}:unlimited"]],
                [['text' => '📅 Fixed Date (Days from Now)', 'callback_data' => "{$prefix}:{$serverId}:{$username}:fixed"]],
                [['text' => '🚀 After First Use', 'callback_data' => "{$prefix}:{$serverId}:{$username}:onhold"]],
                [['text' => '❌ Cancel', 'callback_data' => "usr:{$serverId}:{$username}"]],
            ],
        ];
    }

    /**
     * Charge Confirmation Options Keyboard (Normal / Reset / Additive).
     */
    public static function chargeConfirmOptions(int $serverId, string $username, float $dataLimit, int $dateLimit): array {
        $shortUser = substr($username, 0, 30);
        return [
            'inline_keyboard' => [
                [['text' => '✅ Yes, Normal (Apply Limits)', 'callback_data' => "chg_rst_opt:{$serverId}:normal"]],
                [['text' => '🔁 Yes, Reset Usage + Apply', 'callback_data' => "chg_rst_opt:{$serverId}:reset"]],
                [['text' => '📈 Yes, Add on top of existing', 'callback_data' => "chg_rst_opt:{$serverId}:additive"]],
                [['text' => '❌ No / Cancel', 'callback_data' => "usr:{$serverId}:{$shortUser}"]],
            ],
        ];
    }

    /**
     * Template selection keyboard for user creation or recharging.
     */
    public static function templateSelector(int $serverId, array $templates, string $prefix = 'use_tmpl'): array {
        $rows = [];
        foreach ($templates as $t) {
            if (isset($t['is_active']) && empty($t['is_active'])) {
                continue;
            }
            $dlText = ($t['data_limit'] > 0) ? "{$t['data_limit']}GB" : 'Unlimited';
            $dtText = ($t['date_limit'] > 0) ? "{$t['date_limit']}d" : 'Unlimited';
            $rows[] = [
                [
                    'text' => "⚡ {$t['remark']} ({$dlText} / {$dtText})",
                    'callback_data' => "{$prefix}:{$serverId}:{$t['id']}",
                ]
            ];
        }

        $rows[] = [
            ['text' => '✏️ Custom Values', 'callback_data' => "{$prefix}_custom:{$serverId}"],
            ['text' => '❌ Cancel', 'callback_data' => "srv:{$serverId}"],
        ];

        return ['inline_keyboard' => $rows];
    }

    /**
     * Interactive Configs/Services Multi-Select Keyboard.
     */
    public static function configSelector(
        int $serverId,
        array $configs,
        array $selectedNames,
        string $prefix,
        string $doneCallback,
        string $cancelCallback
    ): array {
        $rows = [];

        // Select All / Deselect All row
        $rows[] = [
            ['text' => 'Select All', 'callback_data' => "{$prefix}:all:{$serverId}"],
            ['text' => 'DeSelect All', 'callback_data' => "{$prefix}:none:{$serverId}"],
        ];

        // Individual config toggles
        foreach ($configs as $cfg) {
            $name = $cfg['name'] ?? ($cfg['remark'] ?? (string)$cfg['id']);
            $isChecked = in_array($name, $selectedNames) || in_array($cfg['id'], $selectedNames);
            $icon = $isChecked ? '✅ ' : '☐ ';
            $rows[] = [
                [
                    'text' => $icon . $name,
                    'callback_data' => "{$prefix}:tgl:{$serverId}:" . rawurlencode((string)($cfg['id'] ?? $name)),
                ]
            ];
        }

        // Action row: Done & Cancel
        $rows[] = [
            ['text' => '✔️ DONE', 'callback_data' => $doneCallback],
            ['text' => '❌ Cancel', 'callback_data' => $cancelCallback],
        ];

        return ['inline_keyboard' => $rows];
    }

    /**
     * Simple Cancel Keyboard.
     */
    public static function cancel(string $backData = 'home'): array {
        return [
            'inline_keyboard' => [
                [['text' => '❌ Cancel', 'callback_data' => $backData]],
            ],
        ];
    }
}
