<?php
/**
 * HolderBot PHP - Webhook Setup & Management Utility
 *
 * Can be run via CLI:
 *   php set_webhook.php set
 *   php set_webhook.php delete
 *   php set_webhook.php info
 *
 * Or accessed via web browser with query param:
 *   set_webhook.php?action=set
 *   set_webhook.php?action=info
 *   set_webhook.php?action=delete
 */

declare(strict_types=1);

if (!file_exists(__DIR__ . '/config.php')) {
    die("config.php not found. Copy config.sample.php to config.php first.\n");
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/tgbot.php';

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    $action = $argv[1] ?? 'info';
} else {
    $action = $_GET['action'] ?? 'info';
    header('Content-Type: text/plain; charset=utf-8');
}

echo "=== HolderBot PHP Webhook Manager ===\n\n";

switch ($action) {
    case 'set':
        $url = $config['webhook_url'] ?? '';
        if (empty($url) || $url === 'https://YOUR_DOMAIN.COM/holderbot-php/index.php') {
            die("ERROR: Please specify a valid HTTPS 'webhook_url' in config.php first.\n");
        }

        $params = ['url' => $url];
        if (!empty($config['webhook_secret'])) {
            $params['secret_token'] = $config['webhook_secret'];
        }

        echo "Registering Webhook to: {$url}...\n";
        $resp = tgbot('setWebhook', $params);
        echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        break;

    case 'delete':
        echo "Deleting Webhook...\n";
        $resp = tgbot('deleteWebhook', ['drop_pending_updates' => true]);
        echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        break;

    case 'info':
    default:
        echo "Fetching current Webhook status from Telegram...\n";
        $resp = tgbot('getWebhookInfo');
        echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        break;
}
