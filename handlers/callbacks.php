<?php
/**
 * HolderBot PHP - Complete Callback Query Handlers
 */

declare(strict_types=1);
if(!class_exists('BatchQueue')) require_once __DIR__ . '/../helpers/queue.php';

class CallbackHandlers {
    /**
     * Dispatch callback queries.
     */
    public static function handle(array $callbackQuery): void {
        $id = $callbackQuery['id'];
        $data = $callbackQuery['data'] ?? '';
        if (str_starts_with($data, 'ref:')) {
            $resolved=Storage::cacheGet($data);
            if(!is_string($resolved)||$resolved===''){tg_answer_callback($id,'This menu has expired. Open the menu again.',true);return;}
            $data=$resolved;
        }
        $chatId = $callbackQuery['message']['chat']['id'] ?? 0;
        $messageId = $callbackQuery['message']['message_id'] ?? 0;
        // Telegram supplies the original bot message's Unix timestamp here.
        $GLOBALS['tg_callback_message'] = ['chat_id'=>$chatId,'message_id'=>$messageId,'date'=>(int)($callbackQuery['message']['date'] ?? 0)];
        $userId = $callbackQuery['from']['id'];

        if ($data === 'queue_home') {
            if(!NotificationOutbox::detachLoadingMessage($chatId,$messageId)){tg_answer_callback($id,'Please try again shortly.',true);return;}
            Storage::clearState($userId);
            tg_answer_callback($id);
            self::renderHome($chatId, $messageId);
            return;
        }
        if(str_starts_with($data,'home_page:')) {
            $page=max(1,(int)substr($data,strlen('home_page:'))); Storage::clearState($userId); tg_answer_callback($id);
            tg_edit_message($chatId,$messageId,Formatter::start(),Keyboards::home(Storage::getServers(),$page)); return;
        }
        if(str_starts_with($data,'admins_page:')) {
            $parts=explode(':',$data,6); $serverId=(int)($parts[1]??0); $page=max(1,(int)($parts[2]??1)); $includeAll=(bool)($parts[3]??0);
            $prefix=rawurldecode($parts[4]??''); $back=rawurldecode($parts[5]??''); $server=Storage::getServer($serverId);
            $state=Storage::getState($userId); $step=$state['step']??''; $sd=$state['data']??[];
            $expected=match($step) {
                'create_user_admin'=>'new_usr_adm',
                'user_mod_owner'=>'set_own:'.rawurlencode((string)($sd['username']??'')),
                'bulk_action_select'=>in_array($sd['action']??'',['add_cfg','del_cfg'],true)?'cfg_adm:'.$sd['action']:(($sd['action']??'')==='xfer_adm'?'xfer_from':'confirm_act:'.($sd['action']??'')),
                'xfer_target'=>'xfer_to', default=>'',
            };
            if(!$server || $prefix==='' || $prefix!==$expected || (int)($sd['server_id']??0)!==$serverId){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            tg_answer_callback($id); tg_edit_message($chatId,$messageId,'Select admin:',Keyboards::adminsSelector($serverId,PanelManager::getAdmins($server),$prefix,$includeAll,$back,$page)); return;
        }
        if(str_starts_with($data,'configs_page:')) {
            $parts=explode(':',$data,7); $serverId=(int)($parts[1]??0); $page=max(1,(int)($parts[2]??1)); $prefix=rawurldecode($parts[3]??''); $done=rawurldecode($parts[4]??''); $back=rawurldecode($parts[5]??'');
            $server=Storage::getServer($serverId); $state=Storage::getState($userId);
            $sd=$state['data']??[];$expectedPrefix=($state['step']??'')==='create_user_configs'?'usr_cfg':((($state['step']??'')==='user_mod_configs')?'cfg_pick:'.rawurlencode((string)($sd['username']??'')):'');
            $expectedDone=($state['step']??'')==='create_user_configs'?"usr_cfg_done:{$serverId}":"cfg_save:{$serverId}:".($sd['username']??'');
            if(!$server || !$state || $prefix==='' || $prefix!==$expectedPrefix || $done!==$expectedDone || (int)($sd['server_id']??0)!==$serverId){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            $state['data']['selector_page']=$page; Storage::setState($userId,$state['step'],$state['data']);
            $selected=$state['data']['current_services'] ?? $state['data']['selected_configs'] ?? [];
            tg_answer_callback($id); tg_edit_message($chatId,$messageId,'Select Configs:',Keyboards::configSelector($serverId,PanelManager::getServices($server),$selected,$prefix,$done,$back,$page)); return;
        }
        if(str_starts_with($data,'bulk_services_page:')) {
            $parts=explode(':',$data);$serverId=(int)($parts[1]??0);$page=max(1,(int)($parts[2]??1));$server=Storage::getServer($serverId);$state=Storage::getState($userId);
            if(!$server || ($state['step']??'')!=='cfg_action_pick' || (int)($state['data']['server_id']??0)!==$serverId){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            tg_answer_callback($id);tg_edit_message($chatId,$messageId,'Select items',Keyboards::bulkServices($serverId,PanelManager::getServices($server),$page,$state['data']['return_to']??null));return;
        }
        if(str_starts_with($data,'template_page:')) {
            $parts=explode(':',$data,4);$prefix=$parts[1]??'';$serverId=(int)($parts[2]??0);$page=max(1,(int)($parts[3]??1));
            $server=Storage::getServer($serverId);$state=Storage::getState($userId);
            $expectedStep=$prefix==='use_tmpl'?'create_user_template':($prefix==='chg_tmpl'?'user_mod_charge':'');
            if(!$server || $expectedStep==='' || ($state['step']??'')!==$expectedStep || (int)($state['data']['server_id']??0)!==$serverId){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            $back=$prefix==='chg_tmpl'?'usr:'.$serverId.':'.$state['data']['username']:"srv:{$serverId}";
            tg_answer_callback($id);
            tg_edit_message($chatId,$messageId,'Select items',Keyboards::templateSelector($serverId,Storage::getActiveTemplates(),$prefix,$prefix==='use_tmpl',$page,$back));
            return;
        }
        if(str_starts_with($data,'admins_search_page:')) {
            $parts=explode(':',$data,3);$serverId=(int)($parts[1]??0);$page=max(1,(int)($parts[2]??1));$state=Storage::getState($userId);
            if(($state['step']??'')!=='search_admin_results'||(int)($state['data']['server_id']??0)!==$serverId){tg_answer_callback($id,'Search expired. Start a new administrator search.',true);return;}
            tg_answer_callback($id);self::renderAdminSearchResults($chatId,$messageId,$serverId,(string)($state['data']['query']??''),$page);return;
        }

        if (str_starts_with($data, 'queue_back:')) {
            $serverId = (int)substr($data, strlen('queue_back:'));
            if(!NotificationOutbox::detachLoadingMessage($chatId,$messageId)){tg_answer_callback($id,'Please try again shortly.',true);return;}
            Storage::clearState($userId);
            tg_answer_callback($id);
            self::renderServerMenu($chatId, $messageId, $serverId);
            return;
        }
        if(str_starts_with($data,'stats_back:')) {
            $serverId=(int)substr($data,strlen('stats_back:'));
            Storage::clearState($userId);
            tg_answer_callback($id);
            MessageTracker::cleanup($chatId,$messageId>0?[$messageId]:[]);
            self::renderServerMenu($chatId,$messageId,$serverId);
            return;
        }

        if (str_starts_with($data, 'job:') || str_starts_with($data, 'job_cancel:')) {
            [$action, $jobId] = explode(':', $data, 2);
            $job = BatchQueue::get($jobId);
            if (!$job || (int)$job['user_id'] !== (int)$userId || (string)$job['chat_id'] !== (string)$chatId) {
                tg_answer_callback($id, 'Job not found.', true);
                return;
            }
            if ($action === 'job_cancel') {
                try { BatchQueue::cancel($jobId); } catch (RuntimeException $e) { tg_answer_callback($id, $e->getMessage(), true); return; }
                $job = BatchQueue::get($jobId) ?? $job;
            } else {
                $statusText = BatchQueue::alertText($job);
                tg_answer_callback($id, $statusText, true);
                return;
            }
            tg_answer_callback($id, $action === 'job_cancel' ? 'Cancellation requested. Completed work is retained.' : null);
            return;
        }

        if (empty($data) || $data === 'noop') {
            tg_answer_callback($id);
            return;
        }

        if (str_starts_with($data, 'decline:')) {
            Storage::clearState($userId);
            tg_answer_callback($id);
            $back = substr($data, strlen('decline:'));
            if (str_starts_with($back, 'act_confirm:')) {
                $parts = explode(':', $back);
                $back = "usr:{$parts[2]}:{$parts[3]}";
            }
            tg_edit_message($chatId, $messageId, "❌ Failed", Keyboards::cancel($back));
            return;
        }
        if (str_starts_with($data, 'srv_type:')) {
            $state = Storage::getState($userId);
            $type = substr($data, strlen('srv_type:'));
            if (($state['step'] ?? '') !== 'add_server_type' || !in_array($type, ['marzban', 'marzneshin'], true)) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            $state['data']['type'] = $type;
            Storage::setState($userId, 'add_server_credentials', $state['data']);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, Formatter::credentialsPrompt(), Keyboards::cancel());
            return;
        }

        // Cancel / Home
        if ($data === 'cancel' || $data === 'home') {
            Storage::clearState($userId);
            tg_answer_callback($id);
            self::renderHome($chatId, $messageId);
            return;
        }

        // Server Menu: srv:<id>
        if (str_starts_with($data, 'srv:')) {
            $serverId = (int)substr($data, strlen('srv:'));
            Storage::clearState($userId);
            tg_answer_callback($id);
            self::renderServerMenu($chatId, $messageId, $serverId);
            return;
        }

        // Server Settings: srv_cfg:<id>
        if (str_starts_with($data, 'srv_cfg:')) {
            $serverId = (int)substr($data, strlen('srv_cfg:'));
            Storage::clearState($userId);
            tg_answer_callback($id);
            self::renderServerSettings($chatId, $messageId, $serverId);
            return;
        }

        // Server Settings Toggles - each requires a confirmation step first
        if (str_starts_with($data, 'tgl_srv_mon_ask:')) {
            $serverId = (int)substr($data, strlen('tgl_srv_mon_ask:'));
            $server=Storage::getServer($serverId);if(!$server){tg_answer_callback($id,'Not found.',true);return;}
            if(!PanelManager::isSudo($server)){tg_answer_callback($id,'Node monitoring requires sudo panel access.',true);return;}
            Storage::setState($userId,'server_toggle_confirm',['server_id'=>$serverId,'action'=>'mon']);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, "Are your sure?", Keyboards::confirm("tgl_srv_mon:{$serverId}", "srv_cfg:{$serverId}"));
            return;
        }
        if (str_starts_with($data, 'tgl_srv_res_ask:')) {
            $serverId = (int)substr($data, strlen('tgl_srv_res_ask:'));
            $server=Storage::getServer($serverId);if(!$server){tg_answer_callback($id,'Not found.',true);return;}
            if(!PanelManager::isSudo($server)){tg_answer_callback($id,'Node restart requires sudo panel access.',true);return;}
            Storage::setState($userId,'server_toggle_confirm',['server_id'=>$serverId,'action'=>'res']);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, "Are your sure?", Keyboards::confirm("tgl_srv_res:{$serverId}", "srv_cfg:{$serverId}"));
            return;
        }
        if (str_starts_with($data, 'tgl_srv_exp_ask:')) {
            $serverId = (int)substr($data, strlen('tgl_srv_exp_ask:'));
            if(!Storage::getServer($serverId)){tg_answer_callback($id,'Not found.',true);return;}
            Storage::setState($userId,'server_toggle_confirm',['server_id'=>$serverId,'action'=>'exp']);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, "Are your sure?", Keyboards::confirm("tgl_srv_exp:{$serverId}", "srv_cfg:{$serverId}"));
            return;
        }
        if (str_starts_with($data, 'tgl_srv_mon:')) {
            $serverId = (int)substr($data, strlen('tgl_srv_mon:'));
            if(!self::consumeConfirmation($userId,'server_toggle_confirm',['server_id'=>$serverId,'action'=>'mon'])){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            $server = Storage::getServer($serverId);
            if($server&&!PanelManager::isSudo($server)){tg_answer_callback($id,'Node monitoring requires sudo panel access.',true);return;}
            if ($server) {
                $server['node_monitoring'] = empty($server['node_monitoring']) ? 1 : 0;
                Storage::saveServer($server);
            }
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, $server ? "✅ Success." : "❌ Not Found.", Keyboards::cancel("srv:{$serverId}"));
            return;
        }
        if (str_starts_with($data, 'tgl_srv_res:')) {
            $serverId = (int)substr($data, strlen('tgl_srv_res:'));
            if(!self::consumeConfirmation($userId,'server_toggle_confirm',['server_id'=>$serverId,'action'=>'res'])){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            $server = Storage::getServer($serverId);
            if($server&&!PanelManager::isSudo($server)){tg_answer_callback($id,'Node restart requires sudo panel access.',true);return;}
            if ($server) {
                $server['node_restart'] = empty($server['node_restart']) ? 1 : 0;
                Storage::saveServer($server);
            }
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, $server ? "✅ Success." : "❌ Not Found.", Keyboards::cancel("srv:{$serverId}"));
            return;
        }
        if (str_starts_with($data, 'tgl_srv_exp:')) {
            $serverId = (int)substr($data, strlen('tgl_srv_exp:'));
            if(!self::consumeConfirmation($userId,'server_toggle_confirm',['server_id'=>$serverId,'action'=>'exp'])){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            $server = Storage::getServer($serverId);
            if ($server) {
                $server['expired_stats'] = empty($server['expired_stats']) ? 1 : 0;
                Storage::saveServer($server);
            }
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, $server ? "✅ Success." : "❌ Not Found.", Keyboards::cancel("srv:{$serverId}"));
            return;
        }
        if (str_starts_with($data, 'srv_edit_remark:')) {
            $serverId = (int)substr($data, strlen('srv_edit_remark:'));
            if(!Storage::getServer($serverId)){tg_answer_callback($id,'❌ Not Found.',true);return;}
            Storage::setState($userId, 'srv_edit_remark', ['server_id' => $serverId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Enter remark: [a-z]",
                Keyboards::cancel("srv:{$serverId}")
            );
            return;
        }
        if (str_starts_with($data, 'srv_edit_creds:')) {
            $serverId = (int)substr($data, strlen('srv_edit_creds:'));
            if(!Storage::getServer($serverId)){tg_answer_callback($id,'❌ Not Found.',true);return;}
            Storage::setState($userId, 'srv_edit_creds', ['server_id' => $serverId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                Formatter::credentialsPrompt(),
                Keyboards::cancel("srv:{$serverId}")
            );
            return;
        }
        if (str_starts_with($data, 'del_srv_ask:')) {
            $serverId = (int)substr($data, strlen('del_srv_ask:'));
            if(!Storage::getServer($serverId)){tg_answer_callback($id,'❌ Not Found.',true);return;}
            Storage::setState($userId,'server_delete_confirm',['server_id'=>$serverId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Are your sure?",
                Keyboards::confirm("del_srv_ok:{$serverId}", "srv_cfg:{$serverId}")
            );
            return;
        }
        if (str_starts_with($data, 'del_srv_ok:')) {
            $serverId = (int)substr($data, strlen('del_srv_ok:'));
            $state=Storage::getState($userId);
            if(($state['step'] ?? '')!=='server_delete_confirm' || (int)($state['data']['server_id'] ?? 0)!==$serverId){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            Storage::clearState($userId);
             $ok = Storage::deleteServer($serverId);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, $ok ? "✅ Success." : "❌ Failed", Keyboards::cancel($ok ? 'home' : "srv:{$serverId}"));
            return;
        }

        // Users List: users:<id>:<page>:<filter>
        if (str_starts_with($data, 'users:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $page = max(1, (int)($parts[2] ?? 1));
            $filter = $parts[3] ?? 'all';
            if(!in_array($filter,['all','active','limited','expired'],true)){tg_answer_callback($id,'Invalid user filter.',true);return;}
            self::renderUsersList($chatId, $messageId, $serverId, $page, $filter, $id);
            return;
        }

        // View single user; current buttons carry their complete Back callback.
        if (str_starts_with($data, 'usr:')) {
            $parts = explode(':', $data, 5);
            $serverId = (int)($parts[1] ?? 0);
            $username = rawurldecode($parts[2] ?? '');
            $back=($parts[3]??'')==='back'?rawurldecode($parts[4]??''):null;
            // Accept callbacks generated before Back context was embedded.
            if($back===null){$originPage=max(1,(int)($parts[3]??1));$originFilter=$parts[4]??'';$back=in_array($originFilter,['all','active','limited','expired'],true)?"users:{$serverId}:{$originPage}:{$originFilter}":null;}
            if($serverId<1 || $username===''){tg_answer_callback($id,'Invalid user.',true);return;}
            Storage::clearState($userId);
            tg_answer_callback($id);
            self::renderUserCard($chatId, $messageId, $serverId, $username,$back);
            return;
        }

        // User actions: act:<id>:<username>:<action>
        if (str_starts_with($data, 'act:')) {
            $parts = explode(':', $data, 4);
            $serverId = (int)($parts[1] ?? 0);
            $username = $parts[2] ?? '';
            $action = $parts[3] ?? '';
            if(!in_array($action,['tgl_ask','chg','dl','dt','cfg','nt','own','rst_ask','rvk_ask','qr','del'],true)){tg_answer_callback($id,'Unknown user action.',true);return;}
            self::handleUserAction($id, $chatId, $messageId, $userId, $serverId, $username, $action);
            return;
        }

        // Delete user confirmed: del_ok:<id>:<username>
        if (str_starts_with($data, 'del_ok:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $username = $parts[2] ?? '';
            $state=Storage::getState($userId);
            if(($state['step'] ?? '')!=='user_confirm' || ($state['data']['action'] ?? '')!=='delete' || (int)($state['data']['server_id'] ?? 0)!==$serverId || ($state['data']['username'] ?? '')!==$username){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            Storage::clearState($userId);
            self::executeUserDelete($id, $chatId, $messageId, $userId, $serverId, $username);
            return;
        }

        // Set owner admin: set_own:<encoded username>:<id>:<admin>
        if (str_starts_with($data, 'set_own:')) {
            $parts = explode(':', $data, 4);
            $target = rawurldecode($parts[1] ?? '');
            $serverId = (int)($parts[2] ?? 0);
            $admin = $parts[3] ?? '';
            $state = Storage::getState($userId);
            $username = $state['data']['username'] ?? '';
            $server = Storage::getServer($serverId);
            if (
                !$server
                || ($state['step'] ?? '') !== 'user_mod_owner'
                || (int)($state['data']['server_id'] ?? 0) !== $serverId
                || $username === ''
                || $username !== $target || !self::validAdmin($server,$admin,false)
            ) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            Storage::clearState($userId);
            self::runUserMutation($id,$chatId,$messageId,$userId,$server,['username'=>$username,'operation'=>'owner','owner'=>$admin]);
            return;
        }

        // Recharge template select: chg_tmpl:<id>:<tmpl_id>
        if (str_starts_with($data, 'chg_tmpl:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $tmplId = (int)($parts[2] ?? 0);
            $tmpl = Storage::getTemplate($tmplId);
            $state = Storage::getState($userId);
            if (
                $tmpl
                && (!isset($tmpl['is_active']) || !empty($tmpl['is_active']))
                && ($state['step'] ?? '') === 'user_mod_charge'
                && (int)($state['data']['server_id'] ?? 0) === $serverId
                && !empty($state['data']['username'])
            ) {
                $state['data']['data_limit'] = (float)$tmpl['data_limit'];
                $state['data']['date_limit'] = (int)$tmpl['date_limit'];
                $state['data']['date_type'] = $tmpl['date_type'] ?? (($tmpl['date_limit'] > 0) ? 'fixed' : 'unlimited');
                Storage::setState($userId, 'charge_confirm_reset', $state['data']);
                tg_answer_callback($id);
                $dlText = ($tmpl['data_limit'] > 0) ? "{$tmpl['data_limit']}GB" : "Unlimited";
                $dtText = ($tmpl['date_limit'] > 0) ? "{$tmpl['date_limit']} days" : "Unlimited";
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Are your sure?",
                    Keyboards::chargeConfirmOptions($serverId, $state['data']['username'], (float)$tmpl['data_limit'], (int)$tmpl['date_limit'])
                );
            } else {
                tg_answer_callback($id, "❌ Not Found.", true);
            }
            return;
        }

        // Execute recharge option: chg_rst_opt:<id>:<mode: normal|reset|additive>
        if (str_starts_with($data, 'chg_rst_opt:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $mode = $parts[2] ?? 'normal';
            $resetUsage = ($mode === 'reset');
            $additive = ($mode === 'additive');
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);
            if (
                $server
                && ($state['step'] ?? '') === 'charge_confirm_reset'
                && (int)($state['data']['server_id'] ?? 0) === $serverId
                && !empty($state['data']['username'])
                && in_array($mode, ['normal', 'reset', 'additive'], true)
            ) {
                $username = $state['data']['username'];
                $dataLimit = (float)($state['data']['data_limit'] ?? 0);
                $dateLimit = (int)($state['data']['date_limit'] ?? 0);
                $dateType = $state['data']['date_type'] ?? 'fixed';
                $job = BatchQueue::enqueueInline('recharge', $server, [
                    'username' => $username, 'data_limit' => $dataLimit, 'date_limit' => $dateLimit,
                    'reset' => $resetUsage, 'additive' => $additive, 'date_type' => $dateType,
                    'message_id' => $messageId,
                ], $chatId, $userId, 'callback:' . $id);
                Storage::clearState($userId);
                tg_answer_callback($id, 'Updating user...');
                tg_edit_message($chatId, $messageId, 'Loading...');
                $attempt = BatchQueue::executeInline($job, function (array &$running) use ($server) { return BatchQueue::recharge($running, $server); });
                if ($attempt['state'] === 'queued') {
                    tg_edit_message($chatId, $messageId, BatchQueue::fallbackMessage($attempt['job']), BatchQueue::keyboard($attempt['job']));
                    return;
                }
                if ($attempt['state'] !== 'completed') {
                    tg_edit_message($chatId, $messageId, BatchQueue::describe($attempt['job']), BatchQueue::keyboard($attempt['job']));
                    return;
                }
                // Final delivery is committed with the job result.
            } else {
                tg_answer_callback($id, "❌ Not Found.", true);
            }
            return;
        }

        // User action confirmation prompt: act_confirm:<action>:<id>:<username>:<yes|no>
        if (str_starts_with($data, 'act_confirm:')) {
            $parts = explode(':', $data, 5);
            $action = $parts[1] ?? '';
            $serverId = (int)($parts[2] ?? 0);
            $username = $parts[3] ?? '';
            $confirm = ($parts[4] ?? '') === 'yes';
            $server = Storage::getServer($serverId);
            $state=Storage::getState($userId);

            if (!$server || ($state['step'] ?? '')!=='user_confirm' || ($state['data']['action'] ?? '')!==$action || (int)($state['data']['server_id'] ?? 0)!==$serverId || ($state['data']['username'] ?? '')!==$username) {
                tg_answer_callback($id, "Server not found.", true);
                return;
            }

            if (!$confirm) {
                Storage::clearState($userId);
                tg_answer_callback($id, "Cancelled.");
                self::renderUserCard($chatId, $messageId, $serverId, $username);
                return;
            }
            Storage::clearState($userId);

            $ok = false;
            if ($action === 'tgl') {
                $user = PanelManager::getUser($server, $username,true);
                if(!$user){tg_answer_callback($id,'❌ Not Found.',true);return;}
                self::runUserMutation($id,$chatId,$messageId,$userId,$server,['username'=>$username,'operation'=>'status','active'=>!$user['is_active']]); return;
            } elseif ($action === 'rst') {
                $job=BatchQueue::enqueueInline('reset',$server,['username'=>$username,'message_id'=>$messageId],$chatId,$userId,'callback:'.$id);
                tg_answer_callback($id,'Resetting usage...');
                tg_edit_message($chatId,$messageId,'Loading...');
                $attempt=BatchQueue::executeInline($job,fn(array &$running)=>BatchQueue::reset($running,$server));
                if ($attempt['state']==='queued') tg_edit_message($chatId,$messageId,BatchQueue::fallbackMessage($attempt['job']),BatchQueue::keyboard($attempt['job']));
                elseif ($attempt['state']!=='completed') tg_edit_message($chatId,$messageId,BatchQueue::describe($attempt['job']),BatchQueue::keyboard($attempt['job']));
                return;
            }
            elseif ($action === 'rvk') {
                $job = BatchQueue::enqueueInline('revoke_qr', $server, [
                    'username' => $username, 'message_id' => $messageId,
                ], $chatId, $userId, 'callback:' . $id);
                tg_answer_callback($id, 'Updating subscription...');
                tg_edit_message($chatId, $messageId, 'Loading...');
                $attempt = BatchQueue::executeInline($job, function (array &$running) use ($server) {
                    $updated = BatchQueue::revoke($running, $server);
                    if (!$updated) throw new RuntimeException('Subscription revoke failed');
                    return $updated;
                });
                if ($attempt['state'] === 'queued') {
                    tg_edit_message($chatId, $messageId, BatchQueue::fallbackMessage($attempt['job']), BatchQueue::keyboard($attempt['job']));
                    return;
                }
                if ($attempt['state'] !== 'completed') {
                    tg_edit_message($chatId, $messageId, BatchQueue::describe($attempt['job']), BatchQueue::keyboard($attempt['job']));
                    return;
                }
                return; // Durable result delivery handles QR and the replacement message.
            }
            if ($action !== 'rvk') tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, $ok ? "✅ Success." : "❌ Failed", Keyboards::cancel("usr:{$serverId}:{$username}"));
            return;
        }

        // Date type selection for user modify: dt_type:<id>:<username>:<type>
        if (str_starts_with($data, 'dt_type:')) {
            $parts = explode(':', $data, 4);
            $serverId = (int)($parts[1] ?? 0);
            $username = $parts[2] ?? '';
            $type = $parts[3] ?? 'fixed';
            $server = Storage::getServer($serverId);
            $state=Storage::getState($userId);
            if (!$server || ($state['step'] ?? '')!=='user_mod_datetype' || (int)($state['data']['server_id'] ?? 0)!==$serverId || ($state['data']['username'] ?? '')!==$username || !in_array($type,['fixed','onhold','unlimited'],true)) { tg_answer_callback($id, "Session expired, please retry.", true); return; }

            if ($type === 'unlimited') {
                tg_answer_callback($id);
                Storage::clearState($userId);
                self::runUserMutation($id,$chatId,$messageId,$userId,$server,['username'=>$username,'operation'=>'date','days'=>0,'date_type'=>'unlimited']);
            } elseif ($type === 'onhold') {
                Storage::setState($userId, 'user_mod_datelimit_onhold', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Enter DateLimit: [0-9]",
                    Keyboards::cancel("usr:{$serverId}:{$username}")
                );
            } else {
                Storage::setState($userId, 'user_mod_datelimit', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Enter DateLimit: [0-9]",
                    Keyboards::cancel("usr:{$serverId}:{$username}")
                );
            }
            return;
        }

        if (str_starts_with($data, 'cfg_pick:')) {
            $parts = explode(':', $data, 5);
            $username = rawurldecode($parts[1] ?? '');
            $operation = $parts[2] ?? '';
            $serverId = (int)($parts[3] ?? 0);
            $state = Storage::getState($userId);
            if (($state['step'] ?? '') !== 'user_mod_configs' || (int)($state['data']['server_id'] ?? 0) !== $serverId || ($state['data']['username'] ?? '') !== $username || !in_array($operation, ['all','none','tgl'], true)) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            $server = Storage::getServer($serverId);
            if (!$server) { tg_answer_callback($id, "❌ Not Found.", true); return; }
            $services = PanelManager::getServices($server);
            $currentIds = $state['data']['current_services'];
            $valid = array_map('strval', array_column($services, 'id'));
            $selected = rawurldecode($parts[4] ?? '');
            if ($operation === 'tgl' && !in_array($selected, $valid, true)) {
                tg_answer_callback($id, 'Config no longer available. Reopen the editor.', true);
                return;
            }
            if ($operation === 'all') $currentIds = array_column($services, 'id');
            elseif ($operation === 'none') $currentIds = [];
            elseif ($operation === 'tgl' && in_array($selected, $valid, true)) {
                if (in_array($selected, array_map('strval', $currentIds), true)) {
                    $currentIds = array_values(array_filter($currentIds, fn($v) => (string)$v !== $selected));
                } else $currentIds[] = $selected;
            }
            $state['data']['current_services'] = $currentIds;
            Storage::setState($userId, 'user_mod_configs', $state['data']);
            $username = $state['data']['username'];
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, "Select Configs:", Keyboards::configSelector(
                $serverId, $services, $currentIds, 'cfg_pick:' . rawurlencode($username), "cfg_save:{$serverId}:{$username}", "usr:{$serverId}:{$username}",(int)($state['data']['selector_page'] ?? 1)
            ));
            return;
        }

        if (str_starts_with($data, 'cfg_save:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $username = $parts[2] ?? '';
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);
            if (
                !$server
                || ($state['step'] ?? '') !== 'user_mod_configs'
                || (int)($state['data']['server_id'] ?? 0) !== $serverId
                || ($state['data']['username'] ?? '') !== $username
            ) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            $newIds = $state['data']['current_services'] ?? [];
            if (empty($newIds)) {
                tg_answer_callback($id, "❌ Not Found Any Config.", true);
                return;
            }
            Storage::clearState($userId);
            self::runUserMutation($id,$chatId,$messageId,$userId,$server,['username'=>$username,'operation'=>'config','ids'=>$newIds]);
            return;
        }

        // Server Actions Menu: act_menu:<id>
        if (str_starts_with($data, 'act_menu:')) {
            $serverId = (int)substr($data, strlen('act_menu:'));
            $server = Storage::getServer($serverId);
            if(!$server){tg_answer_callback($id,'❌ Not Found.',true);return;}
            if(!PanelManager::isSudo($server)){tg_answer_callback($id,'Server-wide actions require sudo panel access.',true);return;}
            Storage::clearState($userId);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Select items",
                Keyboards::actionsMenu($serverId, $server['type'] ?? 'marzban')
            );
            return;
        }

        // Server Action Item selected: act_item:<id>:<action>
        if (str_starts_with($data, 'act_item:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $action = $parts[2] ?? '';
            if(!in_array($action,['del_exp','del_lim','del_all','act_adm','dis_adm','xfer_adm','add_cfg','del_cfg'],true)){tg_answer_callback($id,'Unknown batch action.',true);return;}
            $server = Storage::getServer($serverId);
            if (!$server) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            $admins = PanelManager::getAdmins($server);
            if (empty($admins)) {
                tg_answer_callback($id, "❌ Not Found.", true);
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel("act_menu:{$serverId}"));
                return;
            }
            Storage::setState($userId,'bulk_action_select',['server_id'=>$serverId,'action'=>$action]);

            if ($action === 'del_exp' || $action === 'del_lim') {
                tg_answer_callback($id);
                $title = ($action === 'del_exp') ? "Delete Expired Users" : "Delete Limited Users";
                $kb = Keyboards::adminsSelector($serverId, $admins, "confirm_act:{$action}", true);
                tg_edit_message($chatId, $messageId, "Select admin:", $kb);
                return;
            }

            if ($action === 'del_all') {
                tg_answer_callback($id);
                // Unlike Delete Expired / Delete Limited, this action never offers an
                // "ALL admins" wildcard - a specific admin must always be chosen.
                $kb = Keyboards::adminsSelector($serverId, $admins, "confirm_act:del_all", false);
                tg_edit_message($chatId, $messageId, "Select admin:", $kb);
                return;
            }

            if ($action === 'act_adm' || $action === 'dis_adm') {
                tg_answer_callback($id);
                $title = ($action === 'act_adm') ? "Activate Admin Users" : "Disable Admin Users";
                $kb = Keyboards::adminsSelector($serverId, $admins, "confirm_act:{$action}", false);
                tg_edit_message($chatId, $messageId, "Select admin:", $kb);
                return;
            }

            if ($action === 'xfer_adm') {
                tg_answer_callback($id);
                $kb = Keyboards::adminsSelector($serverId, $admins, "xfer_from", false);
                tg_edit_message($chatId, $messageId, "Select from admin:", $kb);
                return;
            }

            if ($action === 'add_cfg' || $action === 'del_cfg') {
                tg_answer_callback($id);
                $title = ($action === 'add_cfg') ? "Add Config to Users" : "Remove Config from Users";
                $kb = Keyboards::adminsSelector($serverId, $admins, "cfg_adm:{$action}", true);
                tg_edit_message($chatId, $messageId, "Select admin:", $kb);
                return;
            }
        }

        // Confirm before executing a destructive/bulk admin action: confirm_act:<action>:<id>:<admin>
        if (str_starts_with($data, 'confirm_act:')) {
            $parts = explode(':', $data, 4);
            $action = $parts[1] ?? '';
            $serverId = (int)($parts[2] ?? 0);
            $admin = $parts[3] ?? 'ALL';
            $server=Storage::getServer($serverId);
            $state=Storage::getState($userId);
            $allowAll=in_array($action,['del_exp','del_lim'],true);
            if(!$server || !in_array($action,['del_exp','del_lim','del_all','act_adm','dis_adm'],true) || ($state['step']??'')!=='bulk_action_select' || ($state['data']['action']??'')!==$action || (int)($state['data']['server_id']??0)!==$serverId || !self::validAdmin($server,$admin,$allowAll)){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            Storage::setState($userId,'bulk_confirm',['server_id'=>$serverId,'action'=>$action,'admin'=>$admin]);
            tg_answer_callback($id);
            $kb = Keyboards::confirm("exec_act:{$action}:{$serverId}:{$admin}", "act_menu:{$serverId}");
            tg_edit_message($chatId, $messageId, "Are your sure?", $kb);
            return;
        }

        // Config bulk action target admin selected: cfg_adm:<action>:<id>:<admin>
        if (str_starts_with($data, 'cfg_adm:')) {
            $parts = explode(':', $data, 4);
            $action = $parts[1] ?? '';
            $serverId = (int)($parts[2] ?? 0);
            $admin = $parts[3] ?? 'ALL';
            $server = Storage::getServer($serverId);
            $state=Storage::getState($userId);
            if (!$server || !in_array($action,['add_cfg','del_cfg'],true) || ($state['step']??'')!=='bulk_action_select' || ($state['data']['action']??'')!==$action || (int)($state['data']['server_id']??0)!==$serverId || !self::validAdmin($server,$admin,true)) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            Storage::setState($userId, 'cfg_action_pick', ['server_id' => $serverId, 'action' => $action, 'admin' => $admin]);
            $services = PanelManager::getServices($server);
            tg_answer_callback($id);
            if (empty($services)) {
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel("srv:{$serverId}"));
                return;
            }
            $actionLabel = ($action === 'add_cfg') ? 'Add to Users' : 'Remove from Users';
            tg_edit_message($chatId, $messageId, "Select items", Keyboards::bulkServices($serverId,$services));
            return;
        }

        // Config bulk action executed: bulk_cfg:<id>:<service_id>
        if (str_starts_with($data, 'bulk_cfg:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $serviceId = rawurldecode($parts[2] ?? '');
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);
            if (
                !$server
                || ($state['step'] ?? '') !== 'cfg_action_pick'
                || (int)($state['data']['server_id'] ?? 0) !== $serverId
                || !in_array($state['data']['action'] ?? '', ['add_cfg', 'del_cfg'], true)
            ) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            $action = $state['data']['action'] ?? '';
            $admin = $state['data']['admin'] ?? 'ALL';
            $validServices=array_map('strval',array_column(PanelManager::getServices($server),'id'));
            if(!in_array($serviceId,$validServices,true)){tg_answer_callback($id,'❌ Not Found.',true);return;}
            self::queueBatch('config', $server, ['admin'=>$admin, 'service_id'=>$serviceId, 'add'=>$action === 'add_cfg'], $chatId, $messageId, $userId, $id);
            return;
        }

        // Execute bulk action on selected admin: exec_act:<action>:<id>:<admin>
        if (str_starts_with($data, 'exec_act:')) {
            $parts = explode(':', $data, 4);
            $action = $parts[1] ?? '';
            $serverId = (int)($parts[2] ?? 0);
            $admin = $parts[3] ?? 'ALL';
            $server = Storage::getServer($serverId);
            $state=Storage::getState($userId);
            if (!$server || ($state['step']??'')!=='bulk_confirm' || ($state['data']['action']??'')!==$action || ($state['data']['admin']??'')!==$admin || (int)($state['data']['server_id']??0)!==$serverId) { tg_answer_callback($id, "Session expired, please retry.", true); return; }
            Storage::clearState($userId);

            if (in_array($action, ['del_exp', 'del_lim', 'del_all'], true)) {
                self::queueBatch('delete', $server, ['admin'=>$admin, 'status'=>match ($action) { 'del_exp'=>'expired', 'del_lim'=>'limited', default=>null }], $chatId, $messageId, $userId, $id);
            } elseif (in_array($action, ['act_adm', 'dis_adm'], true)) {
                self::queueBatch('admin_status', $server, ['admin'=>$admin, 'active'=>$action === 'act_adm'], $chatId, $messageId, $userId, $id);
            } else tg_answer_callback($id, 'Unknown batch action.', true);
            return;
        }

        // Transfer step 1: xfer_from:<id>:<fromAdmin>
        if (str_starts_with($data, 'xfer_from:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $fromAdmin = $parts[2] ?? '';
            $server = Storage::getServer($serverId);
            $state=Storage::getState($userId);
            if (!$server || ($state['step']??'')!=='bulk_action_select' || ($state['data']['action']??'')!=='xfer_adm' || (int)($state['data']['server_id']??0)!==$serverId || !self::validAdmin($server,$fromAdmin,false)) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            // The destination-admin list is intentionally NOT filtered to exclude
            // the source admin, matching the original bot (which allows a same-
            // admin "transfer" as a harmless no-op rather than making the flow
            // unreachable on a single-admin server).
            $admins = PanelManager::getAdmins($server);
            if (empty($admins)) {
                tg_answer_callback($id, "❌ Not Found.", true);
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel("act_menu:{$serverId}"));
                return;
            }
            Storage::setState($userId, 'xfer_target', ['server_id' => $serverId, 'from_admin' => $fromAdmin]);

            tg_answer_callback($id);
            $kb = Keyboards::adminsSelector($serverId, $admins, "xfer_to", false);
            tg_edit_message(
                $chatId,
                $messageId,
                "Select to admin:",
                $kb
            );
            return;
        }

        // Transfer step 2: xfer_to:<id>:<toAdmin>
        if (str_starts_with($data, 'xfer_to:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $toAdmin = $parts[2] ?? '';
            $state = Storage::getState($userId);
            $server=Storage::getServer($serverId);
            if (!$server || ($state['step'] ?? '') !== 'xfer_target' || (int)($state['data']['server_id'] ?? 0) !== $serverId || !self::validAdmin($server,$toAdmin,false)) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            $fromAdmin = $state['data']['from_admin'] ?? '';
            $state['data']['to_admin'] = $toAdmin;
            Storage::setState($userId, 'xfer_confirm', $state['data']);

            tg_answer_callback($id);
            $kb = Keyboards::confirm("xfer_ok:{$serverId}", (string)($state['data']['return_to']??"act_menu:{$serverId}"));
            tg_edit_message(
                $chatId,
                $messageId,
                "Are your sure?",
                $kb
            );
            return;
        }

        // Execute Transfer: xfer_ok:<id>
        if (str_starts_with($data, 'xfer_ok:')) {
            $serverId = (int)substr($data, strlen('xfer_ok:'));
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);
            if (!$server || ($state['step'] ?? '') !== 'xfer_confirm' || (int)($state['data']['server_id'] ?? 0) !== $serverId) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            $fromAdmin = $state['data']['from_admin'] ?? '';
            $toAdmin = $state['data']['to_admin'] ?? '';
            self::queueBatch('transfer', $server, ['admin'=>$fromAdmin, 'to_admin'=>$toAdmin], $chatId, $messageId, $userId, $id);
            return;
        }

        // Cached statistics view and explicit refresh.
        if (str_starts_with($data,'stats_cached:') || str_starts_with($data,'stats_refresh:') || str_starts_with($data,'stats:')) {
            $refresh=str_starts_with($data,'stats_refresh:');
            $navigation=str_starts_with($data,'stats_cached:');
            $prefix=$refresh?'stats_refresh:':(str_starts_with($data,'stats_cached:')?'stats_cached:':'stats:');
            $serverId=(int)substr($data,strlen($prefix));
            $server = Storage::getServer($serverId);
            if (!$server) {
                tg_answer_callback($id, "❌ Not Found.", true);
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel('home'));
                return;
            }
            if(!$refresh && self::renderCachedStats($id,$chatId,$messageId,$serverId,!$navigation)) return;
            MessageTracker::cleanup($chatId,$messageId>0?[$messageId]:[]);
            self::queueBatch('stats', $server, ['message_id' => $messageId], $chatId, $messageId, $userId, $id);
            return;
        }

        // Templates Menu: tmpls
        if ($data === 'tmpls' || str_starts_with($data,'tmpls:')) {
            Storage::clearState($userId);
            tg_answer_callback($id);
            self::renderTemplates($chatId,$messageId,max(1,(int)(explode(':',$data,2)[1] ?? 1)));
            return;
        }

        // View Template: tmpl_view:<id>
        if (str_starts_with($data, 'tmpl_view:')) {
            $parts=explode(':',$data,3);$tmplId=(int)($parts[1]??0);$page=max(1,(int)($parts[2]??1));
            $tmpl = Storage::getTemplate($tmplId);
            if (!$tmpl) {
                tg_answer_callback($id, "❌ Not Found.", true);
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel());
                return;
            }
            Storage::clearState($userId);
            tg_answer_callback($id);
            $isActive = !isset($tmpl['is_active']) || !empty($tmpl['is_active']);
            tg_edit_message($chatId, $messageId, Formatter::templateCard($tmpl), Keyboards::templateActions($tmplId, $isActive,$page));
            return;
        }

        // Confirm before toggling Template is_active: tmpl_tgl_ask:<id>
        if (str_starts_with($data, 'tmpl_tgl_ask:')) {
            $parts=explode(':',$data,3);$tmplId=(int)($parts[1]??0);$page=max(1,(int)($parts[2]??1));
            if(!Storage::getTemplate($tmplId)){tg_answer_callback($id,'Not found.',true);return;}
            Storage::setState($userId,'template_confirm',['template_id'=>$tmplId,'action'=>'toggle','page'=>$page]);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, "Are your sure?", Keyboards::confirm("tmpl_tgl_act:{$tmplId}", "tmpl_view:{$tmplId}:{$page}"));
            return;
        }

        // Toggle Template is_active: tmpl_tgl_act:<id>
        if (str_starts_with($data, 'tmpl_tgl_act:')) {
            $tmplId = (int)substr($data, strlen('tmpl_tgl_act:'));
            $page=max(1,(int)(Storage::getState($userId)['data']['page']??1));
            if(!self::consumeConfirmation($userId,'template_confirm',['template_id'=>$tmplId,'action'=>'toggle'])){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            $tmpl = Storage::getTemplate($tmplId);
            if (!$tmpl) {
                tg_answer_callback($id, "❌ Not Found.", true);
                tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel());
                return;
            }
            $tmpl['is_active'] = ($tmpl['is_active'] ?? true) ? 0 : 1;
            Storage::saveTemplate($tmpl);
            tg_answer_callback($id, "✅ Success.");
            tg_edit_message($chatId, $messageId, "✅ Success.", Keyboards::cancel('tmpls:'.$page));
            return;
        }

        // Delete Template prompt: tmpl_del_ask:<id>
        if (str_starts_with($data, 'tmpl_del_ask:')) {
            $parts=explode(':',$data,3);$tmplId=(int)($parts[1]??0);$page=max(1,(int)($parts[2]??1));
            if(!Storage::getTemplate($tmplId)){tg_answer_callback($id,'Not found.',true);return;}
            Storage::setState($userId,'template_confirm',['template_id'=>$tmplId,'action'=>'delete','page'=>$page]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Are your sure?",
                Keyboards::confirm("tmpl_del:{$tmplId}", "tmpl_view:{$tmplId}:{$page}")
            );
            return;
        }

        // Delete Template confirmed: tmpl_del:<id>
        if (str_starts_with($data, 'tmpl_del:')) {
            $tmplId = (int)substr($data, strlen('tmpl_del:'));
            $page=max(1,(int)(Storage::getState($userId)['data']['page']??1));
            if(!self::consumeConfirmation($userId,'template_confirm',['template_id'=>$tmplId,'action'=>'delete'])){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            $ok = Storage::deleteTemplate($tmplId);
            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, $ok ? "✅ Success." : "❌ Failed", Keyboards::cancel('tmpls:'.$page));
            return;
        }

        // Edit Template Remark: tmpl_edit_remark:<id>
        if (str_starts_with($data, 'tmpl_edit_remark:')) {
            $parts=explode(':',$data,3);$tmplId=(int)($parts[1]??0);$page=max(1,(int)($parts[2]??1));
            Storage::setState($userId, 'tmpl_edit_remark', ['tmpl_id' => $tmplId,'page'=>$page]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Enter remark: [a-z]",
                Keyboards::cancel("tmpl_view:{$tmplId}:{$page}")
            );
            return;
        }

        // Edit Template Data Limit: tmpl_edit_data:<id>
        if (str_starts_with($data, 'tmpl_edit_data:')) {
            $parts=explode(':',$data,3);$tmplId=(int)($parts[1]??0);$page=max(1,(int)($parts[2]??1));
            Storage::setState($userId, 'tmpl_edit_data', ['tmpl_id' => $tmplId,'page'=>$page]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Enter DataLimit: [0-9]\n0 for unlimited",
                Keyboards::cancel("tmpl_view:{$tmplId}:{$page}")
            );
            return;
        }

        // Edit Template Date Limit: tmpl_edit_date:<id> - shows the date-type selector first
        if (str_starts_with($data, 'tmpl_edit_date:')) {
            $parts=explode(':',$data,3);$tmplId=(int)($parts[1]??0);$page=max(1,(int)($parts[2]??1));
            if (!Storage::getTemplate($tmplId)) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            Storage::setState($userId, 'tmpl_edit_datetype', ['tmpl_id'=>$tmplId,'page'=>$page]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Select a Button",
                Keyboards::templateDateTypeSelector("tmpl_edit_dt:{$tmplId}", "tmpl_view:{$tmplId}:{$page}")
            );
            return;
        }

        // Template date-type chosen during ADD: tmpl_add_dt:<type>
        if (str_starts_with($data, 'tmpl_add_dt:')) {
            $type = substr($data, strlen('tmpl_add_dt:'));
            $state = Storage::getState($userId);
            if (($state['step'] ?? '') !== 'tmpl_add_datetype' || empty($state['data']['remark']) || !in_array($type, ['fixed','onhold','unlimited'], true)) {
                tg_answer_callback($id, "Session expired, please retry.", true);
                return;
            }
            $tmplData = $state['data'];
            $tmplData['date_type'] = $type;
            tg_answer_callback($id);
            if ($type === 'unlimited') {
                $tmplData['date_limit'] = 0;
                Storage::saveTemplate($tmplData);
                Storage::clearState($userId);
                tg_edit_message($chatId, $messageId, "✅ Success.", Keyboards::cancel());
            } else {
                Storage::setState($userId, 'tmpl_add_date', $tmplData);
                tg_edit_message($chatId, $messageId, "Enter DateLimit: [0-9]", Keyboards::cancel());
            }
            return;
        }

        // Template date-type chosen during EDIT: tmpl_edit_dt:<id>:<type>
        if (str_starts_with($data, 'tmpl_edit_dt:')) {
            $parts = explode(':', $data, 3);
            $tmplId = (int)($parts[1] ?? 0);
            $type = $parts[2] ?? 'fixed';
            $state = Storage::getState($userId);
            $page=max(1,(int)($state['data']['page']??1));
            if (($state['step'] ?? '') !== 'tmpl_edit_datetype' || (int)($state['data']['tmpl_id'] ?? 0) !== $tmplId || !in_array($type, ['fixed','onhold','unlimited'], true)) {
                tg_answer_callback($id, 'Session expired, please retry.', true);
                return;
            }
            $tmpl = Storage::getTemplate($tmplId);
            if (!$tmpl) {
                tg_answer_callback($id, "❌ Not Found.", true);
                return;
            }
            tg_answer_callback($id);
            if ($type === 'unlimited') {
                $tmpl['date_type'] = 'unlimited';
                $tmpl['date_limit'] = 0;
                Storage::saveTemplate($tmpl);
                Storage::clearState($userId);
                tg_edit_message($chatId, $messageId, "✅ Success.", Keyboards::cancel("tmpl_view:{$tmplId}:{$page}"));
            } else {
                Storage::setState($userId, 'tmpl_edit_date', ['tmpl_id' => $tmplId, 'date_type' => $type,'page'=>$page]);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Enter DateLimit: [0-9]",
                    Keyboards::cancel("tmpl_view:{$tmplId}:{$page}")
                );
            }
            return;
        }

        // Add Template: new_tmpl
        if ($data === 'new_tmpl') {
            Storage::setState($userId, 'tmpl_add_remark');
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Enter remark: [a-z]",
                Keyboards::cancel()
            );
            return;
        }

        // Search user: srch_usr:<id>
        if (str_starts_with($data, 'srch_usr:')) {
            $serverId = (int)substr($data, strlen('srch_usr:'));
            Storage::setState($userId, 'search_user', ['server_id' => $serverId]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Enter Username:",
                Keyboards::cancel("srv:{$serverId}")
            );
            return;
        }

        if(str_starts_with($data,'srch_adm:')) {
            $serverId=(int)substr($data,strlen('srch_adm:'));
            $server=Storage::getServer($serverId);if(!$server){tg_answer_callback($id,'Server not found.',true);return;}
            if(!PanelManager::isSudo($server)){tg_answer_callback($id,'Administrator management requires sudo panel access.',true);return;}
            Storage::setState($userId,'search_admin',['server_id'=>$serverId]);tg_answer_callback($id);
            tg_edit_message($chatId,$messageId,'Enter administrator username:',Keyboards::cancel("srv:{$serverId}"));return;
        }

        if(str_starts_with($data,'adm_view:')) {
            $parts=explode(':',$data,4);$serverId=(int)($parts[1]??0);$admin=rawurldecode($parts[2]??'');$page=max(1,(int)($parts[3]??1));$server=Storage::getServer($serverId);
            if(!$server){tg_answer_callback($id,'Server not found.',true);return;}
            if(!PanelManager::isSudo($server)){tg_answer_callback($id,'Administrator management requires sudo panel access.',true);return;}
            tg_answer_callback($id);self::renderAdminCard($chatId,$messageId,$server,$admin,$page);return;
        }

        if(str_starts_with($data,'adm_users:')) {
            $parts=explode(':',$data,6);$serverId=(int)($parts[1]??0);$admin=rawurldecode($parts[2]??'');$adminPage=max(1,(int)($parts[3]??1));$page=max(1,(int)($parts[4]??1));$server=Storage::getServer($serverId);
            $filter=$parts[5]??'all';if(!in_array($filter,['all','active','disabled','on_hold','limited','expired'],true))$filter='all';
            if(!$server||!PanelManager::isSudo($server)){tg_answer_callback($id,'Administrator management requires sudo panel access.',true);return;}
            if(!PanelManager::getAdmin($server,$admin)){tg_answer_callback($id,'Administrator not found.',true);return;}
            if($filter==='on_hold'&&strtolower((string)$server['type'])==='marzneshin'){tg_answer_callback($id,'This panel does not expose an On Hold user filter.',true);return;}
            $status=$filter==='all'?null:$filter;$limit=10;$users=PanelManager::getUsers($server,$page,$limit,null,$status,$admin,true);$total=PanelManager::getLastUsersTotal($server);
            if($total!==null&&$page>max(1,(int)ceil($total/$limit))){$page=max(1,(int)ceil($total/$limit));$users=PanelManager::getUsers($server,$page,$limit,null,$status,$admin,true);}
            $hasMore=$total!==null?$page*$limit<$total:count($users)===$limit;
            tg_answer_callback($id);$label=$filter==='all'?'users':str_replace('_',' ',$filter).' users';tg_edit_message($chatId,$messageId,$users?'<b>'.ucwords($label).' owned by '.Formatter::escape($admin).':</b>':'<b>No '.Formatter::escape($label).' found for this administrator.</b>',Keyboards::adminUsers($serverId,$admin,$users,$page,$hasMore,$adminPage,$filter,(string)$server['type']));return;
        }

        if(str_starts_with($data,'srch_adm_user:')) {
            $parts=explode(':',$data,4);$serverId=(int)($parts[1]??0);$admin=rawurldecode($parts[2]??'');$adminPage=max(1,(int)($parts[3]??1));$server=Storage::getServer($serverId);
            if(!$server||!PanelManager::isSudo($server)){tg_answer_callback($id,'Administrator management requires sudo panel access.',true);return;}
            if(!PanelManager::getAdmin($server,$admin)){tg_answer_callback($id,'Administrator not found.',true);return;}
            Storage::setState($userId,'search_admin_user',['server_id'=>$serverId,'admin'=>$admin,'admin_page'=>$adminPage]);tg_answer_callback($id);
            tg_edit_message($chatId,$messageId,'Enter a username to search within <b>'.Formatter::escape($admin).'</b>:',Keyboards::cancel("adm_view:{$serverId}:".rawurlencode($admin).":{$adminPage}"));return;
        }

        if(str_starts_with($data,'adm_create:')) {
            $parts=explode(':',$data,5);$serverId=(int)($parts[1]??0);$admin=rawurldecode($parts[2]??'');$server=Storage::getServer($serverId);
            if(!$server||!PanelManager::isSudo($server)){tg_answer_callback($id,'Administrator management requires sudo panel access.',true);return;}
            if(!PanelManager::getAdmin($server,$admin)){tg_answer_callback($id,'Administrator not found.',true);return;}
            self::renderUserCreatePrompt($chatId,$messageId,$serverId,$userId,$admin,$id);return;
        }

        if(str_starts_with($data,'adm_act:')) {
            $parts=explode(':',$data,6);$action=$parts[1]??'';$serverId=(int)($parts[2]??0);$admin=rawurldecode($parts[3]??'');$page=max(1,(int)($parts[4]??1));$server=Storage::getServer($serverId);
            if(!$server||!PanelManager::isSudo($server)){tg_answer_callback($id,'Administrator management requires sudo panel access.',true);return;}
            if(!in_array($action,['act_adm','dis_adm','del_all','xfer_adm','add_cfg','del_cfg'],true)||!self::validAdmin($server,$admin,false)){tg_answer_callback($id,'Administrator action is no longer valid.',true);return;}
            $back='adm_view:'.$serverId.':'.rawurlencode($admin).':'.$page;
            if($action==='xfer_adm'){
                Storage::setState($userId,'xfer_target',['server_id'=>$serverId,'from_admin'=>$admin,'return_to'=>$back]);
                tg_answer_callback($id);tg_edit_message($chatId,$messageId,'Select destination administrator:',Keyboards::adminsSelector($serverId,PanelManager::getAdmins($server),'xfer_to',false,$back));return;
            }
            if(in_array($action,['add_cfg','del_cfg'],true)){
                if(($server['type']??'marzban')!=='marzneshin'){tg_answer_callback($id,'Configuration actions are only available for Marzneshin.',true);return;}
                $services=PanelManager::getServices($server);if(!$services){tg_answer_callback($id,'No services found.',true);return;}
                Storage::setState($userId,'cfg_action_pick',['server_id'=>$serverId,'action'=>$action,'admin'=>$admin,'return_to'=>$back]);
                tg_answer_callback($id);tg_edit_message($chatId,$messageId,'Select service:',Keyboards::bulkServices($serverId,$services,1,$back));return;
            }
            Storage::setState($userId,'bulk_confirm',['server_id'=>$serverId,'action'=>$action,'admin'=>$admin]);
            tg_answer_callback($id);tg_edit_message($chatId,$messageId,'Are you sure?',Keyboards::confirm("exec_act:{$action}:{$serverId}:{$admin}",$back));return;
        }

        // Creation buttons are valid only for the current wizard and server.
        $creationSteps = [
            'new_usr_adm' => 'create_user_admin',
            'new_usr_json' => 'create_user_name',
            'rnd_usr' => 'create_user_name',
            'use_tmpl' => 'create_user_template',
            'use_tmpl_custom' => 'create_user_template',
            'usr_cfg' => 'create_user_configs',
            'usr_cfg_done' => 'create_user_configs',
            'crt_dt_type' => 'create_user_date_type',
        ];
        $creationParts = explode(':', $data);
        $creationPrefix = $creationParts[0];
        if (isset($creationSteps[$creationPrefix])) {
            $creationServer = (int)($creationParts[$creationPrefix === 'usr_cfg' ? 2 : 1] ?? 0);
            $creationState = Storage::getState($userId);
            if (($creationState['step'] ?? '') !== $creationSteps[$creationPrefix]
                || (int)($creationState['data']['server_id'] ?? 0) !== $creationServer
                || !Storage::getServer($creationServer)) {
                tg_answer_callback($id, "Session expired, please retry.", true);
                return;
            }
        }

        // Create user: new_usr:<id>
        if (str_starts_with($data, 'new_usr:')) {
            $serverId = (int)substr($data, strlen('new_usr:'));
            $server = Storage::getServer($serverId);
            if (!$server) {
                tg_answer_callback($id, "Server not found.", true);
                return;
            }
            if(!PanelManager::isSudo($server)){
                self::renderUserCreatePrompt($chatId,$messageId,$serverId,$userId,'',$id);
                return;
            }
            Storage::setState($userId, 'create_user_admin', ['server_id' => $serverId]);
            $admins = PanelManager::getAdmins($server);
            if (count($admins) > 0) {
                tg_answer_callback($id);
                $kb = Keyboards::adminsSelector($serverId, $admins, 'new_usr_adm', false, "srv:{$serverId}");
                tg_edit_message($chatId, $messageId, "Select admin:", $kb);
                return;
            }

            tg_answer_callback($id);
            tg_edit_message($chatId, $messageId, '❌ Not Found.', Keyboards::cancel("srv:{$serverId}"));
            return;
        }

        // Admin chosen for user create: new_usr_adm:<id>:<admin>
        if (str_starts_with($data, 'new_usr_adm:')) {
            $parts = explode(':', $data, 3);
            $serverId = (int)($parts[1] ?? 0);
            $admin = $parts[2] ?? '';
            $server=Storage::getServer($serverId);
            if(!$server || !self::validAdmin($server,$admin,false)){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            self::renderUserCreatePrompt($chatId, $messageId, $serverId, $userId, $admin, $id);
            return;
        }

        // JSON import button clicked: new_usr_json:<id>
        if (str_starts_with($data, 'new_usr_json:')) {
            $serverId = (int)substr($data, strlen('new_usr_json:'));
            $state = Storage::getState($userId) ?: ['data' => []];
            $admin = $state['data']['admin'] ?? '';
            Storage::setState($userId, 'create_user_json', ['server_id' => $serverId, 'admin' => $admin]);
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Enter Json file: [*.json]",
                Keyboards::cancel("srv:{$serverId}")
            );
            return;
        }

        // Random username generated: rnd_usr:<id>
        if (str_starts_with($data, 'rnd_usr:')) {
            $serverId = (int)substr($data, strlen('rnd_usr:'));
            $randomName = substr(bin2hex(random_bytes(3)), 0, 6);
            $state = Storage::getState($userId) ?: ['data' => []];
            $stateData = array_merge($state['data'] ?? [], [
                'server_id' => $serverId,
                'username'  => $randomName,
            ]);
            Storage::setState($userId, 'create_user_count', $stateData);
            tg_answer_callback($id, "Generated: {$randomName}");

            tg_edit_message(
                $chatId,
                $messageId,
                "Enter count: [0-9]",
                Keyboards::cancel("srv:{$serverId}")
            );
            return;
        }

        // Single user template create: use_tmpl:<id>:<tmpl_id>
        if (str_starts_with($data, 'use_tmpl:')) {
            $parts = explode(':', $data);
            $serverId = (int)($parts[1] ?? 0);
            $tmplId = (int)($parts[2] ?? 0);
            $tmpl = Storage::getTemplate($tmplId);
            $state = Storage::getState($userId);
            if (!$tmpl || (isset($tmpl['is_active']) && empty($tmpl['is_active'])) || empty($state['data']['username'])) {
                tg_answer_callback($id, "Session expired, please retry.", true);
                return;
            }
            $state['data']['data_limit'] = (float)$tmpl['data_limit'];
            $state['data']['date_limit'] = (int)$tmpl['date_limit'];
            $state['data']['date_type'] = $tmpl['date_type'] ?? (($tmpl['date_limit'] > 0) ? 'fixed' : 'unlimited');
            self::renderConfigSelection($chatId, $messageId, $serverId, $userId, $state['data'], $id);
            return;
        }

        // Single user custom create start: use_tmpl_custom:<id>
        if (str_starts_with($data, 'use_tmpl_custom:')) {
            $serverId = (int)substr($data, strlen('use_tmpl_custom:'));
            $state = Storage::getState($userId);
            if (!empty($state['data']['username'])) {
                Storage::setState($userId, 'create_user_data', $state['data']);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Enter DataLimit: [0-9]\n0 for unlimited",
                    Keyboards::cancel("srv:{$serverId}")
                );
            }
            return;
        }

        // Interactive config selector toggles for user creation:
        if (str_starts_with($data, 'usr_cfg:tgl:')) {
            $parts = explode(':', $data, 4);
            $serverId = (int)($parts[2] ?? 0);
            $cfgTag = rawurldecode($parts[3] ?? '');
            $state = Storage::getState($userId);
            $selected = $state['data']['selected_configs'] ?? [];
            $server = Storage::getServer($serverId);
            if(!$server || ($state['step']??'')!=='create_user_configs' || (int)($state['data']['server_id']??0)!==$serverId){tg_answer_callback($id,'Session expired, please retry.',true);return;}
            $configs = PanelManager::getServices($server);
            if (!in_array($cfgTag, array_map('strval', array_column($configs, 'id')), true)) {
                tg_answer_callback($id, '❌ Not Found.', true);
                return;
            }
            if (in_array($cfgTag, array_map('strval', $selected), true)) {
                $selected = array_values(array_filter($selected, fn($s) => (string)$s !== (string)$cfgTag));
            } else {
                $selected[] = $server['type'] === 'marzneshin' ? (int)$cfgTag : $cfgTag;
            }
            $state['data']['selected_configs'] = $selected;
            Storage::setState($userId, 'create_user_configs', $state['data']);
            $server = Storage::getServer($serverId);
            $configs = PanelManager::getServices($server);
            tg_answer_callback($id);
            $kb = Keyboards::configSelector(
                $serverId,
                $configs,
                $selected,
                'usr_cfg',
                "usr_cfg_done:{$serverId}",
                "srv:{$serverId}",(int)($state['data']['selector_page'] ?? 1)
            );
            tg_edit_message($chatId, $messageId, "Select Configs:", $kb);
            return;
        }

        if (str_starts_with($data, 'usr_cfg:all:')) {
            $serverId = (int)substr($data, strlen('usr_cfg:all:'));
            $server = Storage::getServer($serverId);
            $state=Storage::getState($userId);
            if (!$server || ($state['step']??'')!=='create_user_configs' || (int)($state['data']['server_id']??0)!==$serverId) { tg_answer_callback($id, "Session expired, please retry.", true); return; }
            $configs = PanelManager::getServices($server);
            $selected = array_column($configs, 'id');
            $state['data']['selected_configs'] = $selected;
            Storage::setState($userId, 'create_user_configs', $state['data']);
            tg_answer_callback($id);
            $kb = Keyboards::configSelector(
                $serverId,
                $configs,
                $selected,
                'usr_cfg',
                "usr_cfg_done:{$serverId}",
                "srv:{$serverId}",(int)($state['data']['selector_page'] ?? 1)
            );
            tg_edit_message($chatId, $messageId, "Select Configs:", $kb);
            return;
        }

        if (str_starts_with($data, 'usr_cfg:none:')) {
            $serverId = (int)substr($data, strlen('usr_cfg:none:'));
            $server = Storage::getServer($serverId);
            $state = Storage::getState($userId);
            if (!$server || ($state['step']??'')!=='create_user_configs' || (int)($state['data']['server_id']??0)!==$serverId) { tg_answer_callback($id, "Session expired, please retry.", true); return; }
            $configs = PanelManager::getServices($server);
            $state['data']['selected_configs'] = [];
            Storage::setState($userId, 'create_user_configs', $state['data']);
            tg_answer_callback($id);
            $kb = Keyboards::configSelector(
                $serverId,
                $configs,
                [],
                'usr_cfg',
                "usr_cfg_done:{$serverId}",
                "srv:{$serverId}",(int)($state['data']['selector_page'] ?? 1)
            );
            tg_edit_message($chatId, $messageId, "Select Configs:", $kb);
            return;
        }

        if (str_starts_with($data, 'usr_cfg_done:')) {
            $serverId = (int)substr($data, strlen('usr_cfg_done:'));
            $state = Storage::getState($userId);
            if (empty($state['data'])) {
                tg_answer_callback($id, "Session expired, please retry.", true);
                return;
            }
            if (empty($state['data']['selected_configs'])) {
                tg_answer_callback($id, "❌ Not Found Any Config.", true);
                return;
            }
            self::executeUserCreation($chatId, $messageId, $serverId, $userId, $state['data'], $id);
            return;
        }

        // Custom date type chosen during create flow: crt_dt_type:<id>:<type>
        if (str_starts_with($data, 'crt_dt_type:')) {
            $parts = explode(':', $data, 4);
            $serverId = (int)($parts[1] ?? 0);
            $type = $parts[3] ?? ($parts[2] ?? 'fixed');
            $state = Storage::getState($userId);
            if (empty($state['data']['username'])) {
                tg_answer_callback($id, "Session expired, please retry.", true);
                return;
            }
            $state['data']['date_type'] = $type;
            if ($type === 'unlimited') {
                $state['data']['date_limit'] = 0;
                self::renderConfigSelection($chatId, $messageId, $serverId, $userId, $state['data'], $id);
            } elseif ($type === 'onhold') {
                Storage::setState($userId, 'create_user_expire', $state['data']);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Enter DateLimit: [0-9]",
                    Keyboards::cancel("srv:{$serverId}")
                );
            } else {
                Storage::setState($userId, 'create_user_expire', $state['data']);
                tg_answer_callback($id);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Enter DateLimit: [0-9]",
                    Keyboards::cancel("srv:{$serverId}")
                );
            }
            return;
        }

        // Check for Update: check_update
        if ($data === 'check_update') {
            require_once __DIR__ . '/../version.php';
            $currentVersion = defined('HOLDERBOT_VERSION') ? HOLDERBOT_VERSION : '0.6.0';
            $releaseInfo = @file_get_contents('https://api.github.com/repos/erfjab/holderbot/releases/latest', false,
                stream_context_create(['http' => ['header' => "User-Agent: HolderBot-PHP\r\n", 'timeout' => 5]]));
            if ($releaseInfo) {
                $release = json_decode($releaseInfo, true);
                $latest = ltrim($release['tag_name'] ?? '', 'v');
                $current = ltrim($currentVersion, 'v');
                if (version_compare($latest, $current, '>')) {
                    tg_answer_callback($id, "🎉 New version is ready!", true);
                } else {
                    tg_answer_callback($id, "You are update!", true);
                }
            } else {
                tg_answer_callback($id, "You are update!", true);
            }
            return;
        }

        // Add server: add_srv
        if ($data === 'add_srv') {
            Storage::setState($userId, 'add_server_remark');
            tg_answer_callback($id);
            tg_edit_message(
                $chatId,
                $messageId,
                "Enter remark: [a-z]",
                Keyboards::cancel('home')
            );
            return;
        }

        tg_answer_callback($id, "Action not found.");
    }

    private static function queueBatch(string $kind, array $server, array $params, int|string $chatId, int $messageId, int $userId, string $callbackId): void {
        try {
            $params['message_id'] = $messageId;
            $submission='callback:'.$callbackId;
            $job = $kind === 'stats'
                ? BatchQueue::enqueueInline($kind, $server, $params, $chatId, $userId, $submission)
                : BatchQueue::enqueue($kind, $server, $params, $chatId, $userId, $submission);
        } catch (Throwable $e) {
            error_log('Batch submission failed: ' . $e->getMessage());
            tg_answer_callback($callbackId, 'Unable to queue this batch. Check queue storage and batch size.', true);
            return;
        }
        Storage::clearState($userId);
        tg_answer_callback($callbackId, BatchQueue::loadingMessage((string)$job['kind']));
        if ($kind === 'stats') {
            if (in_array($job['status'], ['completed','failed','cancelled'], true)) return;
            tg_edit_message($chatId, $messageId, BatchQueue::loadingMessage($kind), BatchQueue::keyboard($job));
            BatchQueue::run(5.0, 100, $job['id']);
            return;
        }
        $description = BatchQueue::describe($job);
        if (!in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) $description .= "\nUse Refresh status to check progress.";
        tg_edit_message($chatId, $messageId, $description, BatchQueue::keyboard($job));
    }

    private static function renderCachedStats(string $callbackId,int|string $chatId,int $messageId,int $serverId,bool $freshOnly=false): bool {
        $cached=BatchQueue::cachedStats($serverId,$freshOnly);
        if(!$cached) return false;
        tg_answer_callback($callbackId,'Showing latest completed statistics.');
        MessageTracker::cleanup($chatId,$messageId>0?[$messageId]:[]);
        tg_edit_message($chatId,$messageId,$cached['text'],Keyboards::stats($serverId));
        foreach($cached['chunks'] as $chunk) {
            $sent=tg_send_message($chatId,$chunk);
            if(!empty($sent['result']['message_id'])) MessageTracker::rememberForCleanup($chatId,(int)$sent['result']['message_id']);
        }
        return true;
    }

    private static function renderHome(int|string $chatId, int $messageId): void {
        $servers = Storage::getServers();
        $text = Formatter::start();
        $kb = Keyboards::home($servers);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }

    private static function renderServerMenu(int|string $chatId, int $messageId, int $serverId): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::home(Storage::getServers()));
            return;
        }

        $text = "Select a Button";
        $kb = Keyboards::serverMenu($server);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }

    private static function renderServerMenuFresh(int|string $chatId, int $serverId): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_send_message($chatId, "❌ Not Found.", Keyboards::home(Storage::getServers()));
            return;
        }
        tg_send_message($chatId, "Select a Button", Keyboards::serverMenu($server));
    }

    private static function renderServerSettings(int|string $chatId, int $messageId, int $serverId): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::home(Storage::getServers()));
            return;
        }

        $text = Formatter::serverCard($server);
        $kb = Keyboards::serverSettings($server);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }

    public static function renderAdminSearchResults(int|string $chatId,int $messageId,int $serverId,string $query,int $page=1): void {
        $server=Storage::getServer($serverId);
        if(!$server){tg_edit_message($chatId,$messageId,'❌ Server not found.',Keyboards::home(Storage::getServers()));return;}
        $needle=strtolower(trim($query));
        $admins=array_values(array_filter(PanelManager::getAdmins($server),fn(string $admin):bool=>$needle===''||str_contains(strtolower($admin),$needle)));
        natcasesort($admins);$admins=array_values($admins);
        $text=$admins ? '<b>Administrators found:</b> '.count($admins) : '<b>No administrators found.</b>';
        tg_edit_message($chatId,$messageId,$text,Keyboards::adminSearchResults($serverId,$admins,$page));
    }

    private static function renderAdminCard(int|string $chatId,int $messageId,array $server,string $username,int $page): void {
        $admin=PanelManager::getAdmin($server,$username);
        if(!$admin){tg_edit_message($chatId,$messageId,'❌ Administrator not found.',Keyboards::cancel("srv:{$server['id']}"));return;}
        $counts=PanelManager::getAdminUserCounts($server,$username);
        tg_edit_message($chatId,$messageId,Formatter::adminCard($server,$admin,$counts),Keyboards::adminActions((int)$server['id'],$username,$page,(string)$server['type']));
    }

    private static function renderUsersList(
        int|string $chatId,
        int $messageId,
        int $serverId,
        int $page,
        string $filter = 'all',
        ?string $callbackId = null
    ): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::home(Storage::getServers()));
            return;
        }

        $limit = 10;
        $statusParam = ($filter !== 'all') ? $filter : null;
        $users = PanelManager::getUsers($server, $page, $limit, null, $statusParam,null,true);
        $total=method_exists(PanelManager::class,'getLastUsersTotal')?PanelManager::getLastUsersTotal($server):null;
        if($total!==null && $page>max(1,(int)ceil($total/$limit))){$page=max(1,(int)ceil($total/$limit));$users=PanelManager::getUsers($server,$page,$limit,null,$statusParam,null,true);}
        $hasMore=$total!==null ? $page*$limit<$total : count($users)===$limit;

        if ($callbackId) tg_answer_callback($callbackId);
        $text = $users ? "Select a item or create a new:" : "No users found.";

        $kb = Keyboards::usersList($serverId, $users, $page, $hasMore, $filter);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }

    private static function renderUserCard(int|string $chatId, int $messageId, int $serverId, string $username,?string $backData=null): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::home(Storage::getServers()));
            return;
        }

        $user = PanelManager::getUser($server, $username,true);
        if (!$user) {
            tg_edit_message(
                $chatId,
                $messageId,
                "❌ Not Found.",
                Keyboards::serverMenu($server)
            );
            return;
        }

        $card = Formatter::userCard($server, $user,PanelManager::getBotUsername());
        if($backData!==null && method_exists(Storage::class,'rememberUserBack')) Storage::rememberUserBack($chatId,$serverId,$username,$backData);
        $backData=$backData??(method_exists(Storage::class,'userBack')?Storage::userBack($chatId,$serverId,$username):null);
        $kb = Keyboards::userActions($serverId, $user['username'], $user['is_active'], $user['status'],$backData,PanelManager::isSudo($server));
        tg_edit_message($chatId, $messageId, $card, $kb);
    }

    private static function handleUserAction(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        int $userId,
        int $serverId,
        string $username,
        string $action
    ): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_answer_callback($callbackId, "Server not found.", true);
            return;
        }

        switch ($action) {
            case 'tgl_ask':
                Storage::setState($userId,'user_confirm',['action'=>'tgl','server_id'=>$serverId,'username'=>$username]);
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Are you sure? <code>" . Formatter::escape($username) . "</code>?",
                    Keyboards::confirm("act_confirm:tgl:{$serverId}:{$username}:yes", "act_confirm:tgl:{$serverId}:{$username}:no")
                );
                break;

            case 'chg': // Recharge user - requires at least one active template to exist
                $templates = Storage::getActiveTemplates();
                if (empty($templates)) {
                    tg_answer_callback($callbackId, "❌ Not Found.", true);
                    return;
                }
                Storage::setState($userId, 'user_mod_charge', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                $kb = Keyboards::templateSelector($serverId, $templates, 'chg_tmpl', false,1,"usr:{$serverId}:{$username}");
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Select items",
                    $kb
                );
                break;

            case 'dl': // Modify Data limit
                Storage::setState($userId, 'user_mod_datalimit', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Enter DataLimit: [0-9]\n0 for unlimited",
                    Keyboards::cancel("usr:{$serverId}:{$username}")
                );
                break;

            case 'dt': // Modify Date limit - show type selector
                Storage::setState($userId,'user_mod_datetype',['server_id'=>$serverId,'username'=>$username]);
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Select a Button",
                    Keyboards::dateTypeSelector($serverId, $username)
                );
                break;

            case 'cfg': // Modify Configs/Inbounds
                $services = PanelManager::getServices($server);
                $user = PanelManager::getUser($server, $username,true);
                if (empty($services)) {
                    tg_answer_callback($callbackId, "No configs/services found on this server.", true);
                    return;
                }
                if(!$user){tg_answer_callback($callbackId,'❌ Not Found.',true);return;}
                $currentServices = $user['service_ids'] ?? [];
                Storage::setState($userId, 'user_mod_configs', [
                    'server_id'        => $serverId,
                    'username'         => $username,
                    'current_services' => $currentServices,
                ]);
                tg_answer_callback($callbackId);
                $kb = Keyboards::configSelector(
                    $serverId,
                    $services,
                    $currentServices,
                    'cfg_pick:' . rawurlencode($username),
                    "cfg_save:{$serverId}:{$username}",
                    "usr:{$serverId}:{$username}"
                );
                tg_edit_message($chatId, $messageId, "Select Configs:", $kb);
                break;

            case 'nt': // Modify Note
                Storage::setState($userId, 'user_mod_note', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Enter note text:",
                    Keyboards::cancel("usr:{$serverId}:{$username}")
                );
                break;

            case 'own': // Change Owner
                if(!PanelManager::isSudo($server)){tg_answer_callback($callbackId,'Changing ownership requires sudo panel access.',true);break;}
                $admins = PanelManager::getAdmins($server);
                Storage::setState($userId, 'user_mod_owner', ['server_id' => $serverId, 'username' => $username]);
                tg_answer_callback($callbackId);
                $kb = Keyboards::adminsSelector($serverId, $admins, 'set_own:' . rawurlencode($username), false, "usr:{$serverId}:{$username}");
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Select admin:",
                    $kb
                );
                break;

            case 'rst_ask':
                Storage::setState($userId,'user_confirm',['action'=>'rst','server_id'=>$serverId,'username'=>$username]);
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Are you sure? <code>" . Formatter::escape($username) . "</code> to 0?",
                    Keyboards::confirm("act_confirm:rst:{$serverId}:{$username}:yes", "act_confirm:rst:{$serverId}:{$username}:no")
                );
                break;

            case 'rvk_ask':
                Storage::setState($userId,'user_confirm',['action'=>'rvk','server_id'=>$serverId,'username'=>$username]);
                tg_answer_callback($callbackId);
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Are you sure? <code>" . Formatter::escape($username) . "</code>. Old links will stop working. Continue?",
                    Keyboards::confirm("act_confirm:rvk:{$serverId}:{$username}:yes", "act_confirm:rvk:{$serverId}:{$username}:no")
                );
                break;

            case 'qr':
                // Acknowledge the callback before the panel lookup. Token
                // refresh and user retrieval can each require a network call;
                // Telegram should stop showing the button spinner immediately.
                tg_answer_callback($callbackId, "Generating QR code...");
                $job = BatchQueue::enqueueInline('qr', $server, [
                    'username' => $username, 'message_id' => $messageId,
                ], $chatId, $userId, 'callback:' . $callbackId);
                tg_edit_message($chatId, $messageId, 'Loading...');
                $attempt = BatchQueue::executeInline($job, function () use ($server, $username, $chatId) {
                    $user = PanelManager::getUser($server, $username,true);
                    if (!$user || empty($user['subscription_url'])) throw new InvalidArgumentException('No subscription link available for QR');
                    return $user;
                });
                if ($attempt['state'] === 'queued') {
                    tg_edit_message($chatId, $messageId, BatchQueue::fallbackMessage($attempt['job']), BatchQueue::keyboard($attempt['job']));
                } elseif ($attempt['state'] === 'failed') {
                    tg_edit_message($chatId, $messageId, "No subscription link available for QR.", Keyboards::userActions($serverId, $username, false, ''));
                } elseif ($attempt['state'] !== 'completed') {
                    tg_edit_message($chatId, $messageId, BatchQueue::describe($attempt['job']), BatchQueue::keyboard($attempt['job']));
                }
                /* The QR is delivered by the inline operation or its queue fallback. */
                if ($attempt['state'] !== 'completed') {
                    return;
                }
                // Final delivery is committed with the job result.
                break;

            case 'del':
                Storage::setState($userId,'user_confirm',['action'=>'delete','server_id'=>$serverId,'username'=>$username]);
                tg_answer_callback($callbackId);
                $confirmKb = Keyboards::confirm("del_ok:{$serverId}:{$username}", "usr:{$serverId}:{$username}");
                tg_edit_message(
                    $chatId,
                    $messageId,
                    "Are you sure? <code>" . Formatter::escape($username) . "</code> from <b>{$server['remark']}</b>?",
                    $confirmKb
                );
                break;
        }
    }

    private static function renderUserCreatePrompt(
        int|string $chatId,
        int $messageId,
        int $serverId,
        int $userId,
        string $admin,
        string $callbackId
    ): void {
        Storage::setState($userId, 'create_user_name', ['server_id' => $serverId, 'admin' => $admin]);
        tg_answer_callback($callbackId);
        tg_edit_message(
            $chatId,
            $messageId,
            "Enter remark: [a-z]",
            Keyboards::navigationLast([
                'inline_keyboard' => [
                    [
                        ['text' => 'Random Username', 'callback_data' => "rnd_usr:{$serverId}"],
                    ],
                    [
                        ['text' => 'Create With Json', 'callback_data' => "new_usr_json:{$serverId}"],
                    ],
                    [['text' => '🏛️ Home', 'callback_data' => 'home']],
                    [['text' => '◀️ Back', 'callback_data' => "srv:{$serverId}"]],
                ]
            ])
        );
    }

    public static function renderConfigSelection(
        int|string $chatId,
        int $messageId,
        int $serverId,
        int $userId,
        array $stateData,
        string $callbackId
    ): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel());
            return;
        }
        $configs = PanelManager::getServices($server);

        if (empty($configs)) {
            // Matches the original bot: creation is blocked entirely (not
            // silently attempted with zero configs) when the server has none.
            Storage::clearState($userId);
            if ($messageId > 0) tg_answer_callback($callbackId, "❌ Not Found Any Config.", true);
            tg_edit_message($chatId, $messageId, "❌ Not Found.", Keyboards::cancel("srv:{$serverId}"));
            return;
        }

        $allConfigIds = array_column($configs, 'id');
        $stateData['selected_configs'] = $allConfigIds;
        Storage::setState($userId, 'create_user_configs', $stateData);

        if ($messageId > 0) tg_answer_callback($callbackId);
        $kb = Keyboards::configSelector(
            $serverId,
            $configs,
            $stateData['selected_configs'],
            'usr_cfg',
            "usr_cfg_done:{$serverId}",
            "srv:{$serverId}"
        );
        tg_edit_message($chatId, $messageId, "Select Configs:", $kb);
    }

    public static function executeUserCreation(
        int|string $chatId,
        int $messageId,
        int $serverId,
        int $userId,
        array $stateData,
        string $callbackId
    ): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_answer_callback($callbackId, "Server not found.", true);
            return;
        }

        if (!empty($stateData['import_file_id'])) {
            self::queueBatch('import', $server, $stateData, $chatId, $messageId, $userId, $callbackId);
            return;
        }
        if (!empty($stateData['uploaded_json']) || (int)($stateData['count'] ?? 1) > 1) {
            self::queueBatch('create', $server, $stateData, $chatId, $messageId, $userId, $callbackId);
            return;
        }

        $username = (string)($stateData['username'] ?? 'user');
        $dataLimit = (float)($stateData['data_limit'] ?? 0);
        $dateLimit = (int)($stateData['date_limit'] ?? 0);
        $dateType = (string)($stateData['date_type'] ?? 'fixed');
        $job = BatchQueue::enqueueInline('create', $server, [
            'username' => $username, 'data_limit' => $dataLimit, 'date_limit' => $dateLimit,
            'date_type' => $dateType, 'selected_configs' => $stateData['selected_configs'] ?? [],
            'admin' => $stateData['admin'] ?? null, 'message_id' => $messageId, 'send_qr' => true,
        ], $chatId, $userId, 'callback:' . $callbackId);
        Storage::clearState($userId);
        tg_answer_callback($callbackId, 'Creating user...');
        tg_edit_message($chatId, $messageId, 'Loading...');
        $attempt = BatchQueue::executeInline($job, function (array &$running) use ($server, $username, $dataLimit, $dateLimit, $stateData, $dateType, $chatId) {
            $created = PanelManager::createUser(
                $server, $username, $dataLimit, $dateLimit, null, $stateData['selected_configs'] ?? [], $dateType, $stateData['admin'] ?? null, BatchQueue::creationCheckpoint($running)
            );
            return $created;
        });
        if ($attempt['state'] === 'queued') {
            tg_edit_message($chatId, $messageId, BatchQueue::fallbackMessage($attempt['job']), BatchQueue::keyboard($attempt['job']));
            return;
        }
        if ($attempt['state'] !== 'completed') {
            tg_edit_message($chatId, $messageId, BatchQueue::describe($attempt['job']), BatchQueue::keyboard($attempt['job']));
            return;
        }
        // QR and final replacement are committed with the confirmed creation.
    }

    private static function executeUserDelete(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        int $userId,
        int $serverId,
        string $username
    ): void {
        $server = Storage::getServer($serverId);
        if (!$server) {
            tg_answer_callback($callbackId, "Server not found.", true);
            return;
        }

        self::runUserMutation($callbackId,$chatId,$messageId,$userId,$server,['username'=>$username,'operation'=>'delete'],'srv:'.$serverId);
    }

    private static function runUserMutation(string $callbackId,int|string $chatId,int $messageId,int $userId,array $server,array $params,?string $back=null): void
    {
        tg_answer_callback($callbackId,'Updating user...');
        tg_edit_message($chatId,$messageId,'Loading...');
        $params['message_id']=$messageId;
        $attempt=BatchQueue::submitUserMutation($server,$params,$chatId,$userId,'callback:'.$callbackId);
        if($attempt['state']==='queued') tg_edit_message($chatId,$messageId,BatchQueue::fallbackMessage($attempt['job']),BatchQueue::keyboard($attempt['job']));
        elseif($attempt['state']!=='completed') tg_edit_message($chatId,$messageId,BatchQueue::describe($attempt['job']),BatchQueue::keyboard($attempt['job']));
    }

    private static function consumeConfirmation(int $userId,string $step,array $expected): bool
    {
        $state=Storage::getState($userId);
        if(($state['step']??'')!==$step) return false;
        foreach($expected as $key=>$value) if(($state['data'][$key]??null)!==$value) return false;
        Storage::clearState($userId);
        return true;
    }

    private static function validAdmin(array $server,string $username,bool $allowAll): bool
    {
        if($allowAll && $username==='ALL') return true;
        foreach(PanelManager::getAdmins($server) as $admin) {
            $candidate=is_array($admin)?($admin['username']??$admin['name']??''):$admin;
            if($candidate!=='' && hash_equals((string)$candidate,$username)) return true;
        }
        return false;
    }

    private static function renderTemplates(int|string $chatId, int $messageId,int $page=1): void {
        $templates = Storage::getTemplates();
        $text = "Select a item or create a new:";
        $kb = Keyboards::templatesMenu($templates,$page);
        tg_edit_message($chatId, $messageId, $text, $kb);
    }
}
