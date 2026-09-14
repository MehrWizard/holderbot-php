<?php
declare(strict_types=1);

/** Telegram layouts and labels from app/keys/manager.py. */
class Keyboards {
    private const SELECTOR_PAGE=20;
    /** Keep navigation in one final row, including keyboards assembled elsewhere. */
    public static function navigationLast(array $markup): array {
        if (!isset($markup['inline_keyboard']) || !is_array($markup['inline_keyboard'])) return $markup;
        $rows=[]; $back=null; $home=null;
        foreach ($markup['inline_keyboard'] as $row) {
            $content=[];
            foreach ($row as $button) {
                $data=(string)($button['callback_data'] ?? '');
                $label=(string)($button['text'] ?? '');
                if ($data==='home' || $data==='queue_home') $home=$button;
                elseif (str_starts_with($label,'◀️ Back') || $label==='Back') $back=$button;
                else $content[]=$button;
            }
            if ($content) $rows[]=$content;
        }
        $navigation=[];
        if ($back) $navigation[]=$back;
        if ($home) $navigation[]=$home;
        if ($navigation) $rows[]=$navigation;
        $markup['inline_keyboard']=$rows;
        return $markup;
    }
    private static function button(string $text, string $data): array {
        return ['text' => $text, 'callback_data' => $data];
    }
    private static function rows(array $buttons, int $width = 2, ?string $back = null): array {
        $rows = array_chunk($buttons, $width);
        $rows[] = [self::button('🏛️ Home', 'home')];
        if ($back && $back !== 'home') $rows[] = [self::button('◀️ Back', $back)];
        return self::navigationLast(['inline_keyboard' => $rows]);
    }
    public static function home(array $servers,int $page=1): array {
        $page=max(1,$page); $total=count($servers); $servers=array_slice($servers,($page-1)*self::SELECTOR_PAGE,self::SELECTOR_PAGE);
        $buttons = [];
        foreach ($servers as $s) $buttons[] = self::button((($s['is_active'] ?? true) ? '✅ ' : '❌ ') . $s['remark'], "srv:{$s['id']}");
        $rows = array_chunk($buttons, 2);
        $rows[] = [self::button('🗃 Templates', 'tmpls'), self::button('➕ Add Server', 'add_srv')];
        $nav=[]; if($page>1)$nav[]=self::button('⬅️','home_page:'.($page-1)); if($page*self::SELECTOR_PAGE<$total)$nav[]=self::button('➡️','home_page:'.($page+1)); if($nav)$rows[]=$nav;
        return ['inline_keyboard' => $rows];
    }
    public static function serverMenu(int $serverId): array {
        return self::navigationLast(['inline_keyboard' => [
            [self::button('👤 Users', "users:{$serverId}:1:all"), self::button('🗄 Actions', "act_menu:{$serverId}")],
            [self::button('📊 Stats', "stats:{$serverId}"), self::button('➕ Create User', "new_usr:{$serverId}")],
            [self::button('🔍 Search User', "srch_usr:{$serverId}")],
            [self::button('☁️ Server', "srv_cfg:{$serverId}"), self::button('🏛️ Home', 'home')],
        ]]);
    }
    public static function stats(int $serverId): array {
        return ['inline_keyboard'=>[
            [self::button('🔄 Refresh Stats',"stats_refresh:{$serverId}")],
            [self::button('◀️ Back',"stats_back:{$serverId}")],
        ]];
    }
    public static function serverSettings(array $server): array {
        $id = $server['id'];
        return self::rows([
            self::button('🏷 Remark', "srv_edit_remark:{$id}"), self::button('📋 Data', "srv_edit_creds:{$id}"),
            self::button('📡 Monitoring Nodes', "tgl_srv_mon_ask:{$id}"), self::button('🔄 Auto Restart Nodes', "tgl_srv_res_ask:{$id}"),
            self::button('⚰️ Expired Stats', "tgl_srv_exp_ask:{$id}"), self::button('🗑 Remove', "del_srv_ask:{$id}"),
        ], 2, "srv:{$id}");
    }
    public static function usersList(int $serverId, array $users, int $page = 1, bool $hasMore = false, string $filter = 'all', bool $search = false): array {
        $buttons = [];
        foreach ($users as $u) $buttons[] = self::button(($u['is_active'] ? '✅ ' : '❌ ') . $u['username'], "usr:{$serverId}:{$u['username']}");
        $rows = array_chunk($buttons, 2);
        if (!$search) {
            $filters = [];
            foreach (['active' => '🟢', 'limited' => '🔴', 'expired' => '🟡'] as $value => $emoji) {
                $filters[] = self::button(($filter === $value ? '✔' : '') . $emoji, "users:{$serverId}:1:{$value}");
            }
            $rows[] = $filters;
            $nav = [];
            if ($page > 1) $nav[] = self::button('⬅️', "users:{$serverId}:" . ($page - 1) . ":{$filter}");
            if ($hasMore) $nav[] = self::button('➡️', "users:{$serverId}:" . ($page + 1) . ":{$filter}");
            if ($nav) $rows[] = $nav;
        } else $rows[] = [self::button('🔍 Search User', "srch_usr:{$serverId}")];
        $rows[] = [self::button('➕ Create', "new_usr:{$serverId}"), self::button('🏛️ Home', 'home')];
        $rows[] = [self::button('◀️ Back', "srv:{$serverId}")];
        return self::navigationLast(['inline_keyboard' => $rows]);
    }
    public static function userActions(int $serverId, string $username, bool $isActive, string $status, ?string $backData = null): array {
        $actions = [
            'dl' => '📊 Data Limit', 'dt' => '⏱️ Date Limit', 'tgl_ask' => $isActive ? '❌ Disabled' : '✅ Activated',
            'rst_ask' => '🔁 Reset Usage', 'rvk_ask' => '⛓️‍💥 Revoke', 'qr' => '🖼 Qrcode',
            'nt' => '🗒 Note', 'own' => '👤 Set Owner', 'cfg' => '📂 Configs', 'chg' => '🧪 Charge', 'del' => '🗑 Remove',
        ];
        $buttons = [];
        foreach ($actions as $action => $label) $buttons[] = self::button($label, "act:{$serverId}:{$username}:{$action}");
        return self::rows($buttons, 2, $backData ?? "srv:{$serverId}");
    }
    public static function actionsMenu(int $serverId, string $serverType = 'marzban'): array {
        $actions = ['act_adm' => '✔️ Activate Users', 'dis_adm' => '✖️ Disabled Users', 'del_exp' => '🗑 Delete Expired', 'del_lim' => '🗑 Delete Limited', 'del_all' => '🗑 Delete Admin Users', 'xfer_adm' => '💱 Transfer Users'];
        if ($serverType === 'marzneshin') $actions += ['add_cfg' => '➕ Add Config', 'del_cfg' => '➖ Delete Config'];
        $buttons = [];
        foreach ($actions as $action => $label) $buttons[] = self::button($label, "act_item:{$serverId}:{$action}");
        return self::rows($buttons, 2, "srv:{$serverId}");
    }
    public static function adminsSelector(int $serverId, array $admins, string $actionPrefix, bool $includeAll = false, ?string $backData = null,int $page=1): array {
        if ($includeAll) $admins[] = 'ALL';
        $page=max(1,$page); $total=count($admins); $admins=array_slice($admins,($page-1)*self::SELECTOR_PAGE,self::SELECTOR_PAGE);
        $buttons = [];
        foreach ($admins as $admin) $buttons[] = self::button($admin, "{$actionPrefix}:{$serverId}:{$admin}");
        $markup=self::rows($buttons,2,$backData ?: "srv:{$serverId}");
        $nav=[]; $token=rawurlencode($actionPrefix); $back=rawurlencode($backData ?: "srv:{$serverId}");
        if($page>1)$nav[]=self::button('⬅️',"admins_page:{$serverId}:".($page-1).':'.(int)$includeAll.":{$token}:{$back}");
        if($page*self::SELECTOR_PAGE<$total)$nav[]=self::button('➡️',"admins_page:{$serverId}:".($page+1).':'.(int)$includeAll.":{$token}:{$back}");
        if($nav)array_splice($markup['inline_keyboard'],-1,0,[$nav]);
        return $markup;
    }
    public static function templatesMenu(array $templates,int $page=1): array {
        $page=max(1,$page); $total=count($templates); $templates=array_slice($templates,($page-1)*self::SELECTOR_PAGE,self::SELECTOR_PAGE);
        $buttons = [];
        foreach ($templates as $t) $buttons[] = self::button((($t['is_active'] ?? true) ? '✅ ' : '❌ ') . $t['remark'], "tmpl_view:{$t['id']}");
        $rows = array_chunk($buttons, 2);
        $rows[] = [self::button('➕ Create', 'new_tmpl'), self::button('🏛️ Home', 'home')];
        $nav=[]; if($page>1)$nav[]=self::button('⬅️','tmpls:'.($page-1)); if($page*self::SELECTOR_PAGE<$total)$nav[]=self::button('➡️','tmpls:'.($page+1)); if($nav)$rows[]=$nav;
        return self::navigationLast(['inline_keyboard' => $rows]);
    }
    public static function templateActions(int $tmplId, bool $isActive = true): array {
        return self::rows([
            self::button('📊 Data Limit', "tmpl_edit_data:{$tmplId}"), self::button('⏱️ Date Limit', "tmpl_edit_date:{$tmplId}"),
            self::button($isActive ? '❌ Disabled' : '✅ Activated', "tmpl_tgl_ask:{$tmplId}"), self::button('🏷 Remark', "tmpl_edit_remark:{$tmplId}"),
            self::button('🗑 Remove', "tmpl_del_ask:{$tmplId}"),
        ]);
    }
    public static function confirm(string $confirmData, string $cancelData = 'home'): array {
        $back = $cancelData;
        if (str_starts_with($back, 'act_confirm:')) {
            $parts = explode(':', $back);
            $back = "usr:{$parts[2]}:{$parts[3]}";
        } elseif (preg_match('/^(?:srv_cfg|act_menu):(\d+)$/', $back, $m)) $back = 'srv:' . $m[1];
        elseif (str_starts_with($back, 'tmpl_view:')) $back = 'home';
        return self::rows([self::button('✅ Yes', $confirmData), self::button('❌ No', $cancelData)], 2, $cancelData);
    }
    public static function dateTypeSelector(int $serverId, string $username, string $prefix = 'dt_type'): array {
        $back = $prefix === 'crt_dt_type' ? "srv:{$serverId}" : "usr:{$serverId}:{$username}";
        if ($prefix === 'crt_dt_type') {
            return self::rows([
                self::button('unlimited', "{$prefix}:{$serverId}:unlimited"),
                self::button('now', "{$prefix}:{$serverId}:fixed"),
                self::button('after first use', "{$prefix}:{$serverId}:onhold"),
            ], 1, $back);
        }
        return self::rows([
            self::button('unlimited', "{$prefix}:{$serverId}:{$username}:unlimited"),
            self::button('now', "{$prefix}:{$serverId}:{$username}:fixed"),
            self::button('after first use', "{$prefix}:{$serverId}:{$username}:onhold"),
        ], 1, $back);
    }
    public static function templateDateTypeSelector(string $prefix, string $cancelData): array {
        return self::rows([
            self::button('unlimited', "{$prefix}:unlimited"), self::button('now', "{$prefix}:fixed"), self::button('after first use', "{$prefix}:onhold"),
        ], 1, $cancelData);
    }
    public static function chargeConfirmOptions(int $serverId, string $username, float $dataLimit, int $dateLimit): array {
        return self::rows([
            self::button('✅ Yes, reset usage', "chg_rst_opt:{$serverId}:reset"),
            self::button('✅ Yes, reset charge', "chg_rst_opt:{$serverId}:additive"),
            self::button('✅ Yes, Normal', "chg_rst_opt:{$serverId}:normal"),
            self::button('❌ No', "decline:usr:{$serverId}:{$username}"),
        ], 1, "usr:{$serverId}:{$username}");
    }
    public static function templateSelector(int $serverId, array $templates, string $prefix = 'use_tmpl', bool $allowCustom = true,int $page=1,?string $backData=null): array {
        $templates=array_values(array_filter($templates,fn($t)=>!isset($t['is_active']) || !empty($t['is_active'])));
        $page=max(1,$page);$total=count($templates);
        $templates=array_slice($templates,($page-1)*self::SELECTOR_PAGE,self::SELECTOR_PAGE);
        $buttons = [];
        foreach ($templates as $t) {
            $buttons[] = self::button("{$t['id']} | {$t['remark']} [{$t['data_limit']} GB - {$t['date_limit']} Day]", "{$prefix}:{$serverId}:{$t['id']}");
        }
        if ($allowCustom) $buttons[] = self::button('CUSTOM', "{$prefix}_custom:{$serverId}");
        $markup=self::rows($buttons,1,$backData ?? "srv:{$serverId}");
        $nav=[];
        if($page>1)$nav[]=self::button('⬅️',"template_page:{$prefix}:{$serverId}:".($page-1));
        if($page*self::SELECTOR_PAGE<$total)$nav[]=self::button('➡️',"template_page:{$prefix}:{$serverId}:".($page+1));
        if($nav)array_splice($markup['inline_keyboard'],-1,0,[$nav]);
        return $markup;
    }
    public static function configSelector(int $serverId, array $configs, array $selectedNames, string $prefix, string $doneCallback, string $cancelCallback,int $page=1): array {
        $page=max(1,$page); $total=count($configs); $allConfigs=$configs; $configs=array_slice($configs,($page-1)*self::SELECTOR_PAGE,self::SELECTOR_PAGE);
        $buttons = [];
        $selected = array_map('strval', $selectedNames);
        foreach ($configs as $cfg) {
            $name = $cfg['name'] ?? ($cfg['remark'] ?? (string)$cfg['id']);
            $checked = in_array((string)$cfg['id'], $selected, true) || in_array($name, $selected, true);
            $buttons[] = self::button(($checked ? '✅ ' : '❌ ') . $name, "{$prefix}:tgl:{$serverId}:" . rawurlencode((string)$cfg['id']));
        }
        $rows = array_chunk($buttons, 2);
        $all = [];
        if (count($selected) !== count($allConfigs)) $all[] = self::button('Select All', "{$prefix}:all:{$serverId}");
        if (count($selected) > 0) $all[] = self::button('DeSelect All', "{$prefix}:none:{$serverId}");
        if ($all) $rows[] = $all;
        $nav=[]; $token=rawurlencode($prefix); $done=rawurlencode($doneCallback); $cancel=rawurlencode($cancelCallback);
        if($page>1)$nav[]=self::button('⬅️',"configs_page:{$serverId}:".($page-1).":{$token}:{$done}:{$cancel}");
        if($page*self::SELECTOR_PAGE<$total)$nav[]=self::button('➡️',"configs_page:{$serverId}:".($page+1).":{$token}:{$done}:{$cancel}");
        if($nav)$rows[]=$nav;
        $rows[] = [self::button('✔️ DONE', $doneCallback), self::button('🏛️ Home', 'home')];
        if ($cancelCallback !== 'home') $rows[] = [self::button('◀️ Back', $cancelCallback)];
        return self::navigationLast(['inline_keyboard' => $rows]);
    }
    public static function bulkServices(int $serverId,array $services,int $page=1): array {
        $page=max(1,$page);$total=count($services);$services=array_slice($services,($page-1)*self::SELECTOR_PAGE,self::SELECTOR_PAGE);$rows=[];
        foreach($services as $svc){$name=$svc['name']??($svc['remark']??('Service #'.$svc['id']));$rows[]=[self::button($name,"bulk_cfg:{$serverId}:".rawurlencode((string)$svc['id']))];}
        $nav=[];if($page>1)$nav[]=self::button('⬅️',"bulk_services_page:{$serverId}:".($page-1));if($page*self::SELECTOR_PAGE<$total)$nav[]=self::button('➡️',"bulk_services_page:{$serverId}:".($page+1));if($nav)$rows[]=$nav;
        $rows[]=[self::button('🏛️ Home','home')];$rows[]=[self::button('◀️ Back',"srv:{$serverId}")];return self::navigationLast(['inline_keyboard'=>$rows]);
    }
    public static function cancel(string $backData = 'home'): array {
        return self::rows([], 2, $backData);
    }
    public static function serverTypes(): array {
        return self::rows([self::button('marzneshin', 'srv_type:marzneshin'), self::button('marzban', 'srv_type:marzban')]);
    }
}
