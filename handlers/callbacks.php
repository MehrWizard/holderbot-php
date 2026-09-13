<?php
/**
 * HolderBot PHP - Complete Callback Query Handlers
 */

declare(strict_types=1);

class CallbackHandlers {
    /**
     * Dispatch callback queries.
     */
    public static function handle(array $callbackQuery): void {
        $id = $callbackQuery['id'];
        $data = $callbackQuery['data'] ?? '';
        $chatId = $callbackQuery['message']['chat']['id'] ?? 0;
        $messageId = $callbackQuery['message']['message_id'] ?? 0;
        $userId = $callbackQuery['from']['id'];

        if (empty($data) || $data === 'noop') {
            tg_answer_callback($id);
            return;
        }

        // Cancel / Home
        if ($data === 'cancel' || $data === 'home') {
            Storage::clearState($userId);
            tg_answer_callback($id);
            self::renderHome($chatId, $messageId);
            return;
        }

        // Server Menu: srv:<id>
        if (str_starts_with($data, 'srv:')) {
            $serverId = (int)substr($data, 4);
            Storage::clearState($userId);
            tg_answer_callback($id);
            self::renderServerMenu($chatId, $messageId, $serverId);
            return;
        }

        // Server Settings: srv_cfg:<id>
        if (str_starts_with($data, 'srv_cfg:')) {
            $serverId = (int)substr($data, 8);
            tg_answer_callback($id);
            self::renderServerSettings($chatId, $messageId, $serverId);
            return;
        }

        // Server Settings Toggles
        if (str_starts_with($data, 'tgl_srv_act:')) {
            $serverId = (int)substr($data, 12);
            $server = Storage::getServer($serverId);
            if ($server) {
                $server['is_active'] = empty($server['is_active']) ? 1 : 0;
                Storage::saveServer($server);
            }
            tg_answer_callback($id, "Server status updated.");
            self::renderServerSettings($chatId, $messageId, $serverId);
            return;
        }
        if (str_starts_with($data, 'tgl_srv_mon:')) {
            $serverId = (int)substr($data, 12);
            $server = Storage::getServer($serverId);
            if ($server) {
                $server['node_monitoring'] = empty($server['node_monitoring']) ? 1 : 0;
                Storage::saveServer($server);
            }
            tg_answer_callback($id, "Monitoring updated.");
            self::renderServerSettings($chatId, $messageId, $serverId);
            return;
        }
        if (str_starts_with($data, 'tgl_srv_res:')) {
            $serverId = (int)substr($data, 12);
            $server = Storage::getServer($serverId);
            if ($server) {
                $server['node_restart'] = empty($server['node_restart']) ? 1 : 0;
                Storage::saveServer($server);
            }
            tg_answer_callback($id, "Auto-restart updated.");
            self::renderServerSettings($chatId, $messageId, $serverId);
            return;
        }
        if (str_starts_with($data, 'del_srv_ask:')) {
            $serverId = (int)substr($data, 12);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "⚠️ Are you sure you want to remove this server configuration?",
                Keyboards::confirm("del_srv_ok:{$serverId}", "srv_cfg:{$serverId}")
            );
            return;
        }
        if (str_starts_with($data, 'del_srv_ok:')) {
            $serverId = (int)substr($data, 11);
            Storage::deleteServer($serverId);
            tg_answer_callback($id, "Server deleted.", true);
            self::renderHome($chatId, $messageId);
            return;
        }

        // Users List: users:<id>:<page>:<filter>
        if (str_starts_with($data, 'users:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $page = max(1, (int)($parts[2] ?? 1));
            $filter = $parts[3] ?? 'all';
            tg_answer_callback($id);
            self::renderUsersList($chatId, $messageId, $serverId, $page, $filter);
            return;
        }

        // View single user: usr:<id>:<username>
        if (str_starts_with($data, 'usr:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $username = $parts[2] ?? '';
            tg_answer_callback($id);
            self::renderUserCard($chatId, $messageId, $serverId, $username);
            return;
        }

        // User actions: act:<id>:<username>:<action>
        if (str_starts_with($data, 'act:')) {
            $parts = explode(':', $data, 4);
            $serverId = (int)($parts[1] ?? 0);
            $username = $parts[2] ?? '';
            $action = $parts[3] ?? '';
            self::handleUserAction($id, $chatId, $messageId, $userId, $serverId, $username, $action);
            return;
        }

        // Delete user confirmed: del_ok:<id>:<username>
        if (str_starts_with($data, 'del_ok:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $username = $parts[2] ?? '';
            self::executeUserDelete($id, $chatId, $messageId, $serverId, $username);
            return;
        }

        // Set owner admin: set_own:<id>:<admin>
        if (str_starts_with($data, 'set_own:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $admin = $parts[2] ?? '';
            $state = Storage::getState($userId);
            $username = $state['data']['username'] ?? '';
            if ($username) {
                $server = Storage::getServer($serverId);
                PanelManager::setOwner($server, $username, $admin);
                Storage::clearState($userId);
                tg_answer_callback($id, "Owner changed to {$admin}!", true);
                self::renderUserCard($chatId, $messageId, $serverId, $username);
            }
            return;
        }

        // Recharge template select: chg_tmpl:<id>:<tmpl_id>
        if (str_starts_with($data, 'chg_tmpl:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $tmplId = (int)($parts[2] ?? 0);
            $tmpl = Storage::getTemplate($tmplId);
            $state = Storage::getState($userId);
            if ($tmpl && !empty($state['data']['username'])) {
                $state['data']['data_limit'] = $tmpl['data_limit'];
                $state['data']['date_limit'] = $tmpl['date_limit'];
                Storage::setState($userId, 'charge_confirm_reset', $state['data']);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "🧪 <b>Recharge with {$tmpl['remark']}</b>\n\nDo you want to reset used traffic to 0?",
                    [
                        'inline_keyboard' => [
                            [
                                ['text' => '🔁 Yes, Reset Usage', 'callback_data' => "chg_rst_opt:{$serverId}:1"],
                                ['text' => 'Keep Usage', 'callback_data' => "chg_rst_opt:{$serverId}:0"],
                            ],
                            [['text' => '❌ Cancel', 'callback_data' => "usr:{$serverId}:" . $state['data']['username']]],
                        ]
                    ]
                );
            }
            return;
        }

        // Recharge custom limit start: chg_tmpl_custom:<id>
        if (str_starts_with($data, 'chg_tmpl_custom:')) {
            $serverId = (int)substr($data, 16);
            $state = Storage::getState($userId);
            if (!empty($state['data']['username'])) {
                Storage::setState($userId, 'charge_custom_data', $state['data']);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "🧪 Send the <b>Data Limit in GB</b> to add (e.g. <code>50</code>, or <code>0</code> for unlimited):",
                    Keyboards::cancel("usr:{$serverId}:" . $state['data']['username'])
                );
            }
            return;
        }

        // Execute recharge option: chg_rst_opt:<id>:<reset:0|1>
        if (str_starts_with($data, 'chg_rst_opt:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $resetUsage = ((int)($parts[2] ?? 0)) === 1;
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);
            if ($server && !empty($state['data']['username'])) {
                $username = $state['data']['username'];
                $dataLimit = (float)($state['data']['data_limit'] ?? 0);
                $dateLimit = (int)($state['data']['date_limit'] ?? 0);
                Storage::clearState($userId);

                $updated = PanelManager::chargeUser($server, $username, $dataLimit, $dateLimit, $resetUsage);
                tg_answer_callback($id, "Recharged successfully!", true);
                self::renderUserCard($chatId, $messageId, $serverId, $username);
            }
            return;
        }

        // Server Actions Menu: act_menu:<id>
        if (str_starts_with($data, 'act_menu:')) {
            $serverId = (int)substr($data, 9);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "⚡ <b>Server Batch Actions</b>\n\nChoose an automated action to perform across users:",
                Keyboards::actionsMenu($serverId)
            );
            return;
        }

        // Server Action Item selected: act_item:<id>:<action>
        if (str_starts_with($data, 'act_item:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $action = $parts[2] ?? '';
            $server = Storage::getServer($serverId);
            $admins = PanelManager::getAdmins($server);

            if ($action === 'del_exp' || $action === 'del_lim') {
                tg_answer_callback($id);
                $title = ($action === 'del_exp') ? "Delete Expired Users" : "Delete Limited Users";
                $kb = Keyboards::adminsSelector($serverId, $admins, "exec_act:{$action}", true);
                tg_edit_message($chatId, $messageId, "🗑️ <b>{$title}</b>\n\nSelect which admin's users to delete:", $kb);
                return;
            }

            if ($action === 'act_adm' || $action === 'dis_adm') {
                tg_answer_callback($id);
                $title = ($action === 'act_adm') ? "Activate Admin Users" : "Disable Admin Users";
                $kb = Keyboards::adminsSelector($serverId, $admins, "exec_act:{$action}", false);
                tg_edit_message($chatId, $messageId, "👥 <b>{$title}</b>\n\nSelect target admin:", $kb);
                return;
            }

            if ($action === 'xfer_adm') {
                tg_answer_callback($id);
                $kb = Keyboards::adminsSelector($serverId, $admins, "xfer_from", false);
                tg_edit_message($chatId, $messageId, "💱 <b>Transfer Users</b> (Step 1/2)\n\nSelect source admin (FROM):", $kb);
                return;
            }
        }

        // Execute bulk action on selected admin: exec_act:<action>:<id>:<admin>
        if (str_starts_with($data, 'exec_act:')) {
            $parts = explode(':', $data, 4);
            $action = $parts[1] ?? '';
            $serverId = (int)($parts[2] ?? 0);
            $admin = $parts[3] ?? 'ALL';
            $server = Storage::getServer($serverId);

            tg_answer_callback($id, "Processing batch action...");
            tg_edit_message($chatId, $messageId, "⏳ Executing action, please wait...");

            if ($action === 'del_exp') {
                $deleted = PanelManager::deleteExpiredUsers($server, $admin);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "✅ <b>Done!</b> Removed <code>{$deleted}</code> expired users.",
                    Keyboards::serverMenu($serverId)
                );
            } elseif ($action === 'del_lim') {
                $deleted = PanelManager::deleteLimitedUsers($server, $admin);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "✅ <b>Done!</b> Removed <code>{$deleted}</code> limited users.",
                    Keyboards::serverMenu($serverId)
                );
            } elseif ($action === 'act_adm') {
                PanelManager::activateAdminUsers($server, $admin);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "✅ Activated all users under admin <code>{$admin}</code>.",
                    Keyboards::serverMenu($serverId)
                );
            } elseif ($action === 'dis_adm') {
                PanelManager::disableAdminUsers($server, $admin);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "✅ Disabled all users under admin <code>{$admin}</code>.",
                    Keyboards::serverMenu($serverId)
                );
            }
            return;
        }

        // Transfer step 1: xfer_from:<id>:<fromAdmin>
        if (str_starts_with($data, 'xfer_from:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $fromAdmin = $parts[2] ?? '';
            Storage::setState($userId, 'xfer_target', ['server_id' => $serverId, 'from_admin' => $fromAdmin]);
            $server = Storage::getServer($serverId);
            $admins = array_filter(PanelManager::getAdmins($server), fn($a) => $a !== $fromAdmin);

            tg_answer_callback($id);
            $kb = Keyboards::adminsSelector($serverId, $admins, "xfer_to", false);
            tg_edit_message(
                $chatId,
                $messageId,
                "💱 <b>Transfer Users</b> (Step 2/2)\n\nFrom: <code>{$fromAdmin}</code>\nSelect destination admin (TO):",
                $kb
            );
            return;
        }

        // Transfer step 2: xfer_to:<id>:<toAdmin>
        if (str_starts_with($data, 'xfer_to:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $toAdmin = $parts[2] ?? '';
            $state = Storage::getState($userId);
            $fromAdmin = $state['data']['from_admin'] ?? '';
            $state['data']['to_admin'] = $toAdmin;
            Storage::setState($userId, 'xfer_confirm', $state['data']);

            tg_answer_callback($id);
            $kb = Keyboards::confirm("xfer_ok:{$serverId}", "act_menu:{$serverId}");
            tg_edit_message(
                $chatId,
                $messageId,
                "⚠️ <b>Confirm Transfer</b>\n\nTransfer all users from <code>{$fromAdmin}</code> to <code>{$toAdmin}</code>?",
                $kb
            );
            return;
        }

        // Execute Transfer: xfer_ok:<id>
        if (str_starts_with($data, 'xfer_ok:')) {
            $serverId = (int)substr($data, 8);
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);
            $fromAdmin = $state['data']['from_admin'] ?? '';
            $toAdmin = $state['data']['to_admin'] ?? '';
            Storage::clearState($userId);

            tg_answer_callback($id, "Transferring users...");
            tg_edit_message($chatId, $messageId, "⏳ Transferring users, please wait...");

            $count = PanelManager::transferUsers($server, $fromAdmin, $toAdmin);
            tg_edit_message(
                $chatId,
                $messageId,
                "✅ <b>Transfer Complete!</b>\n\nSuccessfully moved <code>{$count}</code> users from <code>{$fromAdmin}</code> to <code>{$toAdmin}</code>.",
                Keyboards::serverMenu($serverId)
            );
            return;
        }

        // Server Statistics: stats:<id>
        if (str_starts_with($data, 'stats:')) {
            $serverId = (int)substr($data, 6);
            $server = Storage::getServer($serverId);
            tg_answer_callback($id, "Loading statistics...");
            tg_edit_message($chatId, $messageId, "⏳ Calculating metrics, please wait...");
            $stats = PanelManager::getServerStats($server);
            $card = Formatter::statsCard($server, $stats);
            $kb = [
                'inline_keyboard' => [
                    [['text' => '🔄 Refresh', 'callback_data' => "stats:{$serverId}"]],
                    [['text' => '« Server Menu', 'callback_data' => "srv:{$serverId}"]],
                ]
            ];
            tg_edit_message($chatId, $messageId, $card, $kb);
            return;
        }

        // Bulk user creation wizard: bulk_usr:<id>
        if (str_starts_with($data, 'bulk_usr:')) {
            $serverId = (int)substr($data, 9);
            Storage::setState($userId, 'bulk_count', ['server_id' => $serverId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "📦 <b>Bulk User Creation</b> (Step 1/3)\n\nHow many accounts do you want to create? (e.g. <code>5</code>, max 50):",
                Keyboards::cancel("srv:{$serverId}")
            );
            return;
        }

        // Template selector for bulk creation: bulk_tmpl:<id>:<tmpl_id>
        if (str_starts_with($data, 'bulk_tmpl:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $tmplId = (int)($parts[2] ?? 0);
            $tmpl = Storage::getTemplate($tmplId);
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);

            if ($tmpl && $server && !empty($state['data']['count'])) {
                $count = (int)$state['data']['count'];
                $prefix = $state['data']['prefix'] ?? 'usr';
                Storage::clearState($userId);

                tg_answer_callback($id, "Creating {$count} accounts...");
                tg_edit_message($chatId, $messageId, "⏳ Creating <code>{$count}</code> accounts on {$server['remark']}...");

                $users = PanelManager::bulkCreateUsers($server, $count, $prefix, $tmpl['data_limit'], $tmpl['date_limit']);
                $card = Formatter::bulkCreatedCard($server, $users);
                tg_edit_message($chatId, $messageId, $card, Keyboards::serverMenu($serverId));
            }
            return;
        }

        // Templates Menu: tmpls
        if ($data === 'tmpls') {
            tg_answer_callback($id);
            self::renderTemplates($chatId, $messageId);
            return;
        }

        // View Template: tmpl_view:<id>
        if (str_starts_with($data, 'tmpl_view:')) {
            $tmplId = (int)substr($data, 10);
            $tmpl = Storage::getTemplate($tmplId);
            if ($tmpl) {
                tg_answer_callback($id);
                tg_edit_message($chatId, $messageId, Formatter::templateCard($tmpl), Keyboards::templateActions($tmplId));
            }
            return;
        }

        // Delete Template: tmpl_del:<id>
        if (str_starts_with($data, 'tmpl_del:')) {
            $tmplId = (int)substr($data, 9);
            Storage::deleteTemplate($tmplId);
            tg_answer_callback($id, "Template deleted.", true);
            self::renderTemplates($chatId, $messageId);
            return;
        }

        // Add Template: new_tmpl
        if ($data === 'new_tmpl') {
            Storage::setState($userId, 'tmpl_add_remark');
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "📋 <b>Add Template</b> (Step 1/3)\n\nPlease send a friendly remark (e.g. <code>30 Days 100GB</code>):",
                Keyboards::cancel('tmpls')
            );
            return;
        }

        // Search user: srch_usr:<id>
        if (str_starts_with($data, 'srch_usr:')) {
            $serverId = (int)substr($data, 9);
            Storage::setState($userId, 'search_user', ['server_id' => $serverId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "🔍 <b>Search User</b>\n\nPlease enter the exact or partial username to search:",
                Keyboards::cancel("srv:{$serverId}")
            );
            return;
        }

        // Create user: new_usr:<id>
        if (str_starts_with($data, 'new_usr:')) {
            $serverId = (int)substr($data, 8);
            Storage::setState($userId, 'create_user_name', ['server_id' => $serverId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "➕ <b>Create New User</b>\n\nPlease send the username for the new account (e.g. <code>client_01</code>) or generate a random one:",
                [
                    'inline_keyboard' => [
                        [['text' => '🎲 Random Username', 'callback_data' => "rnd_usr:{$serverId}"]],
                        [['text' => '❌ Cancel', 'callback_data' => "srv:{$serverId}"]],
                    ]
                ]
            );
            return;
        }

        // Random username generated: rnd_usr:<id>
        if (str_starts_with($data, 'rnd_usr:')) {
            $serverId = (int)substr($data, 8);
            $randomName = 'user_' . substr(bin2hex(random_bytes(4)), 0, 6);
            $stateData = ['server_id' => $serverId, 'username' => $randomName];
            Storage::setState($userId, 'create_user_data', $stateData);
            tg_answer_callback($id, "Generated: {$randomName}");

            $templates = Storage::getTemplates();
            $text = "👤 Generated username: <code>{$randomName}</code>\n\nChoose a template or send custom <b>Data Limit in GB</b> (e.g. <code>30</code>):";
            $kb = Keyboards::templateSelector($serverId, $templates);
            tg_edit_message($chatId, $messageId, $text, $kb);
            return;
        }

        // Single user template create: use_tmpl:<id>:<tmpl_id>
        if (str_starts_with($data, 'use_tmpl:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $tmplId = (int)($parts[2] ?? 0);
            self::applyTemplateCreate($id, $chatId, $messageId, $userId, $serverId, $tmplId);
            return;
        }

        // Single user custom create start: use_tmpl_custom:<id>
        if (str_starts_with($data, 'use_tmpl_custom:')) {
            $serverId = (int)substr($data, 16);
            $state = Storage::getState($userId);
            if (!empty($state['data']['username'])) {
                Storage::setState($userId, 'create_user_data', $state['data']);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "📊 Send the <b>Data Limit in GB</b> (e.g. <code>50</code>, or <code>0</code> for unlimited):",
                    Keyboards::cancel("srv:{$serverId}")
                );
            }
            return;
        }

        // Nodes monitoring: nodes:<id>
        if (str_starts_with($data, 'nodes:')) {
            $serverId = (int)substr($data, 6);
            tg_answer_callback($id);
            self::renderNodesStatus($chatId, $messageId, $serverId);
            return;
        }

        // Add server: add_srv
        if ($data === 'add_srv') {
            Storage::setState($userId, 'add_server_remark');
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "➕ <b>Add New Server</b> (Step 1/5)\n\nPlease send a friendly remark/name for this server (e.g. <code>Main Marzban</code>):",
                Keyboards::cancel('home')
            );
            return;
        }

        tg_answer_callback($id, "Action not found.");
    }

    private static function renderHome(int|string $chatId, int $messageId): void {
        $servers = Storage::getServers();
        $text = "🤖 <b>HolderBot Main Menu</b>\n\nSelect a server to manage:";
        $kb = Keyboards::home($servers);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }

    private static function renderServerMenu(int|string $chatId, int $messageId, int $serverId): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_edit_message($chatId, $messageId, "❌ Server not found.", Keyboards::home(Storage::getServers()));
            return;
        }

        $text = Formatter::serverCard($server);
        $kb = Keyboards::serverMenu($serverId);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }

    private static function renderServerSettings(int|string $chatId, int $messageId, int $serverId): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_edit_message($chatId, $messageId, "❌ Server not found.", Keyboards::home(Storage::getServers()));
            return;
        }

        $text = Formatter::serverCard($server);
        $kb = Keyboards::serverSettings($server);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }

    private static function renderUsersList(
        int|string $chatId,
        int $messageId,
        int $serverId,
        int $page,
        string $filter = 'all'
    ): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_edit_message($chatId, $messageId, "❌ Server not found.", Keyboards::home(Storage::getServers()));
            return;
        }

        $limit = 10;
        $statusParam = ($filter !== 'all') ? $filter : null;
        $users = PanelManager::getUsers($server, $page, $limit + 1, null, $statusParam);
        $hasMore = count($users) > $limit;
        if ($hasMore) {
            array_pop($users);
        }

        $filterBadge = strtoupper($filter);
        if (empty($users)) {
            $text = "👥 <b>Users List [{$filterBadge}]</b> - <code>{$server['remark']}</code>\n\nNo users found matching this filter.";
        } else {
            $text = "👥 <b>Users [{$filterBadge}]</b> - <code>{$server['remark']}</code> (Page {$page}):\n<i>Tap a user to manage:</i>";
        }

        $kb = Keyboards::usersList($serverId, $users, $page, $hasMore, $filter);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }

    private static function renderUserCard(int|string $chatId, int $messageId, int $serverId, string $username): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_edit_message($chatId, $messageId, "❌ Server not found.", Keyboards::home(Storage::getServers()));
            return;
        }

        $user = PanelManager::getUser($server, $username);
        if (!$user) {
            tg_edit_message(
                $chatId,
                $messageId,
                "❌ User <code>" . htmlspecialchars($username) . "</code> was not found on server.",
                Keyboards::serverMenu($serverId)
            );
            return;
        }

        $card = Formatter::userCard($server, $user);
        $kb = Keyboards::userActions($serverId, $user['username'], $user['is_active'], $user['status']);
        tg_edit_message($chatId, $messageId, $card, $kb);
    }

    private static function handleUserAction(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        int $userId,
        int $serverId,
        string $username,
        string $action
    ): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_answer_callback($callbackId, "Server not found.", true);
            return;
        }

        switch ($action) {
            case 'tgl':
                $user = PanelManager::getUser($server, $username);
                if (!$user) {
                    tg_answer_callback($callbackId, "User not found.", true);
                    return;
                }
                $newStatus = !$user['is_active'];
                $ok = PanelManager::setStatus($server, $username, $newStatus);
                tg_answer_callback($callbackId, $ok ? "Status updated!" : "Failed to update status.");
                self::renderUserCard($chatId, $messageId, $serverId, $username);
                break;

            case 'chg': // Recharge user
                $templates = Storage::getTemplates();
                Storage::setState($userId, 'user_mod_charge', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                $kb = Keyboards::templateSelector($serverId, $templates, 'chg_tmpl');
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "🧪 <b>Recharge User:</b> <code>{$username}</code>\n\nSelect a template or custom limits:",
                    $kb
                );
                break;

            case 'dl': // Modify Data limit
                Storage::setState($userId, 'user_mod_datalimit', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "📊 <b>Edit Data Limit:</b> <code>{$username}</code>\n\nPlease send the new total limit in GB (e.g. <code>60</code>, or <code>0</code> for unlimited):",
                    Keyboards::cancel("usr:{$serverId}:{$username}")
                );
                break;

            case 'dt': // Modify Date limit
                Storage::setState($userId, 'user_mod_datelimit', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "⏱️ <b>Edit Expiration:</b> <code>{$username}</code>\n\nPlease send the new duration in days from now (e.g. <code>30</code>, or <code>0</code> for unlimited):",
                    Keyboards::cancel("usr:{$serverId}:{$username}")
                );
                break;

            case 'nt': // Modify Note
                Storage::setState($userId, 'user_mod_note', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "🗒️ <b>Edit Note:</b> <code>{$username}</code>\n\nPlease send the new note for this user (or send <code>-</code> to clear):",
                    Keyboards::cancel("usr:{$serverId}:{$username}")
                );
                break;

            case 'own': // Change Owner
                $admins = PanelManager::getAdmins($server);
                Storage::setState($userId, 'user_mod_owner', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                $kb = Keyboards::adminsSelector($serverId, $admins, 'set_own', false);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "👤 <b>Change Owner:</b> <code>{$username}</code>\n\nSelect the new admin:",
                    $kb
                );
                break;

            case 'rst':
                $ok = PanelManager::resetUsage($server, $username);
                tg_answer_callback($callbackId, $ok ? "Traffic usage reset!" : "Failed to reset usage.");
                self::renderUserCard($chatId, $messageId, $serverId, $username);
                break;

            case 'rvk':
                $updated = PanelManager::revokeSub($server, $username);
                tg_answer_callback($callbackId, $updated ? "Subscription link revoked!" : "Failed to revoke link.");
                self::renderUserCard($chatId, $messageId, $serverId, $username);
                break;

            case 'qr':
                $user = PanelManager::getUser($server, $username);
                if (!$user || empty($user['subscription_url'])) {
                    tg_answer_callback($callbackId, "No subscription link available for QR.", true);
                    return;
                }
                tg_answer_callback($callbackId, "Generating QR code...");
                $qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=350x350&data=" . urlencode($user['subscription_url']);
                tg_send_photo(
                    $chatId,
                    $qrApiUrl,
                    "🖼️ QR Code for <code>" . htmlspecialchars($username) . "</code>\n\n<code>{$user['subscription_url']}</code>"
                );
                break;

            case 'del':
                tg_answer_callback($callbackId);
                $confirmKb = Keyboards::confirm("del_ok:{$serverId}:{$username}", "usr:{$serverId}:{$username}");
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "⚠️ <b>Delete User Confirmation</b>\n\nAre you sure you want to permanently remove user <code>" . htmlspecialchars($username) . "</code> from <b>{$server['remark']}</b>?",
                    $confirmKb
                );
                break;
        }
    }

    private static function executeUserDelete(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        int $serverId,
        string $username
    ): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_answer_callback($callbackId, "Server not found.", true);
            return;
        }

        $ok = PanelManager::deleteUser($server, $username);
        if ($ok) {
            tg_answer_callback($callbackId, "User deleted successfully!", true);
            self::renderUsersList($chatId, $messageId, $serverId, 1);
        } else {
            tg_answer_callback($callbackId, "Failed to delete user.", true);
            self::renderUserCard($chatId, $messageId, $serverId, $username);
        }
    }

    private static function applyTemplateCreate(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        int $userId,
        int $serverId,
        int $tmplId
    ): void {
        $server = Storage::getServer($serverId);
        $tmpl = Storage::getTemplate($tmplId);
        $state = Storage::getState($userId);

        if (!$server || !$tmpl || empty($state['data']['username'])) {
            tg_answer_callback($callbackId, "Session expired, please retry.", true);
            return;
        }

        $username = $state['data']['username'];
        $created = PanelManager::createUser($server, $username, $tmpl['data_limit'], $tmpl['date_limit']);

        Storage::clearState($userId);

        if ($created) {
            tg_answer_callback($callbackId, "User created successfully!");
            $card = "🎉 <b>User Created Successfully!</b>\n\n" . Formatter::userCard($server, $created);
            $kb = Keyboards::userActions($serverId, $created['username'], true, 'active');
            tg_edit_message($chatId, $messageId, $card, $kb);
        } else {
            tg_answer_callback($callbackId, "Failed to create user.", true);
            tg_edit_message(
                $chatId,
                $messageId,
                "❌ <b>Error:</b> Could not create user <code>" . htmlspecialchars($username) . "</code> on server.",
                Keyboards::serverMenu($serverId)
            );
        }
    }

    private static function renderNodesStatus(int|string $chatId, int $messageId, int $serverId): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_edit_message($chatId, $messageId, "❌ Server not found.", Keyboards::home(Storage::getServers()));
            return;
        }

        $nodes = PanelManager::getNodes($server);
        $text = Formatter::serverCard($server, $nodes);
        $kb = Keyboards::serverMenu($serverId);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }

    private static function renderTemplates(int|string $chatId, int $messageId): void {
        $templates = Storage::getTemplates();
        $text = "📋 <b>Templates Management</b>\n\nSelect a template to view or delete, or add a new one:";
        $kb = Keyboards::templatesMenu($templates);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }
}
