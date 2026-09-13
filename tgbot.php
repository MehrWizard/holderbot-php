<?php
/**
 * HolderBot PHP - Telegram Bot API Wrapper
 *
 * Implements base tgbot() using native PHP cURL (zero external libraries).
 */

declare(strict_types=1);

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
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    // Check if parameters contain file uploads (CURLFile)
    $hasFile = false;
    foreach ($params as $val) {
        if ($val instanceof CURLFile) {
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

/**
 * Send a text message to a chat.
 */
function tg_send_message(
    int|string $chatId,
    string $text,
    ?array $replyMarkup = null,
    string $parseMode = 'HTML'
): ?array {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    return tgbot('sendMessage', $params);
}

/**
 * Edit an existing message text and keyboard.
 */
function tg_edit_message(
    int|string $chatId,
    int $messageId,
    string $text,
    ?array $replyMarkup = null,
    string $parseMode = 'HTML'
): ?array {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    return tgbot('editMessageText', $params);
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
 * Send a photo by URL or file path.
 */
function tg_send_photo(
    int|string $chatId,
    string $photo,
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
        $params['reply_markup'] = $replyMarkup;
    }

    if (file_exists($photo)) {
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
