<?php
declare(strict_types=1);

/** Incremental top-level JSON-array reader; never loads the complete decoded import. */
class QueueImport {
    public static function step(string $dir, array &$job): void {
        if (!isset($job['import_offset'])) {
            $content=tg_download_file($job['params']['import_file_id'],8388608);
            if ($content===null) throw new RuntimeException('Cannot download import');
            if (strlen($content)>8388608) throw new LengthException('Import exceeds 8 MiB');
            self::write($dir.'/import.json',$content);
            $job['import_offset']=0; $job['import_count']=0; $job['import_page']=0;
            return;
        }
        $fp=fopen($dir.'/import.json','rb');
        if (!$fp) throw new RuntimeException('Import file missing');
        try {
            fseek($fp,$job['import_offset']);
            if ($job['import_offset']===0 && self::nonspace($fp)!=='[') throw new LengthException('Import must be a JSON array');
            $items=[]; $finished=false;
            for ($i=0;$i<50;$i++) {
                $first=self::nonspace($fp);
                if ($first===']' && $job['import_count']===0 && !$items) { $finished=true; break; }
                if ($first!=='{') throw new LengthException('Import entries must be objects');
                $raw='{'; $depth=1; $quoted=false; $escape=false;
                while ($depth>0) {
                    $char=fgetc($fp);
                    if ($char===false || strlen($raw)>65536) throw new LengthException('Incomplete or oversized import entry');
                    $raw.=$char;
                    if ($quoted) {
                        if ($escape) $escape=false;
                        elseif ($char==='\\') $escape=true;
                        elseif ($char==='"') $quoted=false;
                    } elseif ($char==='"') $quoted=true;
                    elseif ($char==='{' || $char==='[') $depth++;
                    elseif ($char==='}' || $char===']') $depth--;
                }
                try { $item=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
                catch (JsonException $e) { throw new LengthException('Invalid JSON entry'); }
                self::validate($item);
                $items[]=$item;
                $separator=self::nonspace($fp);
                if ($separator===']') { $finished=true; break; }
                if ($separator!==',') throw new LengthException('Invalid JSON separator');
            }
            if ($finished && self::nonspace($fp)!==false) throw new LengthException('Unexpected data after import');
            $count=$job['import_count']+count($items);
            if ($count>10000 || ($finished && !$count)) throw new LengthException('Import must contain 1 to 10000 users');
            self::write($dir.'/input-'.$job['import_page'].'.json',json_encode($items,JSON_THROW_ON_ERROR));
            $job['import_count']=$count; $job['import_page']++; $job['import_offset']=ftell($fp);
            if ($finished) {
                $job['kind']='create'; $job['params']['json']=true; $job['total']=$count; $job['cursor']=0;
                unset($job['params']['import_file_id']);
            }
        } finally { fclose($fp); }
    }
    public static function validate(mixed $item): void {
        if (!is_array($item) || !is_string($item['username'] ?? null) || $item['username']===''
            || !is_numeric($item['datalimit'] ?? null) || !is_finite((float)$item['datalimit']) || $item['datalimit']<0 || $item['datalimit']>PHP_INT_MAX/(1024**3)
            || !is_numeric($item['datelimit'] ?? null) || !is_finite((float)$item['datelimit']) || $item['datelimit']<0 || $item['datelimit']>(PHP_INT_MAX-time())/86400
            || !in_array($item['datetypes'] ?? null,['now','unlimited','after first use'],true)) throw new LengthException('Invalid import entry');
    }
    private static function nonspace($fp): string|false {
        do { $char=fgetc($fp); } while ($char!==false && str_contains(" \r\n\t",$char));
        return $char;
    }
    private static function write(string $path,string $text): void {
        $temp=$path.'.tmp'; $fp=fopen($temp,'wb');
        if (!$fp) throw new RuntimeException('Cannot write import');
        try {
            chmod($temp,0600);
            if (fwrite($fp,$text)!==strlen($text) || !fflush($fp) || (function_exists('fsync') && !fsync($fp))) throw new RuntimeException('Cannot save import');
        } finally { fclose($fp); }
        if (!rename($temp,$path)) throw new RuntimeException('Cannot publish import');
    }
}
