<?php
/**
 * HolderBot PHP - Command Handlers
 */

declare(strict_types=1);

class CommandHandlers {
    /**
     * Handle incoming text commands (/start, /help, /user).
     */
    public static function handle(array $message): bool {
        $text = trim($message['text'] ?? '');
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];

        if (!str_starts_with($text, '/')) {
            return false;
        }

        // Clear any active wizard state on new command
        Storage::clearState($userId);

        $parts = preg_split('/\s+/', $text);
        $command = strtolower($parts[0]);

        switch ($command) {
            case '/start':
                // Check if start command has deep link parameter: /start user_<server_id>_<username>
                if (!empty($parts[1]) && str_starts_with($parts[1], 'user_')) {
                    $subParts = explode('_', $parts[1], 3);
                    if (count($subParts) >= 3) {
                        return self::cmdUser($chatId, ['/user', $subParts[1], $subParts[2]]);
                    }
                }
                return self::cmdStart($chatId);

            case '/home':
            case '/servers':
                return self::cmdStart($chatId);

            case '/help':
                return self::cmdHelp($chatId);

            case '/user':
                return self::cmdUser($chatId, $parts);

            default:
                tg_send_message(
                    $chatId,
                    "❓ Unknown command: <code>" . htmlspecialchars($command) . "</code>\nUse /start to open the main menu."
                );
                return true;
        }
    }

    private static function cmdStart(int|string $chatId): bool {
        $servers = Storage::getServers();

        $text = "🤖 <b>Welcome to HolderBot PHP</b>\n\n";
        $text .= "Easily manage your Marzban & Marzneshin VPN panels directly from Telegram.\n\n";

        if (empty($servers)) {
            $text .= "⚠️ <i>No servers configured yet. Click '➕ Add Server' below or configure them in config.php.</i>";
        } else {
            $text .= "Select a server below to manage users, inspect nodes, or create subscriptions:";
        }

        $kb = Keyboards::home($servers);
        tg_send_message($chatId, $text, $kb);
        return true;
    }

    private static function cmdHelp(int|string $chatId): bool {
        $text = "📖 <b>HolderBot PHP Commands:</b>\n\n";
        $text .= "• /start - Open main server list\n";
        $text .= "• /user &lt;server_id&gt; &lt;username&gt; - Quick user lookup\n";
        $text .= "• /help - Show this manual\n\n";
        $text .= "💡 <i>You can manage users, recharge, revoke subscriptions, and check node health from the inline buttons.</i>";

        tg_send_message($chatId, $text);
        return true;
    }

    private static function cmdUser(int|string $chatId, array $parts): bool {
        if (count($parts) < 3) {
            tg_send_message(
                $chatId,
                "⚠️ <b>Usage:</b> <code>/user &lt;server_id&gt; &lt;username&gt;</code>\nExample: <code>/user 1 john_doe</code>"
            );
            return true;
        }

        $serverId = (int)$parts[1];
        $username = trim($parts[2]);

        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_send_message($chatId, "❌ Server with ID <code>{$serverId}</code> not found.");
            return true;
        }

        $user = PanelManager::getUser($server, $username);
        if (!$user) {
            tg_send_message($chatId, "❌ User <code>" . htmlspecialchars($username) . "</code> not found on <b>{$server['remark']}</b>.");
            return true;
        }

        $card = Formatter::userCard($server, $user);
        $kb = Keyboards::userActions($server['id'], $user['username'], $user['is_active'], $user['status']);
        tg_send_message($chatId, $card, $kb);
        return true;
    }
}
