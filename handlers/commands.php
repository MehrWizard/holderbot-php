<?php
/**
 * HolderBot PHP - Command Handlers
 */

declare(strict_types=1);
require_once __DIR__ . '/../helpers/queue.php';

class CommandHandlers {
    /**
     * Handle original commands and the PHP queue status command.
     */
    public static function handle(array $message): bool {
        $text = trim($message['text'] ?? '');
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];

        if (!str_starts_with($text, '/')) {
            return false;
        }

        $parts = preg_split('/\s+/', $text, 3);
        $command = explode('@', strtolower($parts[0]), 2)[0];

        switch ($command) {
            case '/start':
                // Check if start command has deep link parameter: /start user_<server_id>_<username>
                if (!empty($parts[1]) && str_starts_with($parts[1], 'user_')) {
                    $messageId = $message['message_id'] ?? null;
                    if ($messageId) {
                        tg_delete_message($chatId, $messageId);
                    }
                    $subParts = explode('_', $parts[1], 3);
                    if (count($subParts) >= 3) {
                        if (!ctype_digit($subParts[1]) || $subParts[2] === '') {
                            tg_send_message($chatId, "❌ Invalid pattern.");
                            return true;
                        }
                        return self::handleDeepLinkUser($chatId, (int)$subParts[1], $subParts[2]);
                    }
                }
                Storage::clearState($userId);
                if (!empty($message['message_id'])) tg_delete_message($chatId, $message['message_id']);
                return self::cmdStart($chatId);

            case '/jobs':
                $jobs = BatchQueue::recent($chatId, (int)$userId);
                $rows = [];
                foreach ($jobs as $job) {
                    $status = match ($job['status']) {
                        'completed' => 'completed',
                        'failed' => 'failed',
                        'cancelled' => 'cancelled',
                        default => 'in progress',
                    };
                    $rows[] = [['text'=>ucfirst(BatchQueue::label((string)$job['kind'])) . ' — ' . $status, 'callback_data'=>'job:' . $job['id']]];
                }
                tg_send_message($chatId, $jobs ? 'Recent batches (up to 10):' : 'No batches found.', ['inline_keyboard'=>$rows]);
                return true;

            case '/user':
                return self::cmdUser($chatId, $parts);

            default:
                return true;
        }
    }

    /**
     * Deletes the previous bot-sent menu in this chat (if any) before sending a
     * new one, so repeated /start or /user usage doesn't leave a trail of stale
     * menus behind - matching the original bot's chat-cleanup behavior for its
     * main entry points.
     */
    private static function sendFreshMenu(int|string $chatId, string $text, ?array $kb = null): void {
        $prevId = Storage::cacheGet("last_menu_msg_{$chatId}");
        if ($prevId) {
            tg_delete_message($chatId, (int)$prevId);
        }
        $sent = tg_send_message($chatId, $text, $kb);
        $newId = $sent['result']['message_id'] ?? null;
        if ($newId) {
            Storage::cacheSet("last_menu_msg_{$chatId}", $newId, 86400);
        }
    }

    private static function cmdStart(int|string $chatId): bool {
        $servers = Storage::getServers();

        $text = Formatter::start();

        $kb = Keyboards::home($servers);
        self::sendFreshMenu($chatId, $text, $kb);
        return true;
    }

    /**
     * /user <server_id> <username> is always a search that renders a results
     * list, even on an exact match - it never resolves directly to a single
     * user's action card and never reports "not found" (an empty list is shown
     * instead), matching the original bot's behavior.
     */
    private static function cmdUser(int|string $chatId, array $parts): bool {
        if (count($parts) < 3 || !ctype_digit($parts[1]) || trim($parts[2]) === '') {
            tg_send_message($chatId, "❌ Invalid pattern.\n/user serverid username");
            return true;
        }

        $serverId = (int)$parts[1];
        $username = trim($parts[2]);

        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_send_message($chatId, "❌ Not Found.", Keyboards::cancel('home'));
            return true;
        }

        $results = PanelManager::getUsers($server, 1, 10, $username);
        $kb = Keyboards::usersList($server['id'], $results, 1, false, 'all', true);
        self::sendFreshMenu($chatId, "Select items", $kb);
        return true;
    }

    /**
     * /start user_<server_id>_<username> deep link: unlike /user, this does an
     * EXACT lookup and, if found, jumps straight to that user's full action
     * card - matching the original bot's deep-link handler exactly (a
     * different code path from the /user search command).
     */
    private static function handleDeepLinkUser(int|string $chatId, int $serverId, string $username): bool {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_send_message($chatId, "❌ Not Found.");
            return true;
        }

        $user = PanelManager::getUser($server, $username);
        if (!$user) {
            tg_send_message($chatId, "❌ Not Found.");
            return true;
        }

        $card = Formatter::userCard($server, $user);
        $kb = Keyboards::userActions($serverId, $user['username'], $user['is_active'], $user['status']);
        self::sendFreshMenu($chatId, $card, $kb);
        return true;
    }
}
