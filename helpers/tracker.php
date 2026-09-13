<?php
declare(strict_types=1);

/** Keep wizard prompts tidy while retaining created subscriptions and QR photos. */
class MessageTracker {
    private static int|string|null $chat = null;
    public static function begin(int|string $chat, ?int $incoming = null): void {
        self::$chat = $chat;
        if ($incoming) self::remember($chat, $incoming);
    }
    private static function key(int|string $chat): string { return 'tracked_messages_' . $chat; }
    public static function remember(int|string $chat, int $message): void {
        if ((string)$chat !== (string)self::$chat) return;
        $ids = Storage::cacheGet(self::key($chat)) ?? [];
        $ids[] = $message;
        Storage::cacheSet(self::key($chat), array_values(array_unique($ids)), 30 * 86400);
    }
    public static function sent(int|string $chat, string $text, ?array $markup, ?array $response): void {
        if ((string)$chat !== (string)self::$chat || empty($response['ok']) || empty($response['result']['message_id'])) return;
        $id = (int)$response['result']['message_id'];
        $validation = str_starts_with($text, '❌ Invalid') || str_starts_with($text, '❌ Duplicate');
        if ($markup !== null && !$validation) {
            foreach (Storage::cacheGet(self::key($chat)) ?? [] as $old) {
                if ((int)$old !== $id) tg_delete_message($chat, (int)$old);
            }
            Storage::cacheSet(self::key($chat), [], 30 * 86400);
        }
        if ($markup !== null || $validation) self::remember($chat, $id);
    }
}
