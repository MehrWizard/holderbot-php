<?php
/** Run once from cron, or use --daemon for the original 30-second node interval. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'UTC');
require __DIR__ . '/storage.php';
require __DIR__ . '/tgbot.php';
require __DIR__ . '/panels/panel_manager.php';
require __DIR__ . '/helpers/format.php';
require __DIR__ . '/helpers/tasks.php';
require_once __DIR__ . '/helpers/queue.php';
require __DIR__ . '/helpers/qrcode.php';
Storage::init();
$lock = Storage::db()->query("SELECT GET_LOCK('holderbot-cron', 1)");
if ((int)$lock->fetchColumn() !== 1) exit;
$daemon = in_array('--daemon', $argv, true);
$forceExpired = in_array('--expired', $argv, true);
do {
    $start = microtime(true);
    try { BackgroundTasks::tick($forceExpired); }
    catch (Throwable $e) { error_log('HolderBot scheduler failed: ' . $e->getMessage()); }
    try { BatchQueue::run(); }
    catch (Throwable $e) { error_log('HolderBot queue failed: ' . $e->getMessage()); }
    try { BatchQueue::maintain(); }
    catch (Throwable $e) { error_log('HolderBot queue retention failed: ' . $e->getMessage()); }
    $forceExpired = false;
    if ($daemon) usleep((int)(max(0.1, 30 - (microtime(true) - $start)) * 1000000));
} while ($daemon);
Storage::db()->query("SELECT RELEASE_LOCK('holderbot-cron')");
