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

            case 'create_user_count':
                return self::handleCreateUserCount($chatId, $userId, $text, $data);

            case 'create_user_suffix':
                return self::handleCreateUserSuffix($chatId, $userId, $text, $data);

            case 'create_user_json':
                return self::handleCreateUserJson($chatId, $userId, $message, $data);

            case 'create_user_data':
                return self::handleCreateUserData($chatId, $userId, $text, $data);

            case 'create_user_expire':
                return self::handleCreateUserExpire($chatId, $userId, $text, $data);

            // User modifications
            case 'user_mod_datalimit':
                return self::handleUserModDataLimit($chatId, $userId, $text, $data);

            case 'user_mod_datelimit':
                return self::handleUserModDateLimit($chatId, $userId, $text, $data);

            case 'user_mod_datelimit_onhold':
                return self::handleUserModDateLimitOnhold($chatId, $userId, $text, $data);

            case 'user_mod_note':
                return self::handleUserModNote($chatId, $userId, $text, $data);

            // Template creation & editing
            case 'tmpl_add_remark':
                return self::handleTmplAddRemark($chatId, $userId, $text);

            case 'tmpl_add_data':
                return self::handleTmplAddData($chatId, $userId, $text, $data);

            case 'tmpl_add_date':
                return self::handleTmplAddDate($chatId, $userId, $text, $data);

            case 'tmpl_edit_remark':
                return self::handleTmplEditRemark($chatId, $userId, $text, $data);

            case 'tmpl_edit_data':
                return self::handleTmplEditData($chatId, $userId, $text, $data);

            case 'tmpl_edit_date':
                return self::handleTmplEditDate($chatId, $userId, $text, $data);

            // Server modification
            case 'srv_edit_remark':
                return self::handleSrvEditRemark($chatId, $userId, $text, $data);

            case 'srv_edit_creds':
                return self::handleSrvEditCreds($chatId, $userId, $text, $data);

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

    /**
     * Always renders a results list, even on an exact match, and never reports
     * "not found" - an empty list is shown instead. Matches the original bot's
     * search behavior exactly.
     */
    private static function handleSearchUser(int|string $chatId, int $userId, string $query, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $server = Storage::getServer($serverId);
        if (!$server) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Not Found.");
            return true;
        }

        Storage::clearState($userId);
        $results = PanelManager::getUsers($server, 1, 10, $query);
        $kb = Keyboards::usersList($serverId, $results, 1, false);
        tg_send_message($chatId, "📋 <b>Select items</b>", $kb);
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

        // Matches the original bot exactly: only a length check, no charset
        // filtering - the raw text is used as-is (the panel API is the actual
        // source of truth for whether a username is valid).
        if (strlen($username) <= 3) {
            tg_send_message(
                $chatId,
                "❌ Invalid, Just use [a-z]",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        $data['username'] = $username;
        Storage::setState($userId, 'create_user_count', $data);

        tg_send_message(
            $chatId,
            "👥 <b>User Count</b>\n\nHow many accounts do you want to create? (Send <code>1</code> for single user, or <code>2</code>-<code>50</code> for batch):",
            Keyboards::cancel("srv:{$serverId}")
        );
        return true;
    }

    private static function handleCreateUserCount(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        if (!ctype_digit($input) || (int)$input < 1) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        $count = (int)$input;
        $data['count'] = $count;

        if ($count > 1) {
            Storage::setState($userId, 'create_user_suffix', $data);
            tg_send_message(
                $chatId,
                "🔢 <b>Starting Suffix</b>\n\nEnter the starting number suffix (e.g. <code>1</code> to create {$data['username']}1, {$data['username']}2...):",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        // Single user
        $templates = Storage::getActiveTemplates();
        if (!empty($templates)) {
            Storage::setState($userId, 'create_user_template', $data);
            $text = "👤 Selected username: <code>{$data['username']}</code>\n\nChoose a template or choose custom limits:";
            $kb = Keyboards::templateSelector($serverId, $templates);
            tg_send_message($chatId, $text, $kb);
            return true;
        }

        Storage::setState($userId, 'create_user_data', $data);
        tg_send_message(
            $chatId,
            "📊 Send the <b>Data Limit in GB</b> (e.g. <code>50</code>, or <code>0</code> for unlimited):",
            Keyboards::cancel("srv:{$serverId}")
        );
        return true;
    }

    private static function handleCreateUserSuffix(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        if (!ctype_digit($input) || (int)$input < 1) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        $data['usersuffix'] = (int)$input;
        $templates = Storage::getActiveTemplates();
        if (!empty($templates)) {
            Storage::setState($userId, 'create_user_template', $data);
            $text = "👥 Creating <code>{$data['count']}</code> users starting from <code>{$data['username']}{$data['usersuffix']}</code>.\n\nChoose a template or select custom limits:";
            $kb = Keyboards::templateSelector($serverId, $templates);
            tg_send_message($chatId, $text, $kb);
            return true;
        }

        Storage::setState($userId, 'create_user_data', $data);
        tg_send_message(
            $chatId,
            "📊 Send the <b>Data Limit in GB</b> (e.g. <code>50</code>, or <code>0</code> for unlimited):",
            Keyboards::cancel("srv:{$serverId}")
        );
        return true;
    }

    private static function handleCreateUserJson(int|string $chatId, int $userId, array $message, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $doc = $message['document'] ?? null;
        if (!$doc) {
            tg_send_message($chatId, "❌ Invalid, Just send doc [*.json]:", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        $fileName = strtolower($doc['file_name'] ?? '');
        if (!str_ends_with($fileName, '.json')) {
            tg_send_message($chatId, "❌ Invalid, Just use [*.json]:", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        $content = tg_download_file($doc['file_id']);
        if (!$content) {
            tg_send_message($chatId, "❌ Could not download document from Telegram.", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        $parsed = json_decode($content, true);
        if (!is_array($parsed) || empty($parsed)) {
            tg_send_message($chatId, "❌ Invalid json.", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        // Every entry must be fully valid or the whole file is rejected.
        $validDateTypes = ['unlimited', 'now', 'after first use'];
        foreach ($parsed as $item) {
            if (
                !is_array($item)
                || empty($item['username']) || !is_string($item['username'])
                || !isset($item['datalimit']) || !is_numeric($item['datalimit'])
                || !isset($item['datelimit']) || !is_numeric($item['datelimit'])
                || empty($item['datetypes']) || !in_array(strtolower((string)$item['datetypes']), $validDateTypes, true)
            ) {
                tg_send_message($chatId, "❌ Invalid json.", Keyboards::cancel("srv:{$serverId}"));
                return true;
            }
        }

        $data['uploaded_json'] = $parsed;
        $count = count($parsed);
        tg_send_message($chatId, "✅ Found <code>{$count}</code> users in JSON document.");

        require_once __DIR__ . '/callbacks.php';
        CallbackHandlers::renderConfigSelection($chatId, 0, $serverId, $userId, $data, 'json_ready');
        return true;
    }

    private static function handleCreateUserData(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        if (!ctype_digit($input)) {
            tg_send_message(
                $chatId,
                "❌ Invalid, Just use [0-9]\n0 for unlimited",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        $data['data_limit'] = (int)$input;
        Storage::setState($userId, 'create_user_date_type', $data);

        $uName = $data['username'] ?? 'user';
        $kb = Keyboards::dateTypeSelector($serverId, $uName, 'crt_dt_type');
        tg_send_message(
            $chatId,
            "📊 Data Limit: <b>" . ($data['data_limit'] > 0 ? "{$data['data_limit']} GB" : "Unlimited") . "</b>\n\nSelect Expiry Strategy:",
            $kb
        );
        return true;
    }

    private static function handleCreateUserExpire(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $server = Storage::getServer($serverId);

        if (!ctype_digit($input)) {
            tg_send_message(
                $chatId,
                "❌ Invalid, Just use [0-9]",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        $data['date_limit'] = (int)$input;
        if (empty($data['date_type'])) {
            $data['date_type'] = ($data['date_limit'] > 0) ? 'fixed' : 'unlimited';
        }

        require_once __DIR__ . '/callbacks.php';
        CallbackHandlers::renderConfigSelection($chatId, 0, $serverId, $userId, $data, 'expire_ready');
        return true;
    }

    // =========================================================================
    // User Property Modification Handlers
    // =========================================================================

    private static function handleUserModDataLimit(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';
        $server = Storage::getServer($serverId);

        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]\n0 for unlimited", Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }

        Storage::clearState($userId);
        PanelManager::modifyUserDataLimit($server, $username, (int)$input);

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

        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel("usr:{$serverId}:{$username}"));
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

        if ($note === '' || mb_strlen($note) > 500) {
            tg_send_message($chatId, "❌ Invalid, Just use [a-z]", Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }

        Storage::clearState($userId);
        PanelManager::modifyUserNote($server, $username, $note);

        $user = PanelManager::getUser($server, $username);
        $card = "✅ <b>Note updated!</b>\n\n" . Formatter::userCard($server, $user);
        $kb = Keyboards::userActions($serverId, $username, $user['is_active'], $user['status']);
        tg_send_message($chatId, $card, $kb);
        return true;
    }

    // =========================================================================
    // Template Creation Handlers
    // =========================================================================

    private static function handleTmplAddRemark(int|string $chatId, int $userId, string $remark): bool {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $remark)) {
            tg_send_message($chatId, "❌ Invalid, Just use [a-z]", Keyboards::cancel('tmpls'));
            return true;
        }

        $remark = strtolower($remark);
        foreach (Storage::getTemplates() as $t) {
            if (strtolower($t['remark']) === $remark) {
                tg_send_message($chatId, "❌ Duplicate, try another.", Keyboards::cancel('tmpls'));
                return true;
            }
        }

        Storage::setState($userId, 'tmpl_add_data', ['remark' => $remark]);
        tg_send_message($chatId, "Enter DataLimit: [0-9]\n0 for unlimited", Keyboards::cancel('tmpls'));
        return true;
    }

    private static function handleTmplAddData(int|string $chatId, int $userId, string $input, array $data): bool {
        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]\n0 for unlimited", Keyboards::cancel('tmpls'));
            return true;
        }

        $data['data_limit'] = (int)$input;
        Storage::setState($userId, 'tmpl_add_datetype', $data);
        tg_send_message(
            $chatId,
            "Select a Button",
            Keyboards::templateDateTypeSelector('tmpl_add_dt', 'tmpls')
        );
        return true;
    }

    private static function handleTmplAddDate(int|string $chatId, int $userId, string $input, array $data): bool {
        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel('tmpls'));
            return true;
        }

        $data['date_limit'] = (int)$input;
        Storage::clearState($userId);

        Storage::saveTemplate($data);
        tg_send_message(
            $chatId,
            "✅ Success.",
            Keyboards::templatesMenu(Storage::getTemplates())
        );
        return true;
    }

    // =========================================================================
    // Add Server Wizard Handlers
    // =========================================================================

    private static function handleAddServerRemark(int|string $chatId, int $userId, string $remark): bool {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $remark)) {
            tg_send_message($chatId, "❌ Invalid, Just use [a-z]", Keyboards::cancel('home'));
            return true;
        }

        $remark = strtolower($remark);
        foreach (Storage::getServers() as $s) {
            if (strtolower($s['remark']) === $remark) {
                tg_send_message($chatId, "❌ Duplicate, try another.", Keyboards::cancel('home'));
                return true;
            }
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
        Storage::clearState($userId);

        // Verify credentials (and, for Marzneshin, sudo privilege) BEFORE persisting
        // anything - a server is never saved on failed authentication.
        $token = ($data['type'] === 'marzneshin')
            ? MarzneshinClient::getToken($data)
            : MarzbanClient::getToken($data);

        if (!$token) {
            tg_send_message($chatId, "❌ Invalid data.", Keyboards::cancel('home'));
            return true;
        }

        $serverId = Storage::saveServer($data);
        $server = Storage::getServer($serverId);

        tg_send_message(
            $chatId,
            "✅ Success.",
            Keyboards::serverMenu($serverId)
        );

        return true;
    }

    private static function handleTmplEditRemark(int|string $chatId, int $userId, string $remark, array $data): bool {
        $tmplId = (int)($data['tmpl_id'] ?? 0);
        $tmpl = Storage::getTemplate($tmplId);
        if (!$tmpl) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Template not found.");
            return true;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $remark)) {
            tg_send_message($chatId, "❌ Invalid, Just use [a-z]", Keyboards::cancel("tmpl_view:{$tmplId}"));
            return true;
        }
        $remark = strtolower($remark);
        foreach (Storage::getTemplates() as $t) {
            if ((int)$t['id'] !== $tmplId && strtolower($t['remark']) === $remark) {
                tg_send_message($chatId, "❌ Duplicate, try another.", Keyboards::cancel("tmpl_view:{$tmplId}"));
                return true;
            }
        }
        $tmpl['remark'] = $remark;
        Storage::saveTemplate($tmpl);
        Storage::clearState($userId);
        tg_send_message($chatId, "✅ Template remark updated to: <code>" . htmlspecialchars($tmpl['remark']) . "</code>",
            Keyboards::templateActions($tmplId, !empty($tmpl['is_active'])));
        return true;
    }

    private static function handleTmplEditData(int|string $chatId, int $userId, string $input, array $data): bool {
        $tmplId = (int)($data['tmpl_id'] ?? 0);
        $tmpl = Storage::getTemplate($tmplId);
        if (!$tmpl) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Template not found.");
            return true;
        }
        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]\n0 for unlimited", Keyboards::cancel("tmpl_view:{$tmplId}"));
            return true;
        }
        $tmpl['data_limit'] = (int)$input;
        Storage::saveTemplate($tmpl);
        Storage::clearState($userId);
        $dlText = ($tmpl['data_limit'] > 0) ? "{$tmpl['data_limit']}GB" : 'Unlimited';
        tg_send_message($chatId, "✅ Data limit updated to: <code>{$dlText}</code>",
            Keyboards::templateActions($tmplId, !empty($tmpl['is_active'])));
        return true;
    }

    private static function handleTmplEditDate(int|string $chatId, int $userId, string $input, array $data): bool {
        $tmplId = (int)($data['tmpl_id'] ?? 0);
        $tmpl = Storage::getTemplate($tmplId);
        if (!$tmpl) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Template not found.");
            return true;
        }
        if (!ctype_digit($input) || (int)$input < 1) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel("tmpl_view:{$tmplId}"));
            return true;
        }
        $tmpl['date_limit'] = (int)$input;
        $tmpl['date_type'] = $data['date_type'] ?? 'fixed';
        Storage::saveTemplate($tmpl);
        Storage::clearState($userId);
        tg_send_message($chatId, "✅ Success.",
            Keyboards::templateActions($tmplId, !empty($tmpl['is_active'])));
        return true;
    }

    private static function handleSrvEditRemark(int|string $chatId, int $userId, string $remark, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $server = Storage::getServer($serverId);
        if (!$server) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Server not found.");
            return true;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $remark)) {
            tg_send_message($chatId, "❌ Invalid, Just use [a-z]", Keyboards::cancel("srv_cfg:{$serverId}"));
            return true;
        }
        $remark = strtolower($remark);
        foreach (Storage::getServers() as $s) {
            if ((int)$s['id'] !== $serverId && strtolower($s['remark']) === $remark) {
                tg_send_message($chatId, "❌ Duplicate, try another.", Keyboards::cancel("srv_cfg:{$serverId}"));
                return true;
            }
        }
        $server['remark'] = $remark;
        Storage::saveServer($server);
        Storage::clearState($userId);
        tg_send_message($chatId, "✅ Server remark updated to: <code>" . htmlspecialchars($server['remark']) . "</code>",
            Keyboards::serverSettings($server));
        return true;
    }

    private static function handleSrvEditCreds(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $server = Storage::getServer($serverId);
        if (!$server) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Server not found.");
            return true;
        }
        $parts = preg_split('/\s+/', trim($input), 3);
        if (count($parts) < 3) {
            tg_send_message($chatId,
                "⚠️ Invalid format. Please send:\n<code>admin_username admin_password https://panel.url:port</code>",
                Keyboards::cancel("srv_cfg:{$serverId}")
            );
            return true;
        }

        // Verify the NEW credentials (sudo-checked) BEFORE persisting anything,
        // matching the original bot - a bad edit never overwrites a working
        // server. Testing without 'id' forces a fresh (uncached) login attempt
        // rather than reusing a token cached under this server's id.
        $candidate = $server;
        unset($candidate['id']);
        $candidate['username'] = $parts[0];
        $candidate['password'] = $parts[1];
        $candidate['base_url'] = rtrim($parts[2], '/');

        $token = (strtolower($candidate['type']) === 'marzneshin')
            ? MarzneshinClient::getToken($candidate)
            : MarzbanClient::getToken($candidate);

        if (!$token) {
            tg_send_message($chatId, "❌ Invalid data.", Keyboards::cancel("srv_cfg:{$serverId}"));
            return true;
        }

        $server['username'] = $parts[0];
        $server['password'] = $parts[1];
        $server['base_url'] = rtrim($parts[2], '/');
        $server['cached_token'] = null;
        $server['token_expires_at'] = null;
        Storage::saveServer($server);
        Storage::clearState($userId);
        tg_send_message($chatId, "✅ Success.",
            Keyboards::serverSettings($server));
        return true;
    }

    private static function handleUserModDateLimitOnhold(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';
        $server = Storage::getServer($serverId);
        if (!ctype_digit($input) || (int)$input <= 0) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]",
                Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }
        Storage::clearState($userId);
        $ok = PanelManager::updateDateLimit($server, $username, (int)$input, 'onhold');
        tg_send_message($chatId,
            $ok ? "✅ Success." : "❌ Failed",
            Keyboards::cancel("usr:{$serverId}:{$username}")
        );
        return true;
    }
}
