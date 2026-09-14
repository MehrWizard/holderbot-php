<?php
/**
 * HolderBot PHP - Complete FSM / Multi-step Wizard Handlers
 */

declare(strict_types=1);
require_once __DIR__ . '/../helpers/input.php';

class StateHandlers {
    /**
     * Process message from user when in an active conversation state.
     */
    public static function handle(array $message, array $state): bool {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text'] ?? '';
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

            case 'add_server_credentials':
                return self::handleAddServerCredentials($chatId, $userId, $text, $data);

            default:
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

        $results = PanelManager::getUsers($server, 1, 10, $query);
        $kb = Keyboards::usersList($serverId, $results, 1, false, 'all', true);
        tg_send_message($chatId, "Select items", $kb);
        return true;
    }

    private static function handleCreateUserName(int|string $chatId, int $userId, string $username, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $server = Storage::getServer($serverId);
        if (!$server) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Not Found.");
            return true;
        }

        // Matches the original bot exactly: only a length check, no charset
        // filtering - the raw text is used as-is (the panel API is the actual
        // source of truth for whether a username is valid).
        if (preg_match_all('/./us', $username) <= 3) {
            tg_send_message(
                $chatId,
                "❌ Invalid pattern.",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        $data['username'] = $username;
        Storage::setState($userId, 'create_user_count', $data);

        tg_send_message(
            $chatId,
            "Enter count: [0-9]",
            Keyboards::cancel("srv:{$serverId}")
        );
        return true;
    }

    private static function handleCreateUserCount(int|string $chatId, int $userId, string $input, array $data): bool {
        $input = Input::decimalDigits($input);
        $serverId = (int)($data['server_id'] ?? 0);
        if (!ctype_digit($input) || (int)$input < 1 || (float)$input > 10000) {
            tg_send_message($chatId, "Enter a count between 1 and 10000.", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        $count = (int)$input;
        $data['count'] = $count;

        if ($count > 1) {
            Storage::setState($userId, 'create_user_suffix', $data);
            tg_send_message(
                $chatId,
                "Enter Suffix:",
                Keyboards::cancel("srv:{$serverId}")
            );
            return true;
        }

        // Single user
        $templates = Storage::getActiveTemplates();
        if (!empty($templates)) {
            Storage::setState($userId, 'create_user_template', $data);
            $text = "Select items";
            $kb = Keyboards::templateSelector($serverId, $templates);
            tg_send_message($chatId, $text, $kb);
            return true;
        }

        Storage::setState($userId, 'create_user_data', $data);
        tg_send_message(
            $chatId,
            "Enter DataLimit: [0-9]\n0 for unlimited",
            Keyboards::cancel("srv:{$serverId}")
        );
        return true;
    }

    private static function handleCreateUserSuffix(int|string $chatId, int $userId, string $input, array $data): bool {
        $input = Input::decimalDigits($input);
        $serverId = (int)($data['server_id'] ?? 0);
        if (!ctype_digit($input) || (int)$input < 1) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        $data['usersuffix'] = (int)$input;
        $templates = Storage::getActiveTemplates();
        if (!empty($templates)) {
            Storage::setState($userId, 'create_user_template', $data);
            $text = "Select items";
            $kb = Keyboards::templateSelector($serverId, $templates);
            tg_send_message($chatId, $text, $kb);
            return true;
        }

        Storage::setState($userId, 'create_user_data', $data);
        tg_send_message(
            $chatId,
            "Enter DataLimit: [0-9]\n0 for unlimited",
            Keyboards::cancel("srv:{$serverId}")
        );
        return true;
    }

    private static function handleCreateUserJson(int|string $chatId, int $userId, array $message, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $doc = $message['document'] ?? null;
        if (!$doc) {
            tg_send_message($chatId, "❌ Invalid, Just send doc", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        $fileName = $doc['file_name'] ?? '';
        if (!str_ends_with($fileName, '.json')) {
            tg_send_message($chatId, "❌ Invalid, Just use [*.json]", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }

        if (($doc['file_size'] ?? 0) > 8388608) {
            tg_send_message($chatId, 'Import exceeds 8 MiB. Split the JSON file into smaller batches.', Keyboards::cancel("srv:{$serverId}"));
            return true;
        }
        $data['import_file_id'] = $doc['file_id'];

        require_once __DIR__ . '/callbacks.php';
        CallbackHandlers::renderConfigSelection($chatId, 0, $serverId, $userId, $data, 'json_ready');
        return true;
    }

    private static function handleCreateUserData(int|string $chatId, int $userId, string $input, array $data): bool {
        $input = Input::decimalDigits($input);
        $serverId = (int)($data['server_id'] ?? 0);
        if (!ctype_digit($input)) {
            tg_send_message(
                $chatId,
                "❌ Invalid, Just use [0-9]",
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
            "Select a Button",
            $kb
        );
        return true;
    }

    private static function handleCreateUserExpire(int|string $chatId, int $userId, string $input, array $data): bool {
        $input = Input::decimalDigits($input);
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
        $input = Input::decimalDigits($input);
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';
        $server = Storage::getServer($serverId);

        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }

        if (!$server) {
            tg_send_message($chatId, "❌ Not Found.", Keyboards::cancel());
            return true;
        }
        $ok = PanelManager::modifyUserDataLimit($server, $username, (int)$input);
        Storage::clearState($userId);
        tg_send_message($chatId, $ok ? "✅ Success." : "❌ Failed", Keyboards::cancel("usr:{$serverId}:{$username}"));
        return true;
    }

    private static function handleUserModDateLimit(int|string $chatId, int $userId, string $input, array $data): bool {
        $input = Input::decimalDigits($input);
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';
        $server = Storage::getServer($serverId);

        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }

        if (!$server) {
            tg_send_message($chatId, "❌ Not Found.", Keyboards::cancel());
            return true;
        }
        $ok = PanelManager::modifyUserDateLimit($server, $username, (int)$input);
        Storage::clearState($userId);
        tg_send_message($chatId, $ok ? "✅ Success." : "❌ Failed", Keyboards::cancel("usr:{$serverId}:{$username}"));
        return true;
    }

    private static function handleUserModNote(int|string $chatId, int $userId, string $note, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';
        $server = Storage::getServer($serverId);

        if ($note === '' || preg_match_all('/./us', $note) > 500) {
            tg_send_message($chatId, "❌ Invalid, Just use [a-z]", Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }

        if (!$server) {
            tg_send_message($chatId, "❌ Not Found.", Keyboards::cancel());
            return true;
        }
        $ok = PanelManager::modifyUserNote($server, $username, $note);
        Storage::clearState($userId);
        tg_send_message($chatId, $ok ? "✅ Success." : "❌ Failed", Keyboards::cancel("usr:{$serverId}:{$username}"));
        return true;
    }

    // =========================================================================
    // Template Creation Handlers
    // =========================================================================

    private static function handleTmplAddRemark(int|string $chatId, int $userId, string $remark): bool {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $remark)) {
            tg_send_message($chatId, "❌ Invalid, Just use [a-z]", Keyboards::cancel());
            return true;
        }

        $remark = strtolower($remark);
        foreach (Storage::getTemplates() as $t) {
            if (strtolower($t['remark']) === $remark) {
                tg_send_message($chatId, "❌ Duplicate, try another.", Keyboards::cancel());
                return true;
            }
        }

        Storage::setState($userId, 'tmpl_add_data', ['remark' => $remark]);
        tg_send_message($chatId, "Enter DataLimit: [0-9]\n0 for unlimited", Keyboards::cancel());
        return true;
    }

    private static function handleTmplAddData(int|string $chatId, int $userId, string $input, array $data): bool {
        $input = Input::decimalDigits($input);
        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel());
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
        $input = Input::decimalDigits($input);
        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel());
            return true;
        }

        $data['date_limit'] = (int)$input;

        Storage::saveTemplate($data);
        Storage::clearState($userId);
        tg_send_message(
            $chatId,
            "✅ Success.",
            Keyboards::cancel()
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
        tg_send_message($chatId, "Select a type:", Keyboards::serverTypes());
        return true;
    }

    private static function handleAddServerCredentials(int|string $chatId, int $userId, string $input, array $data): bool {
        $parts = preg_split('/\s+/', trim($input));
        if (count($parts) !== 3) {
            tg_send_message($chatId, "❌ Invalid pattern.");
            return true;
        }
        $data['username'] = $parts[0];
        $data['password'] = $parts[1];
        $data['base_url'] = rtrim($parts[2], '/');
        $token = $data['type'] === 'marzneshin' ? MarzneshinClient::getToken($data) : MarzbanClient::getToken($data);
        if (!$token) {
            tg_send_message($chatId, "❌ Invalid data.");
            return true;
        }
        $serverId = Storage::saveServer($data);
        Storage::cacheSet("online_{$serverId}", time(), 86400);
        Storage::clearState($userId);
        tg_send_message($chatId, "✅ Success.", Keyboards::cancel());
        return true;
    }

    private static function handleTmplEditRemark(int|string $chatId, int $userId, string $remark, array $data): bool {
        $tmplId = (int)($data['tmpl_id'] ?? 0);
        $tmpl = Storage::getTemplate($tmplId);
        if (!$tmpl) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Not Found.");
            return true;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $remark)) {
            tg_send_message($chatId, "❌ Invalid, Just use [a-z]", Keyboards::cancel());
            return true;
        }
        $remark = strtolower($remark);
        foreach (Storage::getTemplates() as $t) {
            if ((int)$t['id'] !== $tmplId && strtolower($t['remark']) === $remark) {
                tg_send_message($chatId, "❌ Duplicate, try another.", Keyboards::cancel());
                return true;
            }
        }
        $tmpl['remark'] = $remark;
        Storage::saveTemplate($tmpl);
        Storage::clearState($userId);
        tg_send_message($chatId, "✅ Success.",
            Keyboards::cancel());
        return true;
    }

    private static function handleTmplEditData(int|string $chatId, int $userId, string $input, array $data): bool {
        $input = Input::decimalDigits($input);
        $tmplId = (int)($data['tmpl_id'] ?? 0);
        $tmpl = Storage::getTemplate($tmplId);
        if (!$tmpl) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Not Found.");
            return true;
        }
        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel());
            return true;
        }
        $tmpl['data_limit'] = (int)$input;
        Storage::saveTemplate($tmpl);
        Storage::clearState($userId);
        $dlText = ($tmpl['data_limit'] > 0) ? "{$tmpl['data_limit']}GB" : 'Unlimited';
        tg_send_message($chatId, "✅ Success.",
            Keyboards::cancel());
        return true;
    }

    private static function handleTmplEditDate(int|string $chatId, int $userId, string $input, array $data): bool {
        $input = Input::decimalDigits($input);
        $tmplId = (int)($data['tmpl_id'] ?? 0);
        $tmpl = Storage::getTemplate($tmplId);
        if (!$tmpl) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Not Found.");
            return true;
        }
        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]", Keyboards::cancel());
            return true;
        }
        $tmpl['date_limit'] = (int)$input;
        $tmpl['date_type'] = $data['date_type'] ?? 'fixed';
        Storage::saveTemplate($tmpl);
        Storage::clearState($userId);
        tg_send_message($chatId, "✅ Success.",
            Keyboards::cancel());
        return true;
    }

    private static function handleSrvEditRemark(int|string $chatId, int $userId, string $remark, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $server = Storage::getServer($serverId);
        if (!$server) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Not Found.");
            return true;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $remark)) {
            tg_send_message($chatId, "❌ Invalid, Just use [a-z]", Keyboards::cancel("srv:{$serverId}"));
            return true;
        }
        $remark = strtolower($remark);
        foreach (Storage::getServers() as $s) {
            if ((int)$s['id'] !== $serverId && strtolower($s['remark']) === $remark) {
                tg_send_message($chatId, "❌ Duplicate, try another.", Keyboards::cancel("srv:{$serverId}"));
                return true;
            }
        }
        $server['remark'] = $remark;
        Storage::saveServer($server);
        Storage::clearState($userId);
        tg_send_message($chatId, "✅ Success.",
            Keyboards::cancel("srv:{$serverId}"));
        return true;
    }

    private static function handleSrvEditCreds(int|string $chatId, int $userId, string $input, array $data): bool {
        $serverId = (int)($data['server_id'] ?? 0);
        $server = Storage::getServer($serverId);
        if (!$server) {
            Storage::clearState($userId);
            tg_send_message($chatId, "❌ Not Found.");
            return true;
        }
        $parts = preg_split('/\s+/', trim($input));
        if (count($parts) !== 3) {
            tg_send_message($chatId,
                "❌ Invalid pattern.",
                Keyboards::cancel("srv:{$serverId}")
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
            tg_send_message($chatId, "❌ Invalid data.", Keyboards::cancel("srv:{$serverId}"));
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
            Keyboards::cancel("srv:{$serverId}"));
        return true;
    }

    private static function handleUserModDateLimitOnhold(int|string $chatId, int $userId, string $input, array $data): bool {
        $input = Input::decimalDigits($input);
        $serverId = (int)($data['server_id'] ?? 0);
        $username = $data['username'] ?? '';
        $server = Storage::getServer($serverId);
        if (!ctype_digit($input)) {
            tg_send_message($chatId, "❌ Invalid, Just use [0-9]",
                Keyboards::cancel("usr:{$serverId}:{$username}"));
            return true;
        }
        if (!$server) { tg_send_message($chatId, "❌ Not Found.", Keyboards::cancel()); return true; }
        Storage::clearState($userId);
        $ok = PanelManager::updateDateLimit($server, $username, (int)$input, 'onhold');
        tg_send_message($chatId,
            $ok ? "✅ Success." : "❌ Failed",
            Keyboards::cancel("usr:{$serverId}:{$username}")
        );
        return true;
    }
}
