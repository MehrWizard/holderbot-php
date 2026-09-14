<?php
declare(strict_types=1);

/** Durable delivery, independent of panel execution. Requires the caller's transaction when staging a result. */
final class NotificationOutbox
{
    /** A menu has reused the loading message; future results must be sent separately. */
    public static function detachLoadingMessage(int|string $chatId,int $messageId): bool
    {
        if($messageId<=0) return true;
        $db=Storage::db();
        $lock=$db->query("SELECT GET_LOCK('holderbot-notifications', 10)");
        if((int)$lock->fetchColumn()!==1) return false;
        try { Storage::cacheSet('detached_loading_'.$chatId.'_'.$messageId,true,30*86400); }
        finally { $db->query("SELECT RELEASE_LOCK('holderbot-notifications')"); }
        return true;
    }

    public static function loadingMessageDetached(int|string $chatId,int $messageId): bool
    {
        return $messageId>0 && Storage::cacheGet('detached_loading_'.$chatId.'_'.$messageId)===true;
    }

    public static function stage(string $jobId, string $key, int|string $chatId, array $payload): void
    {
        if ((string)$chatId === '0') return;
        Storage::db()->prepare('INSERT INTO bot_notifications(job_id,delivery_key,chat_id,payload) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE id=id')->execute([
            $jobId, $key, $chatId, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function drain(int $limit = 3, ?string $jobId = null): int
    {
        $db=Storage::db(); $done=0;
        for ($i=0;$i<$limit;$i++) {
            if (RequestBudget::$deadline !== null && microtime(true) >= RequestBudget::$deadline-.1) break;
            if ((int)$db->query("SELECT GET_LOCK('holderbot-notifications',0)")->fetchColumn()!==1) break;
            try {
                $sql="SELECT n.* FROM bot_notifications n WHERE n.status='pending' AND n.next_run<=UNIX_TIMESTAMP()";
                if ($jobId !== null) $sql.=' AND n.job_id=?';
                // A retrying QR must not leave a confirmed operation's loading
                // message visible indefinitely. Reports otherwise retain order.
                $sql.=" AND (n.delivery_key='final' OR NOT EXISTS (SELECT 1 FROM bot_notifications previous WHERE previous.job_id=n.job_id AND previous.id<n.id AND previous.status='pending')) ORDER BY n.id LIMIT 1";
                $select=$db->prepare($sql); $select->execute($jobId===null?[]:[$jobId]); $row=$select->fetch();
                if (!$row) break;
                $payload=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
                if(isset($payload['message_id']) && self::loadingMessageDetached($row['chat_id'],(int)$payload['message_id'])) unset($payload['message_id']);
                // Pending remains durable while the network call is in flight.
                $db->prepare('UPDATE bot_notifications SET attempts=attempts+1 WHERE id=?')->execute([$row['id']]);
                $error=null;
                try {
                    $result=isset($payload['photo'])
                        ? QrGenerator::sendQrPhoto($row['chat_id'],$payload['photo'],$payload['text'])
                        : (isset($payload['message_id'])
                            ? tg_replace_message($row['chat_id'],(int)$payload['message_id'],$payload['text'],$payload['keyboard']??null)
                            : tg_send_message($row['chat_id'],$payload['text'],$payload['keyboard']??null));
                } catch (Throwable $e) { $result=null; $error=$e->getMessage(); }
                $code=(int)($result['error_code']??0);
                $status=!empty($result['ok']) ? 'sent' : (($code>=400 && $code<500 && $code!==429) ? 'suppressed' : 'pending');
                $delay=max(30,min(3600,30*(2 ** min(7,(int)$row['attempts']))));
                $delay=max($delay,(int)($result['parameters']['retry_after']??0));
                $error=$error ?? ($result['description'] ?? ($status==='sent'?null:'Delivery response unavailable'));
                if ($error!==null) { preg_match('/\A.{0,900}/us',$error,$match); $error=$match[0] ?? 'Delivery error'; }
                $db->prepare('UPDATE bot_notifications SET status=?,next_run=?,error_text=?,telegram_message_id=? WHERE id=?')->execute([
                    $status,time()+$delay,$error,$result['result']['message_id']??null,$row['id'],
                ]);
                if ($status==='sent' && str_starts_with((string)$row['delivery_key'],'report_') && !empty($result['result']['message_id']) && class_exists('MessageTracker')) {
                    MessageTracker::rememberForCleanup($row['chat_id'],(int)$result['result']['message_id']);
                }
                $done++;
            } finally { $db->query("SELECT RELEASE_LOCK('holderbot-notifications')"); }
        }
        return $done;
    }
}
