<?php
/**
 * HolderBot PHP - Webhook Entry Point
 *
 * Minimal, zero-dependency Telegram bot runner designed for cPanel and shared hosting.
 */

declare(strict_types=1);

// Fast-fail response function to acknowledge Telegram quickly
function finish_request_ok(): void {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// 1. Load Configuration
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo "Configuration file (config.php) missing. Please copy config.sample.php to config.php.";
    exit;
}

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'UTC');

// 2. Load Core Components
require_once __DIR__ . '/tgbot.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/panels/panel_manager.php';
require_once __DIR__ . '/helpers/format.php';
require_once __DIR__ . '/helpers/keyboards.php';
require_once __DIR__ . '/helpers/qrcode.php';
require_once __DIR__ . '/handlers/commands.php';
require_once __DIR__ . '/handlers/callbacks.php';
require_once __DIR__ . '/handlers/states.php';
require_once __DIR__ . '/handlers/inline.php';
require_once __DIR__ . '/version.php';

// Initialize storage (auto-migrates schema if needed)
Storage::init();

// 3. Webhook Secret Token Verification (Optional Security Header)
if (!empty($config['webhook_secret'])) {
    $secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($secretHeader !== $config['webhook_secret']) {
        http_response_code(403);
        echo "Forbidden: Invalid secret token.";
        exit;
    }
}

// 4. Read Raw Telegram Update via php://input
$rawInput = file_get_contents('php://input');

// If accessed directly via browser, display status dashboard
if (empty($rawInput)) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>HolderBot PHP</title></head><body style='font-family:sans-serif;padding:30px;background:#f7f9fa;'>";
    echo "<h2>🤖 HolderBot PHP Webhook Endpoint (" . HOLDERBOT_VERSION . ")</h2>";
    echo "<p>Status: <b style='color:green;'>Active & Running</b></p>";
    echo "<p>Storage Engine: <b>" . htmlspecialchars($config['storage_type'] ?? 'json') . "</b></p>";
    echo "<p>Configured Servers: <b>" . count(Storage::getServers()) . "</b></p>";
    echo "<p>Original Source: <a href='https://github.com/erfjab/holderbot/' target='_blank'>erfjab/holderbot</a></p>";
    echo "<hr><p style='color:#666;'>To manage webhook registration, use <code>set_webhook.php</code>.</p>";
    echo "</body></html>";
    exit;
}

$update = json_decode($rawInput, true);
if (!is_array($update)) {
    http_response_code(400);
    exit;
}

// Acknowledge receipt to Telegram
http_response_code(200);

// 5. Extract Sender Details for Authorization
$fromUser = null;
if (!empty($update['message']['from'])) {
    $fromUser = $update['message']['from'];
} elseif (!empty($update['callback_query']['from'])) {
    $fromUser = $update['callback_query']['from'];
} elseif (!empty($update['inline_query']['from'])) {
    $fromUser = $update['inline_query']['from'];
} elseif (!empty($update['chosen_inline_result']['from'])) {
    $fromUser = $update['chosen_inline_result']['from'];
}

if (!$fromUser) {
    exit;
}

$userId = (int)$fromUser['id'];
$adminIds = array_map('intval', $config['admin_ids'] ?? []);

// 6. Security Check: Admin Access Control
// An unauthorized user is never told the bot is admin-gated: no reply is sent to
// them at all. Instead every configured admin is alerted with the intruder's
// identity so they can decide whether to add them.
if (!in_array($userId, $adminIds, true)) {
    $firstName = trim(($fromUser['first_name'] ?? '') . ' ' . ($fromUser['last_name'] ?? ''));
    $username = !empty($fromUser['username']) ? '@' . $fromUser['username'] : '(no username)';
    error_log("HolderBot: unauthorized access attempt by user_id={$userId} ({$username})");

    foreach ($adminIds as $adminId) {
        $usernameDisplay = !empty($fromUser['username']) ? $fromUser['username'] : '➖';
        tg_send_message(
            $adminId,
            "<b>Oops, we have a spy!</b>\n" .
            "🥷🏻 <b>Full Name:</b> <code>" . htmlspecialchars($firstName) . "</code>\n" .
            "📌 <b>Username:</b> <code>{$usernameDisplay}</code>\n" .
            "🆔 <b>User ID:</b> <code>{$userId}</code>\n" .
            "🔗 <b>Private Chat Link:</b> <a href='tg://openmessage?user_id={$userId}'>Click here to open chat</a>"
        );
    }
    exit;
}

Storage::setChatContext($update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? $userId);

$interactiveChat = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
if ($interactiveChat !== null) MessageTracker::begin($interactiveChat, $update['message']['message_id'] ?? null);

// 7. Route Updates
try {
    // A. Inline Queries (@bot username)
    if (!empty($update['inline_query'])) {
        InlineHandlers::handle($update['inline_query']);
        exit;
    }

    // B. Inline Keyboard Callbacks
    if (!empty($update['callback_query'])) {
        CallbackHandlers::handle($update['callback_query']);
        exit;
    }

    // B. Text Messages
    if (!empty($update['message'])) {
        $message = $update['message'];
        $text = trim($message['text'] ?? '');
        $chatId = $message['chat']['id'];

        // If it is a bot command (/start, /help, /user)
        if (str_starts_with($text, '/')) {
            CommandHandlers::handle($message);
            exit;
        }

        // Check if user is inside an interactive multi-step state
        $activeState = Storage::getState($userId);
        if ($activeState !== null) {
            StateHandlers::handle($message, $activeState);
        }
        // Any other plain text outside a wizard is silently ignored, matching
        // the original bot (which only reacts to /start and /user).
    }
} catch (Throwable $e) {
    error_log("HolderBot unhandled exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
}
