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
Storage::init();
$command = $argv[1] ?? 'health';
try {
    switch ($command) {
        case 'health':
            $health=BatchQueue::health();
            echo json_encode($health,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
            exit($health['stale'] ? 1 : 0);
        case 'work': echo BatchQueue::run()." steps processed\n"; break;
        case 'attention':
            foreach (array_slice(glob(BatchQueue::directory().'/attention/*') ?: [],0,100) as $file) {
                $job=BatchQueue::get(basename($file));
                if ($job) echo $job['id'].' '.$job['kind'].' '.$job['status']."\n";
            }
            break;
        case 'inspect':
            $job=BatchQueue::get($argv[2] ?? '');
            if (!$job) throw new RuntimeException('Job not found');
            // Do not print imported data or subscription credentials by default.
            echo BatchQueue::describe($job)."\n";
            break;
        case 'issues':
            $id=$argv[2] ?? '';
            if (!BatchQueue::get($id)) throw new RuntimeException('Job not found');
            foreach (glob(BatchQueue::directory().'/'.$id.'/issue-*.json') ?: [] as $file) echo file_get_contents($file)."\n";
            break;
        case 'cancel': BatchQueue::cancel($argv[2] ?? ''); echo "Cancellation requested\n"; break;
        case 'retry-read': BatchQueue::retryRead($argv[2] ?? ''); echo "Read work requeued\n"; break;
        case 'resend':
            $job=BatchQueue::get($argv[2] ?? '');
            if (!$job || $job['kind']!=='outbox' || !isset($job['params'])) throw new RuntimeException('An unarchived outbox job is required');
            $new=BatchQueue::resend($job);
            echo $new['id']."\n";
            break;
        default: throw new RuntimeException('Usage: php queue.php health|work|attention|inspect ID|issues ID|cancel ID|retry-read ID|resend ID');
    }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
