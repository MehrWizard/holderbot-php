<?php
declare(strict_types=1);

/** Bounded concurrent GETs for independent panel pages. */
final class ParallelPanel {
    public static function fetch(array $server, array $endpoints, string $token, int $timeoutSeconds): array {
        if (!$endpoints) return [];
        if (!function_exists('curl_multi_init')) throw new RuntimeException('PHP cURL multi support is unavailable');
        $multi=curl_multi_init(); $handles=[];
        try {
            foreach($endpoints as $page=>$endpoint) {
                $ch=curl_init();
                curl_setopt_array($ch,[
                    CURLOPT_URL=>rtrim((string)$server['base_url'],'/').$endpoint,
                    CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$token],
                    CURLOPT_TIMEOUT_MS=>RequestBudget::milliseconds(max(1,$timeoutSeconds)),
                    CURLOPT_CONNECTTIMEOUT_MS=>RequestBudget::milliseconds(5),
                    CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                ]);
                curl_multi_add_handle($multi,$ch); $handles[(int)$page]=$ch;
            }
            do {
                $status=curl_multi_exec($multi,$active);
                if($status!==CURLM_OK) throw new RuntimeException('Concurrent panel request failed: '.curl_multi_strerror($status));
                if($active) { $selected=curl_multi_select($multi,1.0); if($selected===-1) usleep(1000); }
            } while($active);
            $results=[];
            foreach($handles as $page=>$ch) {
                $body=curl_multi_getcontent($ch); $errno=curl_errno($ch); $error=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
                if($errno || $body===false) throw new RuntimeException("Panel page {$page} connection failed: ".($error?:'no response'));
                if($code<200 || $code>=300) {
                    $detail=preg_replace('/\s+/u',' ',substr((string)$body,0,700))?:'empty response';
                    throw new RuntimeException("Panel page {$page} returned HTTP {$code}: {$detail}");
                }
                $decoded=json_decode((string)$body,true);
                if(!is_array($decoded)) throw new RuntimeException("Panel page {$page} returned invalid JSON: ".json_last_error_msg());
                $results[$page]=$decoded;
            }
            ksort($results); return $results;
        } finally {
            foreach($handles as $ch){curl_multi_remove_handle($multi,$ch);curl_close($ch);} curl_multi_close($multi);
        }
    }
}
