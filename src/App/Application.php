<?php

declare(strict_types=1);

namespace AutoLepao\App;

use AutoLepao\Core\{Crypto, Device, Health, Lepao, Policy, Problem, Qq, Rules, Runner, Schedule, TaskPlan, Wechat};
use AutoLepao\Support\{Database, Diagnostics, Http, SecretBox, Wakeup};

final class Application
{
    public readonly Database $db;
    public readonly Lepao $lepao;
    public readonly Policy $policy;
    public readonly Runner $runner;
    private readonly Wechat $wechat;
    private readonly Qq $qq;
    private readonly SecretBox $privateState;

    public function __construct(public readonly string $directory, ?Http $http = null)
    {
        $http ??= new Http();
        $this->db = new Database($directory.'/lepao.sqlite');
        $this->privateState = new SecretBox($directory);
        $this->lepao = new Lepao($http, new Diagnostics());
        $this->policy = new Policy($this->lepao);
        $this->runner = new Runner($this->lepao, $http);
        $this->wechat = new Wechat($http, $this->privateState);
        $this->qq = new Qq($http, $this->privateState);
    }

    public function settings(): array
    {
        $settings = $this->privateState->settings();
        return ['wechat_app_id' => $settings['app_id'] ?? 'wx5a2e1ff396785475',
            'wechat_configured' => !empty($settings['app_secret']),
            'worker' => $this->workerOnline(), 'version' => '4.3.3 / 233'];
    }

    public function saveSettings(array $input): array
    {
        $settings = $this->privateState->settings();
        $appid = trim((string) ($input['wechat_app_id'] ?? ''));
        if (!preg_match('/^wx[a-zA-Z0-9]{10,32}$/D', $appid)) {
            throw new Problem('微信应用 ID 格式不正确');
        }
        $settings['app_id'] = $appid;
        if (isset($input['wechat_secret']) && $input['wechat_secret'] !== '') {
            $secret = trim((string) $input['wechat_secret']);
            if (!preg_match('/^[a-zA-Z0-9]{16,128}$/D', $secret)) {
                throw new Problem('微信 Secret 格式不正确');
            }
            $settings['app_secret'] = $secret;
        }
        $temp = $this->directory.'/settings-'.bin2hex(random_bytes(8)).'.tmp';
        file_put_contents($temp, Crypto::json($settings), LOCK_EX);
        if (!rename($temp, $this->directory.'/settings.json')) {
            throw new Problem('设置保存失败', 500);
        }
        if (is_file($this->directory.'/wx-ticket.json')) {
            unlink($this->directory.'/wx-ticket.json');
        }
        return $this->settings();
    }

    public function workerOnline(): bool
    {
        $lock = fopen($this->directory.'/worker.lock', 'c');
        $free = flock($lock, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        return !$free;
    }

    public function startBinding(string $provider): array
    {
        if (!in_array($provider, ['wechat','qq'], true)) {
            throw new Problem('请选择微信或 QQ');
        }
        $qr = $provider === 'qq' ? $this->qq->start() : $this->wechat->start();
        $id = bin2hex(random_bytes(16));
        $this->db->execute('DELETE FROM bindings WHERE expires<?', [time() - 3600]);
        $this->db->execute(
            'INSERT INTO bindings(id,provider,state,context,expires) VALUES(?,?,?,?,?)',
            [$id, $provider, 'waiting', Crypto::json($qr), $qr['expires']]
        );
        return ['id' => $id, 'provider' => $provider, 'image' => $qr['image'], 'expires' => $qr['expires']];
    }

    public function pollBinding(string $id): array
    {
        $row = $this->db->one('SELECT * FROM bindings WHERE id=?', [$id]) ?? throw new Problem('二维码不存在', 404);
        if (in_array($row['state'], ['connected','expired','failed'], true)) {
            return $this->bindingPublic($row);
        }
        if ((int) $row['expires'] <= time()) {
            $this->db->execute("UPDATE bindings SET state='expired',context='{}' WHERE id=?", [$id]);
            return ['state' => 'expired'];
        }
        if ($row['state'] === 'working') {
            return ['state' => 'working'];
        }
        if (!$this->db->execute("UPDATE bindings SET state='working' WHERE id=? AND state IN ('waiting','scanned')", [$id])) {
            return ['state' => 'working'];
        }
        try {
            $qr = json_decode($row['context'], true, 512, JSON_THROW_ON_ERROR);
            if ($row['provider'] === 'qq') {
                $poll = $this->qq->poll($qr);
                $qr = $poll['qr'];
                $state = $poll['state'];
                $code = $poll['sig_url'] ?? '';
            } else {
                $poll = $this->wechat->poll($qr['uuid']);
                $error = (int) ($poll['wx_errcode'] ?? -500);
                $state = match ($error) {
                    405 => 'ok', 404 => 'scanned', 408 => 'waiting', 402,403 => 'expired', default => 'failed'
                };
                $code = $poll['wx_code'] ?? '';
            }
            if ($state !== 'ok') {
                $this->db->execute('UPDATE bindings SET state=?,context=? WHERE id=?', [$state, Crypto::json($qr), $id]);
                return ['state' => $state];
            }
            $grant = $row['provider'] === 'qq' ? $this->qq->exchange($qr, $code) : $this->wechat->exchange($code);
            $grant['provider'] = $row['provider'];
            $old = $this->db->one('SELECT * FROM accounts WHERE provider=? AND openid=?', [$row['provider'], $grant['openid']]);
            $device = $old ? json_decode($old['credentials'], true)['device_profile'] : Device::generated();
            $login = $this->lepao->login($grant, $device);
            $byUid = $this->db->one('SELECT * FROM accounts WHERE uid=?', [(string) $login['uid']]);
            if ($byUid && !$old) {
                $device = json_decode($byUid['credentials'], true)['device_profile'];
                $login = $this->lepao->login($grant, $device);
            }
            $credentials = ['provider' => $row['provider'], 'uid' => (string) $login['uid'],
                'access_token' => $login['access_token'], 'refresh_token' => $login['refresh_token'] ?? '',
                'device_profile' => $device, 'openid' => $grant['openid'],
                $row['provider'] === 'qq' ? 'qq_token' : 'wechat_token' => $grant['access_token']];
            $nickname = (string) ($grant['nickname'] ?? $login['name'] ?? '');
            try {
                $profile = $row['provider'] === 'qq' ? $this->qq->profile($credentials) : $this->wechat->profile($credentials);
                $nickname = (string) ($profile['nickname'] ?? $nickname);
            } catch (\Throwable) { /* A nickname lookup must not discard a successful login. */
            }
            $account = $byUid['id'] ?? bin2hex(random_bytes(16));
            $this->db->transaction(function () use ($account, $credentials, $nickname, $row, $id) {
                $this->db->execute(<<<'SQL'
                    INSERT INTO accounts(id,uid,provider,openid,nickname,credentials,updated_at)
                    VALUES(?,?,?,?,?,?,?) ON CONFLICT(uid) DO UPDATE SET
                    provider=excluded.provider,openid=excluded.openid,nickname=excluded.nickname,
                    credentials=excluded.credentials,status='valid',message='',overview=NULL,updated_at=excluded.updated_at
                    SQL, [$account, $credentials['uid'], $row['provider'], $credentials['openid'],
                        $nickname, Crypto::json($credentials), time()]);
                $this->db->execute("UPDATE bindings SET state='connected',context='{}',account_id=? WHERE id=?", [$account, $id]);
                $this->db->execute("UPDATE hosting SET state='active',pause_reason=NULL,next_at=?,message='已重新登录，继续每日预约' WHERE account_id=? AND state='paused' AND pause_reason='login_invalid'", [time(), $account]);
            });
            Wakeup::notify();
            return ['state' => 'connected', 'account_id' => $account];
        } catch (\Throwable $error) {
            $message = $error instanceof Problem ? $error->getMessage() : '登录暂未完成，请重新获取二维码';
            $this->db->execute("UPDATE bindings SET state='failed',context='{}',message=? WHERE id=?", [$message, $id]);
            error_log('QR binding failed '.get_class($error));
            return ['state' => 'failed', 'message' => $message];
        }
    }

    private function bindingPublic(array $row): array
    {
        return array_intersect_key($row, array_flip(['state','message','account_id']));
    }

    public function accounts(): array
    {
        return array_map(function ($row) {
            $credentials = json_decode($row['credentials'], true);
            return array_intersect_key($row, array_flip(['id','uid','provider','nickname','status','message','updated_at']))
                + ['device' => $credentials['device_profile'],
                    'overview' => $row['overview'] ? json_decode($row['overview'], true) : null];
        }, $this->db->all('SELECT * FROM accounts ORDER BY updated_at DESC'));
    }

    public function credentials(string $id): array
    {
        $account = $this->db->account($id);
        $credentials = json_decode($account['credentials'], true, 512, JSON_THROW_ON_ERROR);
        $lookup = $credentials;
        unset($lookup['student_num'], $lookup['school_id']);
        $response = $this->lepao->call('Student/InfoV14', $lookup);
        try {
            $credentials = array_merge($credentials, Lepao::studentProfile($response, $credentials['uid']));
        } catch (Problem $error) {
            // A missing student profile can also belong to a valid, uncertified account.
            $health = Health::lepao($response);
            $this->db->execute(
                'UPDATE accounts SET status=?,message=? WHERE id=? AND credentials=?',
                [$health['status'], $error->getMessage(), $id, $account['credentials']]
            );
            throw $error;
        }
        $saved = $this->db->execute(
            "UPDATE accounts SET credentials=?,status='valid',message='',updated_at=? WHERE id=? AND credentials=?",
            [Crypto::json($credentials), time(), $id, $account['credentials']]
        );
        if (!$saved) {
            throw new Problem('账号刚刚重新登录，请重试', 409);
        }
        return $credentials;
    }

    public function checkAccount(string $id): array
    {
        try {
            $credentials = $this->credentials($id);
            $overview = $this->policy->overview($credentials);
            $this->db->execute(
                'UPDATE accounts SET overview=? WHERE id=? AND credentials=?',
                [Crypto::json($overview), $id, Crypto::json($credentials)]
            );
            return ['available' => true, 'overview' => $overview];
        } catch (Problem $error) {
            return ['available' => false, 'message' => $error->getMessage()];
        }
    }

    public function deleteAccount(string $id): void
    {
        $this->db->transaction(function () use ($id) {
            $this->db->account($id);
            if ($this->db->one("SELECT id FROM tasks WHERE account_id=? AND state IN ('preparing','running','submitting')", [$id])) {
                throw new Problem('账号仍有执行中的任务，请等任务结束后删除', 409);
            }
            $this->db->execute('DELETE FROM tasks WHERE account_id=?', [$id]);
            $this->db->execute('DELETE FROM accounts WHERE id=?', [$id]);
        });
        Wakeup::notify();
    }

    public function zone(string $id, string $zone): array
    {
        if (!preg_match('/^[0-9]{1,16}$/D', $zone)) {
            throw new Problem('请选择跑区');
        }
        $credentials = $this->credentials($id);
        return $this->policy->fetch($credentials, ['school_id' => $credentials['school_id'], 'game_id' => $zone]);
    }

    public function plan(array $input, bool $activate = false): array
    {
        $id = (string) ($input['account_id'] ?? '');
        $credentials = $this->credentials($id);
        $raw = $input['config'] ?? [];
        if (!is_array($raw)) {
            throw new Problem('路线参数格式不正确');
        }
        if (!preg_match('/^[0-9]{1,16}$/D', (string) ($raw['game_id'] ?? ''))) {
            throw new Problem('请先选择跑区');
        }
        $raw['school_id'] = $credentials['school_id'];
        $policy = $this->policy->fetch($credentials, $raw, $activate);
        $raw['rules'] = $policy['rules'];
        $raw['term_id'] = $policy['term_id'];
        $config = Rules::normalize($raw);
        $mode = $input['mode'] ?? 'random';
        if (!in_array($mode, ['random','fixed','earliest'], true)) {
            throw new Problem('预约方式不正确');
        }
        $requested = $input['requested_at'] ?? null;
        if ($mode === 'fixed' && (!is_numeric($requested) || $requested <= 0)) {
            throw new Problem('请选择预约时间');
        }
        $slot = Schedule::choose($policy, $config, $mode, $requested === null ? null : (float) $requested);
        $config['assigned_checkpoints'] = TaskPlan::checkpoints($policy, $config, $slot['start_at']);
        $config['rules']['checkpoints'] = $config['assigned_checkpoints'];
        $policy['rules']['checkpoints'] = $config['assigned_checkpoints'];
        $route = Rules::route($config);
        $report = Rules::evaluate($config, $route, $slot['start_at']);
        if ($report['precheck'] !== 'pass') {
            throw new Problem('画线路线或配速不满足当前跑区规则，请检查打卡点和里程');
        }
        Runner::checkpointLogs($route, $config, $config['assigned_checkpoints'], (int) $slot['start_at'], $slot['duration_s']);
        return compact('config', 'policy', 'slot', 'route', 'report', 'mode');
    }

    public function createTask(array $input, string $ip): array
    {
        if (!$this->workerOnline()) {
            throw new Problem('调度器尚未启动，请先运行 php bin/console.php worker', 409);
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new Problem('无法取得提交来源 IP');
        }
        if ($this->db->one("SELECT id FROM tasks WHERE account_id=? AND state IN ('scheduled','preparing','running','submitting')", [(string) ($input['account_id'] ?? '')])) {
            throw new Problem('此账号已有预约或正在执行的任务', 409);
        }
        $plan = $this->plan($input, true);
        $plan['config']['submission_ip'] = $ip;
        $id = bin2hex(random_bytes(16));
        $this->db->transaction(function () use ($id, $input, $plan) {
            $account = (string) $input['account_id'];
            $this->db->account($account);
            if ($this->db->one("SELECT id FROM tasks WHERE account_id=? AND state IN ('scheduled','preparing','running','submitting')", [$account])) {
                throw new Problem('此账号已有预约或正在执行的任务', 409);
            }
            $this->db->execute(
                'INSERT INTO tasks(id,account_id,state,due_at,created_at,config,policy,mode,message) VALUES(?,?,?,?,?,?,?,?,?)',
                [$id, $account, 'scheduled', (int) ceil($plan['slot']['start_at']), time(),
                    Crypto::json($plan['config']), Crypto::json($plan['policy']), $plan['mode'], '已预约，等待开始']
            );
            $this->db->event($id, '已预约，等待北京时间 '.date('Y-m-d H:i:s', (int) $plan['slot']['start_at']).' 开始');
        });
        Wakeup::notify();
        return ['id' => $id, 'slot' => $plan['slot']];
    }

    public function cancelTask(string $id): void
    {
        $this->db->transaction(function () use ($id) {
            $row = $this->db->task($id);
            if ($row['state'] !== 'scheduled') {
                throw new Problem('任务已经开始，当前阶段无法取消', 409);
            }
            $this->db->execute("UPDATE tasks SET state='cancelled',message='已取消预约' WHERE id=? AND state='scheduled'", [$id]);
            $this->db->event($id, '已取消预约');
        });
        Wakeup::notify();
    }

    public function tasks(): array
    {
        return array_map(function ($row) {
            $config = json_decode($row['config'], true);
            unset($row['context'], $row['policy'], $row['config']);
            $row['config'] = array_intersect_key($config, array_flip(['distance_km','pace_s_per_km','game_id','term_id','assigned_checkpoints']));
            $row['result'] = $row['result'] ? json_decode($row['result'], true) : null;
            $row['events'] = $this->db->all('SELECT at,message FROM events WHERE task_id=? ORDER BY id', [$row['id']]);
            return $row;
        }, $this->db->all('SELECT t.*,a.nickname,a.uid FROM tasks t JOIN accounts a ON a.id=t.account_id ORDER BY created_at DESC LIMIT 100'));
    }

    public function hosting(): array
    {
        return $this->db->all('SELECT account_id,state,pause_reason,target,completed,term_id,next_at,message FROM hosting');
    }

    public function saveRoute(array $input): array
    {
        $id = $input['id'] ?? bin2hex(random_bytes(16));
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new Problem('路线 ID 无效');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || strlen($name) > 150) {
            throw new Problem('请给路线填写一个名称');
        }
        $config = $input['config'] ?? [];
        // A saved drawing has no account credentials or copied school policy.
        $config = array_intersect_key($config, array_flip(['control_points','closed','distance_km','pace_s_per_km','game_id']));
        if (!is_array($config['control_points'] ?? null) || count($config['control_points']) > 512) {
            throw new Problem('路线点无效');
        }
        $this->db->execute(
            'INSERT INTO routes(id,name,config) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,config=excluded.config',
            [$id, $name, Crypto::json($config)]
        );
        return ['id' => $id];
    }
}
