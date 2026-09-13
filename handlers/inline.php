<?php
/**
 * HolderBot PHP - Telegram Inline Query Handler
 *
 * Implements 1:1 inline search behavior from app/routers/inline.py
 */

declare(strict_types=1);

class InlineHandlers {
    public static function handle(array $inlineQuery): void {
        $id = $inlineQuery['id'];
        $queryText = trim($inlineQuery['query'] ?? '');
        $parts = preg_split('/\s+/', $queryText);

        // If query is empty, list all servers
        if (empty($queryText) || empty($parts[0])) {
            $servers = Storage::getServers();
            $results = [];
            foreach ($servers as $s) {
                $results[] = [
                    'type' => 'article',
                    'id' => 'srv_' . $s['id'],
                    'title' => "{$s['id']} | {$s['remark']}",
                    'description' => "Server type: {$s['type']}",
                    'input_message_content' => [
                        'message_text' => "<b>Server ID:</b> <code>{$s['id']}</code>\n<b>Remark:</b> <code>{$s['remark']}</code>\n<b>Type:</b> <code>{$s['type']}</code>",
                        'parse_mode' => 'HTML',
                    ],
                ];
            }
            tg_answer_inline_query($id, $results, 10);
            return;
        }

        // First parameter must be server ID
        if (!is_numeric($parts[0])) {
            tg_answer_inline_query(
                $id,
                [],
                10,
                "Please enter a valid server ID (e.g. 1)",
                "invalid_server_id"
            );
            return;
        }

        $serverId = (int)$parts[0];
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_answer_inline_query(
                $id,
                [],
                10,
                "Server ID {$serverId} not found",
                "server_not_found"
            );
            return;
        }

        // Query search keyword
        $search = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;

        $users = PanelManager::getUsers($server, 1, 50, $search);
        if (empty($users)) {
            tg_answer_inline_query(
                $id,
                [],
                10,
                "No users found for '{$search}'",
                "no_users_found"
            );
            return;
        }

        $results = [];
        foreach ($users as $u) {
            $card = Formatter::userCard($server, $u);
            $statusEmoji = match ($u['status']) {
                'active' => '🟢',
                'disabled' => '🔴',
                'expired' => '⏱️',
                'limited' => '🚫',
                default => '⚪',
            };

            $used = Formatter::bytes($u['used_traffic_bytes']);
            $limit = ($u['data_limit_bytes'] > 0) ? Formatter::bytes($u['data_limit_bytes']) : 'Unlimited';
            $owner = !empty($u['owner_username']) ? " (@{$u['owner_username']})" : '';

            $results[] = [
                'type' => 'article',
                'id' => 'usr_' . $serverId . '_' . $u['username'],
                'title' => "{$statusEmoji} {$u['username']}{$owner}",
                'description' => "Usage: {$used} / {$limit} | Status: {$u['status']}",
                'thumb_url' => 'https://raw.githubusercontent.com/erfjab/holderbot/main/holderbot.png',
                'input_message_content' => [
                    'message_text' => $card,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ],
            ];
        }

        tg_answer_inline_query($id, $results, 10);
    }
}
