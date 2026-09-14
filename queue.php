<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'UTC');
require __DIR__ . '/storage.php';
require __DIR__ . '/tgbot.php';
require __DIR__ . '/panels/panel_manager.php';
require __DIR__ . '/helpers/format.php';
require __DIR__ . '/helpers/keyboards.php';
require __DIR__ . '/helpers/qrcode.php';
require __DIR__ . '/helpers/queue.php';
require __DIR__ . '/helpers/tasks.php';
Storage::init();
$command = $argv[1] ?? 'health';
try {
    switch ($command) {
        case 'health':
            $health=BatchQueue::health();
            echo "last_run={$health['last_run']} pending={$health['pending']} pending_notifications={$health['pending_notifications']} stale=" . ($health['stale'] ? 'yes' : 'no') . "\n";
            exit($health['stale'] ? 1 : 0);
        case 'work': echo BatchQueue::run()." steps processed\n"; break;
        case 'attention':
            $rows = Storage::db()->query("SELECT id,kind,status,error_text FROM bot_queue WHERE status IN ('failed') OR unconfirmed_count>0 ORDER BY updated_at DESC LIMIT 100")->fetchAll();
            foreach ($rows as $row) echo $row['id'].' '.$row['kind'].' '.$row['status'].' '.($row['error_text'] ?? '')."\n";
            break;
        case 'inspect':
            $job=BatchQueue::get($argv[2] ?? '');
            if (!$job) throw new RuntimeException('Job not found');
            // Do not print imported data or subscription credentials by default.
            echo BatchQueue::describe($job)."\n";
            break;
        case 'issues':
            $job=BatchQueue::get($argv[2] ?? '');
            if (!$job) throw new RuntimeException('Job not found');
            echo BatchQueue::describe($job)."\n";
            $issues=BatchQueue::issues($job['id'], (int)($argv[3] ?? -1));
            if ($issues['intent']) {
                echo 'Last mutation target: '.($issues['intent']['username'] ?? '')."\n";
                echo 'Last write phase: '.($issues['intent']['phase'] ?? '')."\n";
                foreach ($issues['intent']['payload'] ?? [] as $field=>$value) {
                    if (is_scalar($value) || $value === null) echo $field.'='.var_export($value,true)."\n";
                }
            }
            foreach ($issues['items'] as $item) echo $item['position'].' '.$item['username'].' '.$item['status']."\n";
            if (count($issues['items'])===50) echo 'Next page: php queue.php issues '.$job['id'].' '.end($issues['items'])['position']."\n";
            break;
        case 'cancel': BatchQueue::cancel($argv[2] ?? ''); echo "Cancellation requested\n"; break;
        case 'retry-read': throw new RuntimeException('Automatic retry is disabled; submit a new read job after reviewing the failure');
        case 'resend': throw new RuntimeException('Automatic resend is disabled; use the bot action to create a new delivery');
        default: throw new RuntimeException('Usage: php queue.php health|work|attention|inspect ID|issues ID [AFTER_POSITION]|cancel ID|retry-read ID|resend ID');
    }
} catch (Throwable $e) { error_log($e->getMessage()); exit(1); }
