<?php
/**
 * HolderBot PHP - Complete FSM / Multi-step Wizard Handlers
 */

declare(strict_types=1);

class StateHandlers {
    /**
     * Process message from user when in an active conversation state.
     */
    public static function handle(array $message, array $state): bool {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = trim($message['text'] ?? '');
        $step = $state['step'] ?? '';
        $data = $state['data'] ?? [];

        switch ($step) {
            // User search & create
            case 'search_user':
                return self::handleSearchUser($chatId, $userId, $text, $data);

            case 'create_user_name':
                return self::handleCreateUserName($chatId, $userId, $text, $data);

            case 'create_user_data':
                return self::handleCreateUserData($chatId, $userId, $text, $data);

            case 'create_user_expire':
                return self::handleCreateUserExpire($chatId, $userId, $text, $data);

            // User modifications
            case 'user_mod_datalimit':
                return self::handleUserModDataLimit($chatId, $userId, $text, $data);

            case 'user_mod_datelimit':
                return self::handleUserModDateLimit($chatId, $userId, $text, $data);

            case 'user_mod_note':
                return self::handleUserModNote($chatId, $userId, $text, $data);

            // Custom recharge flow
            case 'charge_custom_data':
                return self::handleChargeCustomData($chatId, $userId, $text, $data);

            case 'charge_custom_date':
                return self::handleChargeCustomDate($chatId, $userId, $text, $data);

            // Bulk user creation
            case 'bulk_count':
                return self::handleBulkCount($chatId, $userId, $text, $data);

            case 'bulk_prefix':
                return self::handleBulkPrefix($chatId, $userId, $text, $data);

            // Template creation
            case 'tmpl_add_remark':
                return self::handleTmplAddRemark($chatId, $userId, $text);

            case 'tmpl_add_data':
                return self::handleTmplAddData($chatId, $userId, $text, $data);

            case 'tmpl_add_date':
                return self::handleTmplAddDate($chatId, $userId, $text, $data);

            // Server creation
            case 'add_server_remark':
                return self::handleAddServerRemark($chatId, $userId, $text);

            case 'add_server_type':
                return self::handleAddServerType($chatId, $userId, $text, $data);

            case 'add_server_url':
                return self::handleAddServerUrl($chatId, $userId, $text, $data);

            case 'add_server_user':
                return self::handleAddServerUser($chatId, $userId, $text, $data);

            case 'add_server_pass':
                return self::handleAddServerPass($chatId, $userId, $text, $data);

            default:
                Storage::clearState($userId);
                return false;
        }
    }

    // =========================================================================
    // User Creation & Search Handlers
    // =========================================================================

    private static function handleSearchUser(int|string $chatId, int $userId, string $query, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $server = Storage::getServer($serverId);
        if (!$server) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Server not found.");
            return true;
        }

        $user = PanelManager::getUser($server, $query);
        if ($user) {
            Storage::clearState($userId);
            $card = Formatter::userCard($server, $user);
            $kb = Keyboards::userActions($serverId, $user['username'], $user['is_active'], $user['status']);
            tg_send_message($chatId, $card, $kb);
            return true;
        }

        $results = PanelManager::getUsers($server, 1, 10, $query);
        if (!empty($results)) {
            Storage::clearState($userId);
            $kb = Keyboards::usersList($serverId, $results, 1, false);
            tg_send_message($chatId, "🔍 Search results for <code>" . htmlspecialchars($query) . "</code>:", $kb);
            return true;
        }

        tg_send_message(
            $chatId,
            "❌ No user matching <code>" . htmlspecialchars($query) . "</code> was found.\nPlease send another username or cancel:",
            Keyboards::cancel("srv:{$serverId}")
        );
        return true;
    }

    private static function handleCreateUserName(int|string $chatId, int $userId, string $username, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $server = Storage::getServer($serverId);
        if (!$server) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Server not found.");
            return true;
        }

        $cleanUsername = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
        if (strlen($cleanUsername) < 3) {
            tg_send_message(
                $chatId,
                "⚠️ Username must be at least 3 characters (letters, numbers, underscores).\nPlease enter a valid username:",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        $existing = PanelManager::getUser($server, $cleanUsername);
        if ($existing) {
            tg_send_message(
                $chatId,
                "⚠️ User <code>{$cleanUsername}</code> already exists on <b>{$server['remark']}</b>!\nPlease enter a different username:",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        $data['username'] = $cleanUsername;
        Storage::setState($userId, 'create_user_data', $data);

        $templates = Storage::getTemplates();
        $text = "👤 Selected username: <code>{$cleanUsername}</code>\n\n";
        $text .= "Choose a predefined template or send the <b>Data Limit in GB</b> (e.g. <code>30</code>, or <code>0</code> for unlimited):";

        $kb = Keyboards::templateSelector($serverId, $templates);
        tg_send_message($chatId, $text, $kb);
        return true;
    }

    private static function handleCreateUserData(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        if (!is_numeric($input) || (float)$input < 0) {
            tg_send_message(
                $chatId,
                "⚠️ Please send a valid numeric limit in GB (e.g. <code>50</code>, or <code>0</code> for unlimited):",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        $data['data_limit'] = (float)$input;
        Storage::setState($userId, 'create_user_expire', $data);

        tg_send_message(
            $chatId,
            "📊 Data Limit: <b>" . ($data['data_limit'] > 0 ? "{$data['data_limit']} GB" : "Unlimited") . "</b>\n\nNow send the <b>Expiration Duration in Days</b> (e.g. <code>30</code>, or <code>0</code> for unlimited):",
            Keyboards::cancel("srv:{$serverId}")
        );
        return true;
    }

    private static function handleCreateUserExpire(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $server = Storage::getServer($serverId);

        if (!is_numeric($input) || (int)$input < 0) {
            tg_send_message(
                $chatId,
                "⚠️ Please send a valid number of days (e.g. <code>30</code>, or <code>0</code> for unlimited):",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        $expireDays = (int)$input;
        $username = $data['username'];
        $dataLimit = (float)$data['data_limit'];

        Storage::clearState($userId);

        tg_send_message($chatId, "⏳ Creating user <code>{$username}</code> on <b>{$server['remark']}</b>...");

        $created = PanelManager::createUser($server, $username, $dataLimit, $expireDays);

        if ($created) {
            $card = "🎉 <b>User Created Successfully!</b>\n\n" . Formatter::userCard($server, $created);
            $kb = Keyboards::userActions($serverId, $created['username'], true, 'active');
            tg_send_message($chatId, $card, $kb);
        } else {
            tg_send_message(
                $chatId,
                "❌ <b>Error:</b> Failed to create user <code>{$username}</code> on panel.",
                Keyboards::serverMenu($serverId)
            );
        }

        return true;
    }

    // =========================================================================
    // User Property Modification Handlers
    // =========================================================================

    private static function handleUserModDataLimit(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';
        $server = Storage::getServer($serverId);

        if (!is_numeric($input) || (float)$input < 0) {
            tg_send_message($chatId, "⚠️ Please send a valid numeric limit in GB:", Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }

        Storage::clearState($userId);
        PanelManager::modifyUserDataLimit($server, $username, (float)$input);

        $user = PanelManager::getUser($server, $username);
        $card = "✅ <b>Data limit updated!</b>\n\n" . Formatter::userCard($server, $user);
        $kb = Keyboards::userActions($serverId, $username, $user['is_active'], $user['status']);
        tg_send_message($chatId, $card, $kb);
        return true;
    }

    private static function handleUserModDateLimit(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';
        $server = Storage::getServer($serverId);

        if (!is_numeric($input) || (int)$input < 0) {
            tg_send_message($chatId, "⚠️ Please send a valid duration in days from now:", Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }

        Storage::clearState($userId);
        PanelManager::modifyUserDateLimit($server, $username, (int)$input);

        $user = PanelManager::getUser($server, $username);
        $card = "✅ <b>Expiration date updated!</b>\n\n" . Formatter::userCard($server, $user);
        $kb = Keyboards::userActions($serverId, $username, $user['is_active'], $user['status']);
        tg_send_message($chatId, $card, $kb);
        return true;
    }

    private static function handleUserModNote(int|string $chatId, int $userId, string $note, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';
        $server = Storage::getServer($serverId);

        $cleanNote = ($note === '-') ? '' : $note;

        Storage::clearState($userId);
        PanelManager::modifyUserNote($server, $username, $cleanNote);

        $user = PanelManager::getUser($server, $username);
        $card = "✅ <b>Note updated!</b>\n\n" . Formatter::userCard($server, $user);
        $kb = Keyboards::userActions($serverId, $username, $user['is_active'], $user['status']);
        tg_send_message($chatId, $card, $kb);
        return true;
    }

    private static function handleChargeCustomData(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';

        if (!is_numeric($input) || (float)$input < 0) {
            tg_send_message($chatId, "⚠️ Please enter a valid number of GB:", Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }

        $data['data_limit'] = (float)$input;
        Storage::setState($userId, 'charge_custom_date', $data);

        tg_send_message(
            $chatId,
            "⏱️ Now send the <b>Expiration duration in Days</b> to add (e.g. <code>30</code>, or <code>0</code> for unlimited):",
            Keyboards::cancel("usr:{$serverId}:{$username}")
        );
        return true;
    }

    private static function handleChargeCustomDate(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';

        if (!is_numeric($input) || (int)$input < 0) {
            tg_send_message($chatId, "⚠️ Please enter a valid number of days:", Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }

        $data['date_limit'] = (int)$input;
        Storage::setState($userId, 'charge_confirm_reset', $data);

        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => '🔁 Yes, Reset Usage', 'callback_data' => "chg_rst_opt:{$serverId}:1"],
                    ['text' => 'Keep Usage', 'callback_data' => "chg_rst_opt:{$serverId}:0"],
                ],
                [['text' => '❌ Cancel', 'callback_data' => "usr:{$serverId}:{$username}"]],
            ]
        ];

        tg_send_message($chatId, "Do you want to reset used traffic to 0?", $kb);
        return true;
    }

    // =========================================================================
    // Bulk Creation Handlers
    // =========================================================================

    private static function handleBulkCount(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $count = (int)$input;

        if ($count < 1 || $count > 50) {
            tg_send_message(
                $chatId,
                "⚠️ Please enter a count between 1 and 50:",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        $data['count'] = $count;
        Storage::setState($userId, 'bulk_prefix', $data);

        tg_send_message(
            $chatId,
            "📦 <b>Bulk Creation</b> (Step 2/3)\n\nEnter a prefix for usernames (e.g. <code>user</code> or <code>vip</code>):",
            Keyboards::cancel("srv:{$serverId}")
        );
        return true;
    }

    private static function handleBulkPrefix(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $input);

        if (strlen($prefix) < 2) {
            tg_send_message($chatId, "⚠️ Prefix must be at least 2 characters:", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        $data['prefix'] = $prefix;
        Storage::setState($userId, 'bulk_template', $data);

        $templates = Storage::getTemplates();
        $text = "📦 <b>Bulk Creation</b> (Step 3/3)\nCreating <code>{$data['count']}</code> users with prefix <code>{$prefix}</code>.\n\nSelect template:";
        $kb = Keyboards::templateSelector($serverId, $templates, 'bulk_tmpl');
        tg_send_message($chatId, $text, $kb);
        return true;
    }

    // =========================================================================
    // Template Creation Handlers
    // =========================================================================

    private static function handleTmplAddRemark(int|string $chatId, int $userId, string $remark): bool {
        if (strlen($remark) < 2) {
            tg_send_message($chatId, "⚠️ Remark is too short. Enter template remark:", Keyboards::cancel('tmpls'));
            return true;
        }

        Storage::setState($userId, 'tmpl_add_data', ['remark' => $remark]);
        tg_send_message($chatId, "📊 Enter Data Limit in GB (e.g. <code>50</code>):", Keyboards::cancel('tmpls'));
        return true;
    }

    private static function handleTmplAddData(int|string $chatId, int $userId, string $input, array $data): bool {
        if (!is_numeric($input) || (int)$input <= 0) {
            tg_send_message($chatId, "⚠️ Enter a valid positive number of GB:", Keyboards::cancel('tmpls'));
            return true;
        }

        $data['data_limit'] = (int)$input;
        Storage::setState($userId, 'tmpl_add_date', $data);
        tg_send_message($chatId, "⏱️ Enter Expiration in Days (e.g. <code>30</code>):", Keyboards::cancel('tmpls'));
        return true;
    }

    private static function handleTmplAddDate(int|string $chatId, int $userId, string $input, array $data): bool {
        if (!is_numeric($input) || (int)$input <= 0) {
            tg_send_message($chatId, "⚠️ Enter a valid positive number of days:", Keyboards::cancel('tmpls'));
            return true;
        }

        $data['date_limit'] = (int)$input;
        Storage::clearState($userId);

        Storage::saveTemplate($data);
        tg_send_message(
            $chatId,
            "✅ <b>Template Created!</b>\n\n• <b>Remark:</b> <code>{$data['remark']}</code>\n• <b>Limit:</b> <code>{$data['data_limit']} GB</code>\n• <b>Days:</b> <code>{$data['date_limit']} Days</code>",
            Keyboards::templatesMenu(Storage::getTemplates())
        );
        return true;
    }

    // =========================================================================
    // Add Server Wizard Handlers
    // =========================================================================

    private static function handleAddServerRemark(int|string $chatId, int $userId, string $remark): bool {
        if (strlen($remark) < 2) {
            tg_send_message($chatId, "⚠️ Remark is too short. Please send a friendly server name:", Keyboards::cancel('home'));
            return true;
        }

        Storage::setState($userId, 'add_server_type', ['remark' => $remark]);
        tg_send_message(
            $chatId,
            "⚙️ <b>Server Type</b> (Step 2/5)\n\nPlease send <code>marzban</code> or <code>marzneshin</code>:",
            Keyboards::cancel('home')
        );
        return true;
    }

    private static function handleAddServerType(int|string $chatId, int $userId, string $type, array $data): bool {
        $cleanType = strtolower(trim($type));
        if (!in_array($cleanType, ['marzban', 'marzneshin'])) {
            tg_send_message($chatId, "⚠️ Invalid type. Please send either <code>marzban</code> or <code>marzneshin</code>:", Keyboards::cancel('home'));
            return true;
        }

        $data['type'] = $cleanType;
        Storage::setState($userId, 'add_server_url', $data);

        tg_send_message(
            $chatId,
            "🌐 <b>Panel Base URL</b> (Step 3/5)\n\nPlease send the panel full URL including port:\nExample: <code>https://panel.example.com:8000</code>",
            Keyboards::cancel('home')
        );
        return true;
    }

    private static function handleAddServerUrl(int|string $chatId, int $userId, string $url, array $data): bool {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            tg_send_message($chatId, "⚠️ URL must start with <code>http://</code> or <code>https://</code>. Please resend:", Keyboards::cancel('home'));
            return true;
        }

        $data['base_url'] = rtrim($url, '/');
        Storage::setState($userId, 'add_server_user', $data);

        tg_send_message(
            $chatId,
            "👤 <b>Admin Username</b> (Step 4/5)\n\nPlease send the panel administrator username (e.g. <code>admin</code>):",
            Keyboards::cancel('home')
        );
        return true;
    }

    private static function handleAddServerUser(int|string $chatId, int $userId, string $username, array $data): bool {
        $data['username'] = trim($username);
        Storage::setState($userId, 'add_server_pass', $data);

        tg_send_message(
            $chatId,
            "🔑 <b>Admin Password</b> (Step 5/5)\n\nPlease send the panel administrator password:",
            Keyboards::cancel('home')
        );
        return true;
    }

    private static function handleAddServerPass(int|string $chatId, int $userId, string $password, array $data): bool {
        $data['password'] = trim($password);
        $data['is_active'] = 1;
        $data['node_monitoring'] = 1;

        Storage::clearState($userId);

        tg_send_message($chatId, "🔄 Testing panel credentials and connectivity...");

        $serverId = Storage::saveServer($data);
        $server = Storage::getServer($serverId);

        $token = ($server['type'] === 'marzneshin')
            ? MarzneshinClient::getToken($server)
            : MarzbanClient::getToken($server);

        if ($token) {
            tg_send_message(
                $chatId,
                "✅ <b>Server Added Successfully!</b>\n\nConnection verified to <b>{$server['remark']}</b> ({$server['type']}).",
                Keyboards::serverMenu($serverId)
            );
        } else {
            tg_send_message(
                $chatId,
                "⚠️ <b>Server Saved</b>, but authentication failed.\nPlease verify the credentials and URL in the panel settings.",
                Keyboards::serverMenu($serverId)
            );
        }

        return true;
    }
}
