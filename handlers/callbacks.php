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

        // Server Settings Toggles - each requires a confirmation step first
        if (str_starts_with($data, 'tgl_srv_mon_ask:')) {
            $serverId = (int)substr($data, 16);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, "Are your sure?", Keyboards::confirm("tgl_srv_mon:{$serverId}", "srv_cfg:{$serverId}"));
            return;
        }
        if (str_starts_with($data, 'tgl_srv_res_ask:')) {
            $serverId = (int)substr($data, 16);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, "Are your sure?", Keyboards::confirm("tgl_srv_res:{$serverId}", "srv_cfg:{$serverId}"));
            return;
        }
        if (str_starts_with($data, 'tgl_srv_exp_ask:')) {
            $serverId = (int)substr($data, 16);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, "Are your sure?", Keyboards::confirm("tgl_srv_exp:{$serverId}", "srv_cfg:{$serverId}"));
            return;
        }
        if (str_starts_with($data, 'tgl_srv_mon:')) {
            $serverId = (int)substr($data, 12);
            $server = Storage::getServer($serverId);
            if ($server) {
                $server['node_monitoring'] = empty($server['node_monitoring']) ? 1 : 0;
                Storage::saveServer($server);
            }
            tg_answer_callback($id, "✅ Success.");
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
            tg_answer_callback($id, "✅ Success.");
            self::renderServerSettings($chatId, $messageId, $serverId);
            return;
        }
        if (str_starts_with($data, 'tgl_srv_exp:')) {
            $serverId = (int)substr($data, 12);
            $server = Storage::getServer($serverId);
            if ($server) {
                $server['expired_stats'] = empty($server['expired_stats']) ? 1 : 0;
                Storage::saveServer($server);
            }
            tg_answer_callback($id, "✅ Success.");
            self::renderServerSettings($chatId, $messageId, $serverId);
            return;
        }
        if (str_starts_with($data, 'srv_edit_remark:')) {
            $serverId = (int)substr($data, 16);
            Storage::setState($userId, 'srv_edit_remark', ['server_id' => $serverId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "✏️ <b>Edit Server Remark</b>\n\nPlease send the new remark/name for this server:",
                Keyboards::cancel("srv_cfg:{$serverId}")
            );
            return;
        }
        if (str_starts_with($data, 'srv_edit_creds:')) {
            $serverId = (int)substr($data, 15);
            Storage::setState($userId, 'srv_edit_creds', ['server_id' => $serverId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "🔑 <b>Edit Server Credentials</b>\n\nPlease send credentials in format:\n<code>username password https://panel.url:port</code>",
                Keyboards::cancel("srv_cfg:{$serverId}")
            );
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
                $state['data']['data_limit'] = (float)$tmpl['data_limit'];
                $state['data']['date_limit'] = (int)$tmpl['date_limit'];
                $state['data']['date_type'] = $tmpl['date_type'] ?? (($tmpl['date_limit'] > 0) ? 'fixed' : 'unlimited');
                Storage::setState($userId, 'charge_confirm_reset', $state['data']);
                tg_answer_callback($id);
                $dlText = ($tmpl['data_limit'] > 0) ? "{$tmpl['data_limit']}GB" : "Unlimited";
                $dtText = ($tmpl['date_limit'] > 0) ? "{$tmpl['date_limit']} days" : "Unlimited";
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "🧪 <b>Recharge with {$tmpl['remark']}</b>\n• Limit: <code>{$dlText}</code>\n• Duration: <code>{$dtText}</code>\n\nPlease select recharge mode:",
                    Keyboards::chargeConfirmOptions($serverId, $state['data']['username'], (float)$tmpl['data_limit'], (int)$tmpl['date_limit'])
                );
            }
            return;
        }

        // Execute recharge option: chg_rst_opt:<id>:<mode: normal|reset|additive>
        if (str_starts_with($data, 'chg_rst_opt:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $mode = $parts[2] ?? 'normal';
            $resetUsage = ($mode === 'reset');
            $additive = ($mode === 'additive');
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);
            if ($server && !empty($state['data']['username'])) {
                $username = $state['data']['username'];
                $dataLimit = (float)($state['data']['data_limit'] ?? 0);
                $dateLimit = (int)($state['data']['date_limit'] ?? 0);
                $dateType = $state['data']['date_type'] ?? 'fixed';
                Storage::clearState($userId);

                $updated = PanelManager::chargeUser($server, $username, $dataLimit, $dateLimit, $resetUsage, $additive, $dateType);
                tg_answer_callback($id, $updated ? "Recharged successfully!" : "Failed to recharge.", !$updated);
                self::renderUserCard($chatId, $messageId, $serverId, $username);
            }
            return;
        }

        // User action confirmation prompt: act_confirm:<action>:<id>:<username>:<yes|no>
        if (str_starts_with($data, 'act_confirm:')) {
            $parts = explode(':', $data, 5);
            $action = $parts[1] ?? '';
            $serverId = (int)($parts[2] ?? 0);
            $username = $parts[3] ?? '';
            $confirm = ($parts[4] ?? '') === 'yes';
            $server = Storage::getServer($serverId);

            if (!$server) {
                tg_answer_callback($id, "Server not found.", true);
                return;
            }

            if (!$confirm) {
                tg_answer_callback($id, "Cancelled.");
                self::renderUserCard($chatId, $messageId, $serverId, $username);
                return;
            }

            if ($action === 'tgl') {
                $user = PanelManager::getUser($server, $username);
                $newStatus = empty($user['is_active']);
                $ok = PanelManager::setStatus($server, $username, $newStatus);
                tg_answer_callback($id, $ok ? "Status updated!" : "Failed to update status.");
            } elseif ($action === 'rst') {
                $ok = PanelManager::resetUsage($server, $username);
                tg_answer_callback($id, $ok ? "Traffic reset!" : "Failed to reset traffic.");
            } elseif ($action === 'rvk') {
                $updated = PanelManager::revokeSub($server, $username);
                if ($updated && !empty($updated['subscription_url'])) {
                    QrGenerator::sendQrPhoto(
                        $chatId,
                        $updated['subscription_url'],
                        "⛓️ <b>New Subscription URL:</b> <code>{$username}</code>\n\n<code>{$updated['subscription_url']}</code>"
                    );
                }
                tg_answer_callback($id, $updated ? "Subscription link revoked!" : "Failed to revoke link.");
            }

            self::renderUserCard($chatId, $messageId, $serverId, $username);
            return;
        }

        // Date type selection for user modify: dt_type:<id>:<username>:<type>
        if (str_starts_with($data, 'dt_type:')) {
            $parts = explode(':', $data, 4);
            $serverId = (int)($parts[1] ?? 0);
            $username = $parts[2] ?? '';
            $type = $parts[3] ?? 'fixed';
            $server = Storage::getServer($serverId);

            if ($type === 'unlimited') {
                tg_answer_callback($id, "Setting to unlimited...");
                $ok = PanelManager::updateDateLimit($server, $username, 0, 'unlimited');
                tg_answer_callback($id, $ok ? "Set to unlimited!" : "Failed.", !$ok);
                self::renderUserCard($chatId, $messageId, $serverId, $username);
            } elseif ($type === 'onhold') {
                Storage::setState($userId, 'user_mod_datelimit_onhold', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "🚀 <b>After First Use Expiration:</b> <code>{$username}</code>\n\nPlease send the duration in days after user's first connection (e.g. <code>30</code>):",
                    Keyboards::cancel("usr:{$serverId}:{$username}")
                );
            } else {
                Storage::setState($userId, 'user_mod_datelimit', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "📅 <b>Fixed Expiration:</b> <code>{$username}</code>\n\nPlease send the duration in days from now (e.g. <code>30</code>):",
                    Keyboards::cancel("usr:{$serverId}:{$username}")
                );
            }
            return;
        }

        // User modify configs: cfg_tgl:<id>:<username>:<serviceId>
        if (str_starts_with($data, 'cfg_tgl:')) {
            $parts = explode(':', $data, 4);
            $serverId = (int)($parts[1] ?? 0);
            $username = $parts[2] ?? '';
            $svcId = $parts[3] ?? '';
            $state = Storage::getState($userId);
            $currentIds = $state['data']['current_services'] ?? [];
            if (in_array($svcId, $currentIds) || in_array((int)$svcId, $currentIds)) {
                $currentIds = array_values(array_filter($currentIds, fn($id) => (string)$id !== (string)$svcId));
            } else {
                $currentIds[] = is_numeric($svcId) ? (int)$svcId : $svcId;
            }
            $state['data']['current_services'] = $currentIds;
            Storage::setState($userId, 'user_mod_configs', $state['data']);
            $server = Storage::getServer($serverId);
            $services = PanelManager::getServices($server);
            tg_answer_callback($id);
            $kb = Keyboards::configSelector($serverId, $services, $currentIds, "cfg_pick_all:{$username}", "cfg_save:{$serverId}:{$username}", "usr:{$serverId}:{$username}");
            tg_edit_message($chatId, $messageId, "📂 <b>Manage Configs for</b> <code>{$username}</code>:\nToggle configs to enable or disable:", $kb);
            return;
        }

        if (str_starts_with($data, 'cfg_save:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $username = $parts[2] ?? '';
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);
            $newIds = $state['data']['current_services'] ?? [];
            Storage::clearState($userId);
            if (empty($newIds)) {
                tg_answer_callback($id, "At least one config must be selected.", true);
                return;
            }
            $ok = PanelManager::updateUserConfigs($server, $username, $newIds);
            tg_answer_callback($id, $ok ? "Configs updated!" : "Failed to update configs.", !$ok);
            self::renderUserCard($chatId, $messageId, $serverId, $username);
            return;
        }

        // Server Actions Menu: act_menu:<id>
        if (str_starts_with($data, 'act_menu:')) {
            $serverId = (int)substr($data, 9);
            $server = Storage::getServer($serverId);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "⚡ <b>Server Batch Actions</b>\n\nChoose an automated action to perform across users:",
                Keyboards::actionsMenu($serverId, $server['type'] ?? 'marzban')
            );
            return;
        }

        // Server Action Item selected: act_item:<id>:<action>
        if (str_starts_with($data, 'act_item:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $action = $parts[2] ?? '';
            $server = Storage::getServer($serverId);
            if (!$server) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            $admins = PanelManager::getAdmins($server);
            if (empty($admins)) {
                tg_answer_callback($id, "❌ Not Found.", true);
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel("act_menu:{$serverId}"));
                return;
            }

            if ($action === 'del_exp' || $action === 'del_lim') {
                tg_answer_callback($id);
                $title = ($action === 'del_exp') ? "Delete Expired Users" : "Delete Limited Users";
                $kb = Keyboards::adminsSelector($serverId, $admins, "confirm_act:{$action}", true);
                tg_edit_message($chatId, $messageId, "🗑️ <b>{$title}</b>\n\nSelect which admin's users to delete:", $kb);
                return;
            }

            if ($action === 'del_all') {
                tg_answer_callback($id);
                // Unlike Delete Expired / Delete Limited, this action never offers an
                // "ALL admins" wildcard - a specific admin must always be chosen.
                $kb = Keyboards::adminsSelector($serverId, $admins, "confirm_act:del_all", false);
                tg_edit_message($chatId, $messageId, "🗑️ <b>Delete All Admin Users</b>\n\nSelect which admin's users to delete:", $kb);
                return;
            }

            if ($action === 'act_adm' || $action === 'dis_adm') {
                tg_answer_callback($id);
                $title = ($action === 'act_adm') ? "Activate Admin Users" : "Disable Admin Users";
                $kb = Keyboards::adminsSelector($serverId, $admins, "confirm_act:{$action}", false);
                tg_edit_message($chatId, $messageId, "👥 <b>{$title}</b>\n\nSelect target admin:", $kb);
                return;
            }

            if ($action === 'xfer_adm') {
                tg_answer_callback($id);
                $kb = Keyboards::adminsSelector($serverId, $admins, "xfer_from", false);
                tg_edit_message($chatId, $messageId, "💱 <b>Transfer Users</b> (Step 1/2)\n\nSelect source admin (FROM):", $kb);
                return;
            }

            if ($action === 'add_cfg' || $action === 'del_cfg') {
                tg_answer_callback($id);
                $title = ($action === 'add_cfg') ? "Add Config to Users" : "Remove Config from Users";
                $kb = Keyboards::adminsSelector($serverId, $admins, "cfg_adm:{$action}", true);
                tg_edit_message($chatId, $messageId, "📂 <b>{$title}</b>\n\nSelect target admin:", $kb);
                return;
            }
        }

        // Confirm before executing a destructive/bulk admin action: confirm_act:<action>:<id>:<admin>
        if (str_starts_with($data, 'confirm_act:')) {
            $parts = explode(':', $data, 4);
            $action = $parts[1] ?? '';
            $serverId = (int)($parts[2] ?? 0);
            $admin = $parts[3] ?? 'ALL';
            tg_answer_callback($id);
            $kb = Keyboards::confirm("exec_act:{$action}:{$serverId}:{$admin}", "act_menu:{$serverId}");
            tg_edit_message($chatId, $messageId, "Are your sure?", $kb);
            return;
        }

        // Config bulk action target admin selected: cfg_adm:<action>:<id>:<admin>
        if (str_starts_with($data, 'cfg_adm:')) {
            $parts = explode(':', $data, 4);
            $action = $parts[1] ?? '';
            $serverId = (int)($parts[2] ?? 0);
            $admin = $parts[3] ?? 'ALL';
            $server = Storage::getServer($serverId);
            if (!$server) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            Storage::setState($userId, 'cfg_action_pick', ['server_id' => $serverId, 'action' => $action, 'admin' => $admin]);
            $services = PanelManager::getServices($server);
            tg_answer_callback($id);
            if (empty($services)) {
                tg_edit_message($chatId, $messageId, "❌ No configs found on this server.", Keyboards::serverMenu($serverId));
                return;
            }
            $rows = [];
            foreach ($services as $svc) {
                $name = $svc['name'] ?? ($svc['remark'] ?? ('Service #' . $svc['id']));
                $rows[] = [['text' => $name, 'callback_data' => "cfg_pick:{$serverId}:" . $svc['id']]];
            }
            $rows[] = [['text' => '« Back', 'callback_data' => "act_menu:{$serverId}"]];
            $actionLabel = ($action === 'add_cfg') ? 'Add to Users' : 'Remove from Users';
            tg_edit_message($chatId, $messageId, "📂 Select config to {$actionLabel}:", ['inline_keyboard' => $rows]);
            return;
        }

        // Config bulk action executed: cfg_pick:<id>:<service_id>
        if (str_starts_with($data, 'cfg_pick:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $serviceId = (int)($parts[2] ?? 0);
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);
            $action = $state['data']['action'] ?? '';
            $admin = $state['data']['admin'] ?? 'ALL';
            Storage::clearState($userId);
            tg_answer_callback($id, "Processing config action...");
            tg_edit_message($chatId, $messageId, "⏳ Applying config changes for admin <code>{$admin}</code>...");
            $r = PanelManager::applyConfigToUsers($server, $serviceId, $action === 'add_cfg', $admin);
            tg_edit_message(
                $chatId,
                $messageId,
                "Action Finished: {$r['success']}/{$r['total']}",
                Keyboards::serverMenu($serverId)
            );
            return;
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
                $r = PanelManager::deleteExpiredUsers($server, $admin);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Action Finished: {$r['success']}/{$r['total']}",
                    Keyboards::serverMenu($serverId)
                );
            } elseif ($action === 'del_lim') {
                $r = PanelManager::deleteLimitedUsers($server, $admin);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Action Finished: {$r['success']}/{$r['total']}",
                    Keyboards::serverMenu($serverId)
                );
            } elseif ($action === 'del_all') {
                $r = PanelManager::deleteAllAdminUsers($server, $admin);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Action Finished: {$r['success']}/{$r['total']}",
                    Keyboards::serverMenu($serverId)
                );
            } elseif ($action === 'act_adm') {
                $ok = PanelManager::activateAdminUsers($server, $admin);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    $ok ? "✅ Success." : "❌ Failed",
                    Keyboards::serverMenu($serverId)
                );
            } elseif ($action === 'dis_adm') {
                $ok = PanelManager::disableAdminUsers($server, $admin);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    $ok ? "✅ Success." : "❌ Failed",
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
            $server = Storage::getServer($serverId);
            if (!$server) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            // The destination-admin list is intentionally NOT filtered to exclude
            // the source admin, matching the original bot (which allows a same-
            // admin "transfer" as a harmless no-op rather than making the flow
            // unreachable on a single-admin server).
            $admins = PanelManager::getAdmins($server);
            if (empty($admins)) {
                tg_answer_callback($id, "❌ Not Found.", true);
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel("act_menu:{$serverId}"));
                return;
            }
            Storage::setState($userId, 'xfer_target', ['server_id' => $serverId, 'from_admin' => $fromAdmin]);

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

            $r = PanelManager::transferUsers($server, $fromAdmin, $toAdmin);
            tg_edit_message(
                $chatId,
                $messageId,
                "Action Finished: {$r['success']}/{$r['total']}",
                Keyboards::serverMenu($serverId)
            );
            return;
        }

        // Server Statistics: stats:<id>
        if (str_starts_with($data, 'stats:')) {
            $serverId = (int)substr($data, 6);
            $server = Storage::getServer($serverId);
            if (!$server) {
                tg_answer_callback($id, "❌ Not Found.", true);
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel('home'));
                return;
            }
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, "⏳");
            $stats = PanelManager::getServerStats($server);
            $card = Formatter::statsCard($server, $stats);
            tg_edit_message($chatId, $messageId, $card, Keyboards::serverMenu($serverId));
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
            if (!$tmpl) {
                tg_answer_callback($id, "❌ Not Found.", true);
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel('tmpls'));
                return;
            }
            tg_answer_callback($id);
            $isActive = !isset($tmpl['is_active']) || !empty($tmpl['is_active']);
            tg_edit_message($chatId, $messageId, Formatter::templateCard($tmpl), Keyboards::templateActions($tmplId, $isActive));
            return;
        }

        // Confirm before toggling Template is_active: tmpl_tgl_ask:<id>
        if (str_starts_with($data, 'tmpl_tgl_ask:')) {
            $tmplId = (int)substr($data, 13);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, "Are your sure?", Keyboards::confirm("tmpl_tgl_act:{$tmplId}", "tmpl_view:{$tmplId}"));
            return;
        }

        // Toggle Template is_active: tmpl_tgl_act:<id>
        if (str_starts_with($data, 'tmpl_tgl_act:')) {
            $tmplId = (int)substr($data, 13);
            $tmpl = Storage::getTemplate($tmplId);
            if (!$tmpl) {
                tg_answer_callback($id, "❌ Not Found.", true);
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel('tmpls'));
                return;
            }
            $tmpl['is_active'] = empty($tmpl['is_active']) ? 1 : 0;
            Storage::saveTemplate($tmpl);
            tg_answer_callback($id, "✅ Success.");
            tg_edit_message($chatId, $messageId, Formatter::templateCard($tmpl), Keyboards::templateActions($tmplId, !empty($tmpl['is_active'])));
            return;
        }

        // Delete Template prompt: tmpl_del_ask:<id>
        if (str_starts_with($data, 'tmpl_del_ask:')) {
            $tmplId = (int)substr($data, 13);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "⚠️ Are you sure you want to delete this template?",
                Keyboards::confirm("tmpl_del:{$tmplId}", "tmpl_view:{$tmplId}")
            );
            return;
        }

        // Delete Template confirmed: tmpl_del:<id>
        if (str_starts_with($data, 'tmpl_del:')) {
            $tmplId = (int)substr($data, 9);
            Storage::deleteTemplate($tmplId);
            tg_answer_callback($id, "Template deleted.", true);
            self::renderTemplates($chatId, $messageId);
            return;
        }

        // Edit Template Remark: tmpl_edit_remark:<id>
        if (str_starts_with($data, 'tmpl_edit_remark:')) {
            $tmplId = (int)substr($data, 17);
            Storage::setState($userId, 'tmpl_edit_remark', ['tmpl_id' => $tmplId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "✏️ <b>Edit Template Remark</b>\n\nPlease send the new remark for this template:",
                Keyboards::cancel("tmpl_view:{$tmplId}")
            );
            return;
        }

        // Edit Template Data Limit: tmpl_edit_data:<id>
        if (str_starts_with($data, 'tmpl_edit_data:')) {
            $tmplId = (int)substr($data, 15);
            Storage::setState($userId, 'tmpl_edit_data', ['tmpl_id' => $tmplId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "📊 <b>Edit Data Limit</b>\n\nPlease send the new data limit in GB (e.g. <code>50</code>, or <code>0</code> for unlimited):",
                Keyboards::cancel("tmpl_view:{$tmplId}")
            );
            return;
        }

        // Edit Template Date Limit: tmpl_edit_date:<id> - shows the date-type selector first
        if (str_starts_with($data, 'tmpl_edit_date:')) {
            $tmplId = (int)substr($data, 15);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Select a Button",
                Keyboards::templateDateTypeSelector("tmpl_edit_dt:{$tmplId}", "tmpl_view:{$tmplId}")
            );
            return;
        }

        // Template date-type chosen during ADD: tmpl_add_dt:<type>
        if (str_starts_with($data, 'tmpl_add_dt:')) {
            $type = substr($data, 12);
            $state = Storage::getState($userId);
            if (empty($state['data']['remark'])) {
                tg_answer_callback($id, "Session expired, please retry.", true);
                return;
            }
            $tmplData = $state['data'];
            $tmplData['date_type'] = $type;
            tg_answer_callback($id);
            if ($type === 'unlimited') {
                $tmplData['date_limit'] = 0;
                Storage::clearState($userId);
                Storage::saveTemplate($tmplData);
                tg_edit_message($chatId, $messageId, "✅ Success.", Keyboards::templatesMenu(Storage::getTemplates()));
            } else {
                Storage::setState($userId, 'tmpl_add_date', $tmplData);
                tg_edit_message($chatId, $messageId, "Enter DateLimit: [0-9]", Keyboards::cancel('tmpls'));
            }
            return;
        }

        // Template date-type chosen during EDIT: tmpl_edit_dt:<id>:<type>
        if (str_starts_with($data, 'tmpl_edit_dt:')) {
            $parts = explode(':', $data, 3);
            $tmplId = (int)($parts[1] ?? 0);
            $type = $parts[2] ?? 'fixed';
            $tmpl = Storage::getTemplate($tmplId);
            if (!$tmpl) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            tg_answer_callback($id);
            if ($type === 'unlimited') {
                $tmpl['date_type'] = 'unlimited';
                $tmpl['date_limit'] = 0;
                Storage::saveTemplate($tmpl);
                tg_edit_message($chatId, $messageId, Formatter::templateCard($tmpl), Keyboards::templateActions($tmplId, !empty($tmpl['is_active'])));
            } else {
                Storage::setState($userId, 'tmpl_edit_date', ['tmpl_id' => $tmplId, 'date_type' => $type]);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "⏱️ <b>Edit Date Limit</b>\n\nPlease send the new duration in days (e.g. <code>30</code>):",
                    Keyboards::cancel("tmpl_view:{$tmplId}")
                );
            }
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
            $server = Storage::getServer($serverId);
            if (!$server) {
                tg_answer_callback($id, "Server not found.", true);
                return;
            }
            $admins = PanelManager::getAdmins($server);
            if (count($admins) > 1) {
                tg_answer_callback($id);
                $kb = Keyboards::adminsSelector($serverId, $admins, 'new_usr_adm', false, "srv:{$serverId}");
                tg_edit_message($chatId, $messageId, "👤 <b>Select Admin Owner:</b>", $kb);
                return;
            }

            $admin = !empty($admins[0]) ? $admins[0] : '';
            self::renderUserCreatePrompt($chatId, $messageId, $serverId, $userId, $admin, $id);
            return;
        }

        // Admin chosen for user create: new_usr_adm:<id>:<admin>
        if (str_starts_with($data, 'new_usr_adm:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $admin = $parts[2] ?? '';
            self::renderUserCreatePrompt($chatId, $messageId, $serverId, $userId, $admin, $id);
            return;
        }

        // JSON import button clicked: new_usr_json:<id>
        if (str_starts_with($data, 'new_usr_json:')) {
            $serverId = (int)substr($data, 13);
            $state = Storage::getState($userId) ?: ['data' => []];
            $admin = $state['data']['admin'] ?? '';
            Storage::setState($userId, 'create_user_json', ['server_id' => $serverId, 'admin' => $admin]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "📁 <b>Create With JSON</b>\n\nEnter Json file: [*.json]\nPlease upload a <code>.json</code> document containing a user list:\n<code>[{\"username\": \"client_01\", \"datalimit\": 30, \"datelimit\": 30, \"datetypes\": \"now\"}]</code>",
                Keyboards::cancel("srv:{$serverId}")
            );
            return;
        }

        // Random username generated: rnd_usr:<id>
        if (str_starts_with($data, 'rnd_usr:')) {
            $serverId = (int)substr($data, 8);
            $randomName = substr(bin2hex(random_bytes(3)), 0, 6);
            $state = Storage::getState($userId) ?: ['data' => []];
            $stateData = array_merge($state['data'] ?? [], [
                'server_id' => $serverId,
                'username'  => $randomName,
            ]);
            Storage::setState($userId, 'create_user_count', $stateData);
            tg_answer_callback($id, "Generated: {$randomName}");

            tg_edit_message(
                $chatId,
                $messageId,
                "👥 <b>User Count</b>\n\nHow many accounts do you want to create? (Send <code>1</code> for single user, or <code>2</code>-<code>50</code> for batch):",
                Keyboards::cancel("srv:{$serverId}")
            );
            return;
        }

        // Single user template create: use_tmpl:<id>:<tmpl_id>
        if (str_starts_with($data, 'use_tmpl:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $tmplId = (int)($parts[2] ?? 0);
            $tmpl = Storage::getTemplate($tmplId);
            $state = Storage::getState($userId);
            if (!$tmpl || empty($state['data']['username'])) {
                tg_answer_callback($id, "Session expired, please retry.", true);
                return;
            }
            $state['data']['data_limit'] = (float)$tmpl['data_limit'];
            $state['data']['date_limit'] = (int)$tmpl['date_limit'];
            $state['data']['date_type'] = $tmpl['date_type'] ?? (($tmpl['date_limit'] > 0) ? 'fixed' : 'unlimited');
            self::renderConfigSelection($chatId, $messageId, $serverId, $userId, $state['data'], $id);
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

        // Interactive config selector toggles for user creation:
        if (str_starts_with($data, 'usr_cfg:tgl:')) {
            $parts = explode(':', $data, 4);
            $serverId = (int)($parts[2] ?? 0);
            $cfgTag = rawurldecode($parts[3] ?? '');
            $state = Storage::getState($userId);
            $selected = $state['data']['selected_configs'] ?? [];
            if (in_array($cfgTag, $selected) || in_array((int)$cfgTag, $selected)) {
                $selected = array_values(array_filter($selected, fn($s) => (string)$s !== (string)$cfgTag));
            } else {
                $selected[] = is_numeric($cfgTag) ? (int)$cfgTag : $cfgTag;
            }
            $state['data']['selected_configs'] = $selected;
            Storage::setState($userId, 'create_user_configs', $state['data']);
            $server = Storage::getServer($serverId);
            $configs = PanelManager::getServices($server);
            tg_answer_callback($id);
            $kb = Keyboards::configSelector(
                $serverId,
                $configs,
                $selected,
                'usr_cfg',
                "usr_cfg_done:{$serverId}",
                "srv:{$serverId}"
            );
            tg_edit_message($chatId, $messageId, "📂 <b>Select Configs / Inbounds:</b>\nToggle protocols for the new user:", $kb);
            return;
        }

        if (str_starts_with($data, 'usr_cfg:all:')) {
            $serverId = (int)substr($data, 12);
            $server = Storage::getServer($serverId);
            $configs = PanelManager::getServices($server);
            $selected = array_column($configs, 'id');
            $state = Storage::getState($userId);
            $state['data']['selected_configs'] = $selected;
            Storage::setState($userId, 'create_user_configs', $state['data']);
            tg_answer_callback($id);
            $kb = Keyboards::configSelector(
                $serverId,
                $configs,
                $selected,
                'usr_cfg',
                "usr_cfg_done:{$serverId}",
                "srv:{$serverId}"
            );
            tg_edit_message($chatId, $messageId, "📂 <b>Select Configs / Inbounds:</b>\nToggle protocols for the new user:", $kb);
            return;
        }

        if (str_starts_with($data, 'usr_cfg:none:')) {
            $serverId = (int)substr($data, 13);
            $server = Storage::getServer($serverId);
            $configs = PanelManager::getServices($server);
            $state = Storage::getState($userId);
            $state['data']['selected_configs'] = [];
            Storage::setState($userId, 'create_user_configs', $state['data']);
            tg_answer_callback($id);
            $kb = Keyboards::configSelector(
                $serverId,
                $configs,
                [],
                'usr_cfg',
                "usr_cfg_done:{$serverId}",
                "srv:{$serverId}"
            );
            tg_edit_message($chatId, $messageId, "📂 <b>Select Configs / Inbounds:</b>\nToggle protocols for the new user:", $kb);
            return;
        }

        if (str_starts_with($data, 'usr_cfg_done:')) {
            $serverId = (int)substr($data, 13);
            $state = Storage::getState($userId);
            if (empty($state['data'])) {
                tg_answer_callback($id, "Session expired, please retry.", true);
                return;
            }
            if (empty($state['data']['uploaded_json']) && empty($state['data']['selected_configs'])) {
                tg_answer_callback($id, "❌ Not Found Any Config.", true);
                return;
            }
            self::executeUserCreation($chatId, $messageId, $serverId, $userId, $state['data'], $id);
            return;
        }

        // Custom date type chosen during create flow: crt_dt_type:<id>:<type>
        if (str_starts_with($data, 'crt_dt_type:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $type = $parts[2] ?? 'fixed';
            $state = Storage::getState($userId);
            if (empty($state['data']['username'])) {
                tg_answer_callback($id, "Session expired, please retry.", true);
                return;
            }
            $state['data']['date_type'] = $type;
            if ($type === 'unlimited') {
                $state['data']['date_limit'] = 0;
                self::renderConfigSelection($chatId, $messageId, $serverId, $userId, $state['data'], $id);
            } elseif ($type === 'onhold') {
                Storage::setState($userId, 'create_user_expire', $state['data']);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "🚀 <b>After First Use Duration:</b>\nSend duration in days (e.g. <code>30</code>):",
                    Keyboards::cancel("srv:{$serverId}")
                );
            } else {
                Storage::setState($userId, 'create_user_expire', $state['data']);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "📅 <b>Fixed Expiration Duration:</b>\nSend duration in days from now (e.g. <code>30</code>):",
                    Keyboards::cancel("srv:{$serverId}")
                );
            }
            return;
        }

        // Check for Update: check_update
        if ($data === 'check_update') {
            require_once __DIR__ . '/../version.php';
            $currentVersion = defined('HOLDERBOT_VERSION') ? HOLDERBOT_VERSION : '0.6.0';
            $releaseInfo = @file_get_contents('https://api.github.com/repos/erfjab/holderbot/releases/latest', false,
                stream_context_create(['http' => ['header' => "User-Agent: HolderBot-PHP\r\n", 'timeout' => 5]]));
            if ($releaseInfo) {
                $release = json_decode($releaseInfo, true);
                $latest = ltrim($release['tag_name'] ?? '', 'v');
                $current = ltrim($currentVersion, 'v');
                if (version_compare($latest, $current, '>')) {
                    tg_answer_callback($id, "🎉 New version is ready!", true);
                } else {
                    tg_answer_callback($id, "You are update!", true);
                }
            } else {
                tg_answer_callback($id, "You are update!", true);
            }
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
            case 'tgl_ask':
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "⚠️ <b>Confirm Status Toggle</b>\n\nAre you sure you want to change active status for user <code>" . htmlspecialchars($username) . "</code>?",
                    Keyboards::confirm("act_confirm:tgl:{$serverId}:{$username}:yes", "act_confirm:tgl:{$serverId}:{$username}:no")
                );
                break;

            case 'chg': // Recharge user - requires at least one active template to exist
                $templates = Storage::getActiveTemplates();
                if (empty($templates)) {
                    tg_answer_callback($callbackId, "❌ Not Found.", true);
                    return;
                }
                Storage::setState($userId, 'user_mod_charge', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                $kb = Keyboards::templateSelector($serverId, $templates, 'chg_tmpl', false);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "🧪 <b>Recharge User:</b> <code>{$username}</code>\n\nSelect a template:",
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

            case 'dt': // Modify Date limit - show type selector
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "⏱️ <b>Edit Expiration:</b> <code>{$username}</code>\n\nPlease select the expiry strategy:",
                    Keyboards::dateTypeSelector($serverId, $username)
                );
                break;

            case 'cfg': // Modify Configs/Inbounds
                $services = PanelManager::getServices($server);
                $user = PanelManager::getUser($server, $username);
                if (empty($services)) {
                    tg_answer_callback($callbackId, "No configs/services found on this server.", true);
                    return;
                }
                $currentServices = $user['service_ids'] ?? [];
                Storage::setState($userId, 'user_mod_configs', [
                    'server_id'        => $serverId,
                    'username'         => $username,
                    'current_services' => $currentServices,
                ]);
                tg_answer_callback($callbackId);
                $kb = Keyboards::configSelector(
                    $serverId,
                    $services,
                    $currentServices,
                    "cfg_tgl:{$serverId}:{$username}",
                    "cfg_save:{$serverId}:{$username}",
                    "usr:{$serverId}:{$username}"
                );
                tg_edit_message($chatId, $messageId, "📂 <b>Manage Configs for</b> <code>{$username}</code>:\nToggle configs to enable or disable:", $kb);
                break;

            case 'nt': // Modify Note
                Storage::setState($userId, 'user_mod_note', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "🗒️ <b>Edit Note:</b> <code>{$username}</code>\n\nEnter note text:",
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

            case 'rst_ask':
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "⚠️ <b>Confirm Reset Usage</b>\n\nAre you sure you want to reset traffic usage for <code>" . htmlspecialchars($username) . "</code> to 0?",
                    Keyboards::confirm("act_confirm:rst:{$serverId}:{$username}:yes", "act_confirm:rst:{$serverId}:{$username}:no")
                );
                break;

            case 'rvk_ask':
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "⚠️ <b>Confirm Revoke Subscription</b>\n\nRevoking will generate a new subscription link for <code>" . htmlspecialchars($username) . "</code>. Old links will stop working. Continue?",
                    Keyboards::confirm("act_confirm:rvk:{$serverId}:{$username}:yes", "act_confirm:rvk:{$serverId}:{$username}:no")
                );
                break;

            case 'qr':
                $user = PanelManager::getUser($server, $username);
                if (!$user || empty($user['subscription_url'])) {
                    tg_answer_callback($callbackId, "No subscription link available for QR.", true);
                    return;
                }
                tg_answer_callback($callbackId, "Generating QR code...");
                QrGenerator::sendQrPhoto(
                    $chatId,
                    $user['subscription_url'],
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

    private static function renderUserCreatePrompt(
        int|string $chatId,
        int $messageId,
        int $serverId,
        int $userId,
        string $admin,
        string $callbackId
    ): void {
        Storage::setState($userId, 'create_user_name', ['server_id' => $serverId, 'admin' => $admin]);
        tg_answer_callback($callbackId);
        tg_edit_message(
            $chatId,
            $messageId,
            "➕ <b>Create New User</b>\n\nPlease send the username for the new account (e.g. <code>client_01</code>), generate a random one, or import from JSON:",
            [
                'inline_keyboard' => [
                    [
                        ['text' => '🎲 Random Username', 'callback_data' => "rnd_usr:{$serverId}"],
                        ['text' => '📁 Create With Json', 'callback_data' => "new_usr_json:{$serverId}"],
                    ],
                    [['text' => '❌ Cancel', 'callback_data' => "srv:{$serverId}"]],
                ]
            ]
        );
    }

    public static function renderConfigSelection(
        int|string $chatId,
        int $messageId,
        int $serverId,
        int $userId,
        array $stateData,
        string $callbackId
    ): void {
        $server = Storage::getServer($serverId);
        $configs = PanelManager::getServices($server);

        if (empty($configs)) {
            // Matches the original bot: creation is blocked entirely (not
            // silently attempted with zero configs) when the server has none.
            Storage::clearState($userId);
            tg_answer_callback($callbackId, "❌ Not Found Any Config.", true);
            tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel("srv:{$serverId}"));
            return;
        }

        $allConfigIds = array_column($configs, 'id');
        $stateData['selected_configs'] = $allConfigIds;
        Storage::setState($userId, 'create_user_configs', $stateData);

        tg_answer_callback($callbackId);
        $kb = Keyboards::configSelector(
            $serverId,
            $configs,
            $stateData['selected_configs'],
            'usr_cfg',
            "usr_cfg_done:{$serverId}",
            "srv:{$serverId}"
        );
        tg_edit_message($chatId, $messageId, "📂 <b>Select Configs / Inbounds:</b>\nToggle protocols for the new user:", $kb);
    }

    public static function executeUserCreation(
        int|string $chatId,
        int $messageId,
        int $serverId,
        int $userId,
        array $stateData,
        string $callbackId
    ): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_answer_callback($callbackId, "Server not found.", true);
            return;
        }

        Storage::clearState($userId);
        tg_answer_callback($callbackId, "Creating user(s)...");
        tg_edit_message($chatId, $messageId, "⏳ Creating user(s) on <b>{$server['remark']}</b>...");

        $admin = !empty($stateData['admin']) ? $stateData['admin'] : null;
        $selectedConfigs = $stateData['selected_configs'] ?? [];

        // Check if JSON bulk upload
        if (!empty($stateData['uploaded_json'])) {
            $success = 0;
            $failed = 0;
            foreach ($stateData['uploaded_json'] as $item) {
                $uName = $item['username'] ?? '';
                $uData = (float)($item['datalimit'] ?? 0);
                $uDays = (int)($item['datelimit'] ?? 0);
                $uDateType = strtolower($item['datetypes'] ?? 'now');
                if ($uDateType === 'unlimited') $uType = 'unlimited';
                elseif ($uDateType === 'after first use') $uType = 'onhold';
                else $uType = 'fixed';

                $created = PanelManager::createUser($server, $uName, $uData, $uDays, null, $selectedConfigs, $uType, $admin);
                if ($created) {
                    $success++;
                    if (!empty($created['subscription_url'])) {
                        QrGenerator::sendQrPhoto($chatId, $created['subscription_url'], Formatter::userCard($server, $created));
                    }
                } else {
                    $failed++;
                    tg_send_message($chatId, "❌ Failed to create user <code>{$uName}</code>.");
                }
            }
            tg_send_message($chatId, "✅ <b>JSON Import Complete!</b>\nCreated: <code>{$success}</code>\nFailed: <code>{$failed}</code>", Keyboards::serverMenu($serverId));
            return;
        }

        $count = max(1, (int)($stateData['count'] ?? 1));
        $baseName = $stateData['username'] ?? 'user';
        $suffixStart = (int)($stateData['usersuffix'] ?? 1);
        $dataLimit = (float)($stateData['data_limit'] ?? 0);
        $expireDays = (int)($stateData['date_limit'] ?? 0);
        $dateType = $stateData['date_type'] ?? 'fixed';

        $createdList = [];
        for ($i = 0; $i < $count; $i++) {
            $uname = ($count === 1) ? $baseName : ($baseName . ($suffixStart + $i));
            $created = PanelManager::createUser($server, $uname, $dataLimit, $expireDays, null, $selectedConfigs, $dateType, $admin);
            if ($created) {
                $createdList[] = $created;
                if (!empty($created['subscription_url'])) {
                    QrGenerator::sendQrPhoto($chatId, $created['subscription_url'], "🎉 <b>User Created!</b>\n\n" . Formatter::userCard($server, $created));
                }
            } else {
                tg_send_message($chatId, "❌ Failed to create user <code>" . htmlspecialchars($uname) . "</code>.");
            }
        }

        if (empty($createdList)) {
            $err = PanelManager::getLastError($server);
            $errText = $err ? "\n<b>Reason:</b> <code>" . htmlspecialchars($err) . "</code>" : '';
            tg_send_message($chatId, "❌ <b>Error:</b> Failed to create user(s) on panel.{$errText}", Keyboards::serverMenu($serverId));
        } else {
            tg_send_message($chatId, "✅ Successfully created <code>" . count($createdList) . "</code> user(s) on <b>{$server['remark']}</b>.", Keyboards::serverMenu($serverId));
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

    private static function renderTemplates(int|string $chatId, int $messageId): void {
        $templates = Storage::getTemplates();
        $text = "📋 <b>Templates Management</b>\n\nSelect a template to view or delete, or add a new one:";
        $kb = Keyboards::templatesMenu($templates);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }
}
