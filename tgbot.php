<?php
/**
 * HolderBot PHP - Telegram Bot API Wrapper
 *
 * Implements base tgbot() using native PHP cURL (zero external libraries).
 */

declare(strict_types=1);
require_once __DIR__ . '/helpers/request_budget.php';
require_once __DIR__ . '/helpers/tracker.php';
require_once __DIR__ . '/helpers/language.php';

/**
 * Executes a Telegram Bot API method via cURL.
 *
 * @param string $method Telegram API method (e.g., 'sendMessage', 'editMessageText')
 * @param array $params Parameters to send in the request
 * @return array|null Returns parsed response array or null on connection failure
 */
function tgbot(string $method, array $params = []): ?array {
    global $config;
    $token = $config['bot_token'] ?? '';
    if (empty($token) || $token === 'YOUR_TELEGRAM_BOT_TOKEN_HERE') {
        error_log("tgbot error: BOT_TOKEN is not configured.");
        return null;
    }

    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $ch = curl_init();
    $curlOptions = [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => RequestBudget::milliseconds(20),
        CURLOPT_CONNECTTIMEOUT_MS => RequestBudget::milliseconds(10),
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    // Check if parameters contain file uploads (CURLFile)
    $hasFile = false;
    foreach ($params as $val) {
        if ($val instanceof CURLFile || (class_exists('CURLStringFile') && $val instanceof CURLStringFile)) {
            $hasFile = true;
            break;
        }
    }

    if ($hasFile) {
        $curlOptions[CURLOPT_POSTFIELDS] = $params;
    } else {
        $curlOptions[CURLOPT_POSTFIELDS] = json_encode($params);
        $curlOptions[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }

    curl_setopt_array($ch, $curlOptions);

    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        error_log("tgbot cURL failed for '{$method}': " . $curlError);
        return null;
    }

    $result = json_decode($rawResponse, true);
    if (!is_array($result) || empty($result['ok'])) {
        $desc = $result['description'] ?? "HTTP {$httpCode}: {$rawResponse}";
        error_log("tgbot API error in '{$method}': {$desc}");
    }

    return $result;
}

function tg_pack_keyboard(?array $markup): ?array {
    if ($markup === null) return null;
    $markup = Keyboards::navigationLast($markup);
    foreach ($markup['inline_keyboard'] as &$row) {
        foreach ($row as &$button) {
            $button['text'] = Language::replace('keyboards', $button['text']);
            $data = $button['callback_data'] ?? '';
            if (strlen($data) > 64) {
                $button['callback_data'] = 'ref:' . substr(hash('sha256', $data), 0, 48);
                Storage::cacheSet($button['callback_data'], $data, 30 * 86400);
            }
        }
    }
    return $markup;
}

/**
 * Send a text message to a chat.
 */
function tg_send_message(
    int|string $chatId,
    string $text,
    ?array $replyMarkup = null,
    string $parseMode = 'HTML',
    bool $cleanupTracked = true
): ?array {
    $params = [
        'chat_id' => $chatId,
        'text' => Language::replace('messages', $text),
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = tg_pack_keyboard($replyMarkup);
    }
    $response = tgbot('sendMessage', $params);
    if ($cleanupTracked) MessageTracker::sent($chatId, $text, $replyMarkup, $response);
    elseif (!empty($response['result']['message_id'])) MessageTracker::remember($chatId,(int)$response['result']['message_id']);
    return $response;
}

/**
 * Edit an existing message text and keyboard.
 */
function tg_callback_message_is_old(int|string $chatId, int $messageId, ?array $source = null): bool {
    $source ??= $GLOBALS['tg_callback_message'] ?? null;
    return is_array($source) && (string)($source['chat_id'] ?? '') === (string)$chatId
        && (int)($source['message_id'] ?? 0) === $messageId
        && (int)($source['date'] ?? 0) > 0
        && time() - (int)$source['date'] > 600;
}

function tg_edit_message(
    int|string $chatId,
    int $messageId,
    string $text,
    ?array $replyMarkup = null,
    string $parseMode = 'HTML'
): ?array {
    if ($messageId <= 0) return tg_send_message($chatId, $text, $replyMarkup, $parseMode);
    if (tg_callback_message_is_old($chatId,$messageId)) {
        $replacement = Storage::cacheGet('replacement_' . $chatId . '_' . $messageId);
        if (!$replacement) return tg_replace_message($chatId, $messageId, $text, $replyMarkup, $parseMode);
        $messageId = (int)$replacement;
    }
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => Language::replace('messages', $text),
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = tg_pack_keyboard($replyMarkup);
    }
    $response = tgbot('editMessageText', $params);
    if (!empty($response['ok'])) MessageTracker::remember($chatId, $messageId);
    if (empty($response['ok'])) {
        // A missing or uneditable message still needs a usable reply. Keep
        // recent messages intact; hard deletion is reserved for old callbacks.
        $sent = tg_send_message($chatId, $text, $replyMarkup, $parseMode, false);
        if (!empty($sent['result']['message_id'])) Storage::cacheSet('replacement_' . $chatId . '_' . $messageId, (int)$sent['result']['message_id'], 604800);
        return $sent;
    }
    return $response;
}

/** Delete a previous status/loading message and deliver the final result fresh. */
function tg_replace_message(
    int|string $chatId,
    int $messageId,
    string $text,
    ?array $replyMarkup = null,
    string $parseMode = 'HTML'
): ?array {
    $originalId=$messageId;
    for ($i = 0; $i < 100 && $messageId > 0; $i++) {
        $replacement = Storage::cacheGet('replacement_' . $chatId . '_' . $messageId);
        if (!$replacement || (int)$replacement === $messageId) break;
        $messageId = (int)$replacement;
    }
    if ($messageId > 0) tg_delete_message($chatId, $messageId);
    $sent=tg_send_message($chatId, $text, $replyMarkup, $parseMode);
    if ($originalId > 0 && !empty($sent['result']['message_id'])) Storage::cacheSet('replacement_' . $chatId . '_' . $originalId, (int)$sent['result']['message_id'], 604800);
    return $sent;
}

/**
 * Answer an incoming callback query.
 */
function tg_answer_callback(
    string $callbackQueryId,
    ?string $text = null,
    bool $showAlert = false
): ?array {
    $params = [
        'callback_query_id' => $callbackQueryId,
        'show_alert' => $showAlert,
    ];
    if ($text !== null) {
        $params['text'] = $text;
    }
    return tgbot('answerCallbackQuery', $params);
}

/**
 * Delete a message from a chat.
 */
function tg_delete_message(int|string $chatId, int $messageId): ?array {
    return tgbot('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
    ]);
}

/**
 * Send a photo by URL, file path, or an in-memory CURLStringFile.
 */
function tg_send_photo(
    int|string $chatId,
    mixed $photo,
    string $caption = '',
    ?array $replyMarkup = null,
    string $parseMode = 'HTML'
): ?array {
    $params = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'parse_mode' => $parseMode,
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = tg_pack_keyboard($replyMarkup);
    }

    if (is_string($photo) && file_exists($photo)) {
        $params['photo'] = new CURLFile($photo);
    } else {
        $params['photo'] = $photo;
    }

    return tgbot('sendPhoto', $params);
}

/**
 * Answer an inline query.
 */
function tg_answer_inline_query(
    string $inlineQueryId,
    array $results,
    int $cacheTime = 10,
    ?string $switchPmText = null,
    ?string $switchPmParameter = null
): ?array {
    $params = [
        'inline_query_id' => $inlineQueryId,
        'results' => $results,
        'cache_time' => $cacheTime,
    ];
    if ($switchPmText !== null) {
        $params['switch_pm_text'] = $switchPmText;
    }
    if ($switchPmParameter !== null) {
        $params['switch_pm_parameter'] = $switchPmParameter;
    }
    return tgbot('answerInlineQuery', $params);
}

/**
 * Download a file from Telegram by file_id.
 */
function tg_download_file(string $fileId, int $maxBytes = 1048576): ?string {
    global $config;
    $previousDeadline = RequestBudget::$deadline;
    RequestBudget::$deadline = min($previousDeadline ?? INF, microtime(true) + 10);
    try {
        $res = tgbot('getFile', ['file_id' => $fileId]);
        if (empty($res['result']['file_path']) || ($res['result']['file_size'] ?? 0) > $maxBytes) return null;
        $token = $config['bot_token'] ?? '';
        $fileUrl = "https://api.telegram.org/file/bot{$token}/" . $res['result']['file_path'];
        $content = '';
        $ch = curl_init($fileUrl);
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT_MS => RequestBudget::milliseconds(10),
            CURLOPT_CONNECTTIMEOUT_MS => RequestBudget::milliseconds(5),
            CURLOPT_WRITEFUNCTION => static function($curl, string $chunk) use (&$content, $maxBytes): int {
                if (strlen($content) + strlen($chunk) > $maxBytes) return 0;
                $content .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $ok !== false && $status >= 200 && $status < 300 ? $content : null;
        } finally { curl_close($ch); }
    } catch (Throwable $e) { return null; }
    finally { RequestBudget::$deadline = $previousDeadline; }
}
