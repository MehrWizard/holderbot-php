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
                    'id' => (string)$s['id'],
                    'title' => "{$s['id']} | {$s['remark']}",
                    'description' => "server type: {$s['type']}",
                    'thumbnail_url' => 'https://github.com/user-attachments/assets/02947eb3-421c-424c-8f64-83686168c8f5',
                    'input_message_content' => [
                        'message_text' => "Server ID: {$s['id']}\nRemark: {$s['remark']}",
                    ],
                ];
            }
            tg_answer_inline_query($id, $results, 10);
            return;
        }

        // First parameter must be server ID
        if (!ctype_digit($parts[0])) {
            tg_answer_inline_query(
                $id,
                [],
                10,
                "Please enter a valid server ID (integer)",
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
                "Server not found. Enter a valid server ID.",
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
                "No users found for the given query.",
                "no_users_found"
            );
            return;
        }

        $results = [];
        foreach ($users as $u) {
            $statusEmoji = $u['is_active'] ? '✅ ' : '❌ ';
            $owner = $u['owner_username'] ?: 'None';
            $brief = Formatter::briefData($server, $u);
            $dataLimit = $brief['data_limit'];
            $expire = $brief['expire_strategy'];

            $results[] = [
                'type' => 'article',
                'id' => $u['username'],
                'title' => "{$statusEmoji} {$u['username']} ({$owner})",
                'description' => "data: {$dataLimit} | date: {$expire}",
                'thumbnail_url' => 'https://github.com/user-attachments/assets/02947eb3-421c-424c-8f64-83686168c8f5',
                'input_message_content' => [
                    'message_text' => Formatter::userInfo($server, $u),
                    'parse_mode' => 'HTML',
                ],
            ];
        }

        tg_answer_inline_query($id, $results, 10);
    }
}
