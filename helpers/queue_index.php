<?php
declare(strict_types=1);

/** Runnable index, bounded history and incremental payload retention. */
trait QueueIndex {
    private static function indexDirectory(string $name): string {
        $path = self::directory() . '/' . $name;
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Cannot create queue index');
        return $path;
    }
    private static function remember(array $job): void {
        if (!$job['user_id'] || $job['kind']==='outbox') return;
        $path = self::indexDirectory('history') . '/' . hash('sha256', $job['chat_id'] . ':' . $job['user_id']) . '.json';
        $ids = is_file($path) ? self::read($path) : [];
        $ids = array_values(array_diff($ids, [$job['id']]));
        array_unshift($ids, $job['id']);
        $dates=[];
        foreach ($ids as $id) $dates[$id]=$id===$job['id'] ? $job['created_at'] : (self::get($id)['created_at'] ?? 0);
        usort($ids, fn($a,$b)=>$dates[$b]<=>$dates[$a]);
        self::save($path, array_slice($ids, 0, 20));
    }
    private static function initializeIndex(): void {
        $root = self::directory();
        if (is_file($root . '/.indexed')) return;
        // Existing installations are indexed once under the submission lock.
        $lock = fopen($root . '/.enqueue.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot migrate queue index');
        try {
            $cursorFile=$root.'/.migration-cursor.json';
            $listFile=$root.'/.migration-jobs.json';
            // Freeze legacy IDs once; new submissions already create ready markers.
            // This avoids directory-offset changes while webhooks submit new jobs.
            if (!is_file($listFile)) {
                $ids=[];
                foreach (new DirectoryIterator($root) as $entry) {
                    if ($entry->isDir() && preg_match('/^[a-f0-9]{32}$/D',$entry->getFilename())) $ids[]=$entry->getFilename();
                }
                self::save($listFile,$ids);
            }
            $ids=self::read($listFile);
            $cursor=is_file($cursorFile) ? (int)self::read($cursorFile)['offset'] : 0;
            $count=0; $deadline=microtime(true)+0.5;
            while (isset($ids[$cursor]) && $count<100 && microtime(true)<$deadline) {
                $file=$root.'/'.$ids[$cursor].'/job.json';
                if (is_file($file)) {
                    $job=self::read($file);
                    self::save($file,$job);
                    self::remember($job);
                }
                $cursor++; $count++;
            }
            self::save($cursorFile,['offset'=>$cursor]);
            if (!isset($ids[$cursor])) self::atomic($root.'/.indexed','1');
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private static function indexBeforeSave(array &$job): void {
        $active = !in_array($job['status'], self::TERMINAL, true) || $job['notification'] === 'pending';
        if ($active) self::atomic(self::indexDirectory('ready') . '/' . $job['id'], '1');
        else {
            if ($job['status']==='failed' || $job['unconfirmed'] || $job['delivery_failed'] || $job['owner_failed']) self::atomic(self::indexDirectory('attention').'/'.$job['id'],'1');
            $job['finished_at'] ??= time();
            self::atomic(self::indexDirectory('archive/' . gmdate('Y-m-d', $job['finished_at'])) . '/' . $job['id'], '1');
        }
    }
    private static function indexAfterSave(array $job): void {
        if (in_array($job['status'], self::TERMINAL, true) && $job['notification'] !== 'pending') {
            $file = self::indexDirectory('ready') . '/' . $job['id'];
            if (is_file($file)) unlink($file);
        }
    }
    /** At most $limit files removed, with a separate maintenance time allowance. */
    public static function maintain(int $limit = 50): int {
        global $config;
        $root = self::directory(); $done = 0; $deadline = microtime(true) + 0.5;
        $days = max(1, (int)($config['queue_retention_days'] ?? 7));
        foreach (glob(self::indexDirectory('archive') . '/*', GLOB_ONLYDIR) ?: [] as $bucket) {
            if (basename($bucket) >= gmdate('Y-m-d', time() - $days * 86400)) continue;
            foreach (new DirectoryIterator($bucket) as $entry) {
                if ($entry->isDot()) continue;
                if ($done >= $limit || microtime(true) > $deadline) return $done;
                $id = $entry->getFilename();
                if (!preg_match('/^[a-f0-9]{32}$/D', $id)) continue;
                $job = self::get($id);
                if (!$job || !in_array($job['status'], self::TERMINAL, true) || $job['notification'] === 'pending') continue;
                if (($job['finished_at'] ?? time()) >= time()-$days*86400) continue;
                // Compact metadata remains permanently for duplicate-submission protection.
                $tombstone = array_intersect_key($job,array_flip(['id','kind','server_id','chat_id','user_id','status','created_at','updated_at','finished_at','cursor','total','success','unconfirmed','skipped','delivery_failed','owner_failed','notification','summary_id']));
                $tombstone['archived'] = true;
                self::save(self::indexDirectory('tombstones/' . substr($id, 0, 2)) . '/' . $id . '.json', $tombstone);
                $dir = $root . '/' . $id;
                if (is_dir($dir)) {
                    foreach (new DirectoryIterator($dir) as $file) {
                        if ($file->isDot() || $file->getFilename() === 'job.json') continue;
                        if ($done >= $limit || microtime(true) > $deadline) return $done;
                        if ($file->isFile()) { unlink($file->getPathname()); $done++; }
                    }
                    if (is_file($dir . '/job.json')) { unlink($dir . '/job.json'); $done++; }
                    rmdir($dir);
                }
                $attention=self::indexDirectory('attention').'/'.$id;
                if (is_file($attention)) unlink($attention);
                unlink($entry->getPathname());
            }
            if (!(new FilesystemIterator($bucket))->valid()) rmdir($bucket);
        }
        return $done;
    }
    public static function retryRead(string $id): void {
        $lock=fopen(self::directory().'/.worker.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Worker is busy; retry later');
        try {
            $job=self::get($id);
            if (!$job || !empty($job['archived']) || $job['status']!=='failed' || $job['active']!==null
                || !in_array($job['kind'],['stats','expiry','access','monitor','import'],true)) throw new RuntimeException('Only failed, unarchived read jobs can be retried');
            $job['status']='running'; $job['next_run']=0; $job['read_failures']=0;
            $job['notification']=$job['user_id'] ? 'pending':'suppressed';
            unset($job['error'],$job['finished_at']);
            self::save(self::directory().'/'.$id.'/job.json',$job);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public static function resend(array $job): array {
        if ($job['kind']!=='outbox' || !isset($job['params'])) throw new RuntimeException('Cannot resend this job');
        return self::enqueue('outbox',self::virtualServer(),$job['params'],$job['chat_id'],$job['user_id'],'manual-resend:'.$job['id'].':'.bin2hex(random_bytes(8)));
    }
    public static function health(): array {
        $heartbeat = self::directory() . '/heartbeat.json';
        $result = is_file($heartbeat) ? self::read($heartbeat) : ['last_run'=>null];
        $result['needs_attention']=count(glob(self::indexDirectory('attention').'/*') ?: []);
        $result['pending'] = count(glob(self::indexDirectory('ready') . '/*') ?: []);
        $result['stale'] = $result['last_run'] === null || time() - $result['last_run'] > 180 || !empty($result['error']);
        return $result;
    }
}
