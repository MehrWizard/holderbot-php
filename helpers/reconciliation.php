<?php
declare(strict_types=1);

/** Read-only comparison. Never sends a mutation or infers success from absent fields. */
final class MutationReconciliation
{
    public static function compare(string $type, array $intent, ?array $current): array
    {
        if (!in_array($type,['marzban','marzneshin'],true)) return ['state'=>'unsupported'];
        $before=$intent['before'] ?? null;
        if (!is_array($before) || empty($intent['username']) || ($before['username'] ?? null)!==$intent['username']) return ['state'=>'insufficient_evidence'];
        $phase=$intent['phase'] ?? '';
        if ($phase==='delete_user') {
            if (($before['username'] ?? null)!==$intent['username']) return ['state'=>'insufficient_evidence'];
            return ['state'=>$current===null?'desired_state_observed':'unchanged'];
        }
        if ($phase==='create_user') {
            if (($before['_exists'] ?? null)!==false || $current===null) return ['state'=>'insufficient_evidence'];
            if (($current['username'] ?? null)!==$intent['username']) return ['state'=>'identity_conflict'];
            $expected=$intent['payload'] ?? []; $mismatch=[];
            foreach (['data_limit','expire','status','on_hold_expire_duration','expire_strategy','expire_date','usage_duration','note'] as $key) {
                if (!array_key_exists($key,$expected) || $expected[$key]===null) continue;
                if (!array_key_exists($key,$current)) { $mismatch[]=$key; continue; }
                $actual=$current[$key]; $value=$expected[$key];
                if (in_array($key,['expire_date'],true) && $actual!==null) {
                    try { $actual=(new DateTimeImmutable($actual))->getTimestamp(); $value=(new DateTimeImmutable($value))->getTimestamp(); }
                    catch(Throwable) { $mismatch[]=$key; continue; }
                }
                if ($actual!==$value && !(is_numeric($actual)&&is_numeric($value)&&(string)$actual===(string)$value)) $mismatch[]=$key;
            }
            if (!empty($expected['owner_username'])) {
                $owner=$type==='marzban' ? ($current['admin']['username'] ?? $current['owner'] ?? null) : ($current['owner_username'] ?? $current['admin_username'] ?? null);
                if ($owner!==$expected['owner_username']) $mismatch[]='owner_username';
            }
            if ($type==='marzneshin' && array_key_exists('service_ids',$expected)) {
                $wanted=array_map('strval',$expected['service_ids']); $actual=array_map('strval',$current['service_ids'] ?? []);
                sort($wanted); sort($actual); if ($wanted!==$actual) $mismatch[]='service_ids';
            }
            if ($type==='marzban' && !empty($expected['selected_configs'])) {
                $actual=[];
                foreach (($current['inbounds'] ?? []) as $items) foreach ((array)$items as $tag) $actual[]=(string)$tag;
                $wanted=array_map('strval',$expected['selected_configs']); sort($wanted); sort($actual);
                if ($wanted!==$actual) $mismatch[]='selected_configs';
            }
            return ['state'=>$mismatch?'creation_conflict':'desired_state_observed','mismatched'=>$mismatch];
        }
        if ($current===null || ($current['username'] ?? null)!==$intent['username']) return ['state'=>'insufficient_evidence'];
        if ($phase==='modify_user') {
            $expected=$intent['payload'] ?? []; $mismatch=[]; $missing=[];
            foreach($expected as $key=>$value) {
                $actual=match($key) {
                    'owner_username'=>$type==='marzban' ? ($current['admin']['username'] ?? $current['owner'] ?? null) : ($current['owner_username'] ?? $current['admin_username'] ?? null),
                    'enabled'=>$type==='marzban' ? (($current['status'] ?? null)==='active') : ($current['enabled'] ?? null),
                    'service_ids'=>$current['service_ids'] ?? null,
                    'selected_configs'=>self::marzbanConfigs($current),
                    default=>$current[$key] ?? null,
                };
                if ($actual===null && !array_key_exists($key,$current) && !in_array($key,['owner_username','enabled','service_ids','selected_configs'],true)) { $missing[]=$key; continue; }
                if(in_array($key,['service_ids','selected_configs'],true)) {
                    $a=array_map('strval',(array)$actual); $b=array_map('strval',(array)$value); sort($a); sort($b);
                    if($a!==$b)$mismatch[]=$key;
                } elseif($actual!==$value && !(is_numeric($actual)&&is_numeric($value)&&(string)$actual===(string)$value)) $mismatch[]=$key;
            }
            if(!$expected || $missing) return ['state'=>'insufficient_evidence','missing'=>$missing];
            return ['state'=>$mismatch?'conflict':'desired_state_observed','mismatched'=>$mismatch];
        }
        if ($phase==='assign_created_owner') {
            $owner=$type==='marzban' ? ($current['admin']['username'] ?? $current['owner'] ?? null) : ($current['owner_username'] ?? $current['admin_username'] ?? null);
            if ($owner===null || empty($intent['payload']['owner_username'])) return ['state'=>'insufficient_evidence'];
            // Preserve identity evidence, not just an existing username.
            if (empty($before['subscription_url']) || ($current['subscription_url'] ?? null)!==$before['subscription_url']) return ['state'=>'identity_conflict'];
            return ['state'=>$owner===$intent['payload']['owner_username']?'desired_state_observed':'partial_creation'];
        }
        if ($phase==='apply_recharge') {
            $expected=$intent['payload'] ?? [];
            $allowed=$type==='marzban' ? ['data_limit','expire','status','on_hold_expire_duration','on_hold_timeout'] : ['username','data_limit','expire_strategy','expire_date','usage_duration','activation_deadline'];
            $mismatch=[]; $missing=[];
            foreach($expected as $key=>$value) {
                if (!in_array($key,$allowed,true)) return ['state'=>'unsupported_field','field'=>$key];
                if (!array_key_exists($key,$current)) { $missing[]=$key; continue; }
                $actual=$current[$key];
                if (in_array($key,['expire_date','activation_deadline'],true) && $value!==null && $actual!==null) {
                    try { $actual=(new DateTimeImmutable($actual))->getTimestamp(); $value=(new DateTimeImmutable($value))->getTimestamp(); }
                    catch(Throwable) { $mismatch[]=$key; continue; }
                }
                if ($actual!==$value && !(is_numeric($actual) && is_numeric($value) && (string)$actual===(string)$value)) $mismatch[]=$key;
            }
            if (!$expected || $missing) return ['state'=>'insufficient_evidence','missing'=>$missing];
            return ['state'=>$mismatch?'conflict':'desired_state_observed','mismatched'=>$mismatch];
        }
        if ($phase==='revoke_subscription') {
            if (empty($current['subscription_url']) || empty($before['subscription_url'])) return ['state'=>'insufficient_evidence'];
            return ['state'=>$current['subscription_url']===$before['subscription_url']?'unchanged':'subscription_changed'];
        }
        if ($phase==='reset_usage') {
            $field=$type==='marzneshin'?'traffic_reset_at':'last_traffic_reset_time';
            if (empty($current[$field]) || !array_key_exists($field,$before)) return ['state'=>'insufficient_evidence'];
            try {
                $now=(new DateTimeImmutable($current[$field]))->getTimestamp();
                $old=$before[$field]===null ? 0 : (new DateTimeImmutable($before[$field]))->getTimestamp();
            } catch(Throwable) { return ['state'=>'insufficient_evidence']; }
            return ['state'=>$now>$old?'reset_marker_changed':'unchanged'];
        }
        return ['state'=>'manual_review'];
    }

    private static function marzbanConfigs(array $raw): array
    {
        $out=[];
        foreach(($raw['inbounds'] ?? []) as $items) foreach((array)$items as $tag) $out[]=(string)$tag;
        return $out;
    }
}
