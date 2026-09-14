<?php
declare(strict_types=1);

/** Overrides use the same names as Python MessageTexts / KeyboardTexts. */
class Language {
    private static ?array $defaults = null;
    public static function get(string $group, string $key, ?string $fallback = null): string {
        global $config;
        self::$defaults ??= self::defaults();
        $override = $config[$group][$key] ?? getenv($key);
        if (is_string($override) && $override !== '') return $override;
        return $fallback ?? self::$defaults[$group][$key] ?? '';
    }
    public static function replace(string $group, string $text): string {
        self::$defaults ??= self::defaults();
        $key = array_search($text, self::$defaults[$group], true);
        return $key === false ? $text : self::get($group, $key, $text);
    }

    private static function defaults(): array
    {
        return [
            'messages' => [
                'LETS_BACK' => "Let's back...", 'ITEMS_MENU' => 'Select a item or create a new:', 'ITEMS' => 'Select items', 'MENU' => 'Select a Button',
                'ASK_REMARK' => 'Enter remark: [a-z]', 'ASK_JSON' => 'Enter Json file: [*.json]', 'CREATE_WITH_JSON' => 'Create With Json',
                'INVALID_JSON' => '❌ Invalid json.', 'WORNG_DOC' => '❌ Invalid, Just send doc', 'WORNG_JSON' => '❌ Invalid, Just use [*.json]',
                'WRONG_STR' => '❌ Invalid, Just use [a-z]', 'WRONG_INT' => '❌ Invalid, Just use [0-9]', 'DUPLICATE' => '❌ Duplicate, try another.',
                'ASK_TYPES' => 'Select a type:', 'WRONG_PATTERN' => '❌ Invalid pattern.', 'INVALID_DATA' => '❌ Invalid data.', 'SUCCESS' => '✅ Success.',
                'FAILED' => '❌ Failed', 'NOT_FOUND' => '❌ Not Found.', 'NOT_FOUND_CONFIGS' => '❌ Not Found Any Config.', 'ASK_COUNT' => 'Enter count: [0-9]',
                'ASK_SUFFIX' => 'Enter Suffix:', 'ASK_DATA_LIMT' => "Enter DataLimit: [0-9]\n0 for unlimited", 'ASK_DATE_LIMIT' => 'Enter DateLimit: [0-9]',
                'ASK_CONFIGS' => 'Select Configs:', 'FAILED_USERNAME' => '❌ Failed to create {username}.', 'RANDOM_USERNAME' => 'Random Username',
                'USER_INFO' => "• <b>Username:</b> <code>{username}</code>\n• <b>Data Limit:</b> <code>{data_limit}</code>\n• <b>Date Limit:</b> <code>{expire_strategy}</code>\n• <b>Sub Url:</b> <code>{subscription_url}</code>\n",
                'ASK_SURE' => 'Are your sure?', 'ASK_ADMIN' => 'Select admin:', 'ASK_NOTE' => 'Enter note text:', 'ASK_ADMIN_FROM' => 'Select from admin:',
                'ASK_ADMIN_TO' => 'Select to admin:', 'ASK_USERNAME' => 'Enter Username:', 'START' => "Welcome to HolderBot 🤖 [<code>v0.6.0</code> by @ErfJabs]",
            ],
            'keyboards' => [
                'HOMES' => '🏛️ Home', 'SERVER' => '☁️ Server', 'CREATE' => '➕ Create', 'USERS' => '👤 Users', 'ACTIONS' => '🗄 Actions',
                'CREATE_USER' => '➕ Create User', 'SEARCH_USER' => '🔍 Search User', 'CREATE_SERVER' => '➕ Add Server', 'TEMPLATES' => '🗃 Templates',
                'DONE' => '✔️ DONE', 'LEFT' => '⬅️', 'RIGHT' => '➡️', 'UPDATE_CHECKER' => '👀 Check Update', 'STATS' => '📊 Stats',
                'SELECTS_ALL' => 'Select All', 'DESELECTS_ALL' => 'DeSelect All', 'BACK' => '◀️ Back',
            ],
        ];
    }
}
