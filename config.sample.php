<?php
/**
 * HolderBot PHP - Configuration File
 *
 * Copy this file to config.php and fill in your settings.
 */

declare(strict_types=1);

return [
    // -------------------------------------------------------------------------
    // Telegram Bot Settings
    // -------------------------------------------------------------------------
    // Get your bot token from @BotFather
    'bot_token' => 'YOUR_TELEGRAM_BOT_TOKEN_HERE',

    // List of Telegram user IDs allowed to interact with the bot
    // Anyone not in this list will be rejected
    'admin_ids' => [
        123456789, // Replace with your Telegram User ID (get it from @userinfobot)
    ],

    // Webhook URL where Telegram sends updates (Must be HTTPS)
    // Example: 'https://example.com/holderbot-php/index.php'
    'webhook_url' => 'https://YOUR_DOMAIN.COM/holderbot-php/index.php',

    // Optional secret token for verifying incoming Telegram webhook requests
    'webhook_secret' => '',

    // -------------------------------------------------------------------------
    // Timezone Settings
    // -------------------------------------------------------------------------
    'timezone' => 'UTC',

    // Optional QR background image (requires PHP GD), matching QR_BACKGROUND.
    'qr_background' => '',

    // Optional text overrides, using upstream setting names (e.g. START, HOMES).
    'messages' => [],
    'keyboards' => [],

    // -------------------------------------------------------------------------
    // Storage Engine Settings
    // -------------------------------------------------------------------------
    // MySQL/MariaDB is required. The application never writes local state files.
    'storage_type' => 'mysql',

    // MySQL connection settings
    'mysql' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'holderbot',
        'username' => 'cpanel_user',
        'password' => 'secret_password',
        'charset'  => 'utf8mb4',
    ],

    // Queue execution limits. Queue records and payloads are stored in MySQL.
    'queue_budget_seconds' => 55,
    'queue_cron_budget_seconds' => 300,
    'scan_page_size' => 1000,
    'scan_request_timeout_seconds' => 25,
    'queue_retention_days' => 7,
    'inline_lease_seconds' => 120,

    // -------------------------------------------------------------------------
    // Pre-configured Servers (Optional)
    // You can also add and manage servers dynamically via the bot.
    // -------------------------------------------------------------------------
    'servers' => [
        /*
        [
            'id'              => 1,
            'remark'          => 'Marzban Main',
            'type'            => 'marzban', // 'marzban' or 'marzneshin'
            'base_url'        => 'https://marzban.example.com:8000',
            'username'        => 'admin',
            'password'        => 'password',
            'is_active'       => true,
            'node_monitoring' => true,
        ],
        */
    ],
];
