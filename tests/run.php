<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use AutoLepao\App\{Application, Worker};
use AutoLepao\Core\{Crypto, Device, Lepao, Policy, Problem, Qq, Rules, Runner, Schedule, TaskPlan, Trajectory};
use AutoLepao\Support\{Diagnostics, Http, Runtime};

date_default_timezone_set('Asia/Shanghai');
umask(0077);
$passed = 0;
function check(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) throw new RuntimeException($message);
    $passed++;
}
function rejects(callable $action, string $message): void
{
    try { $action(); } catch (Problem) { check(true, $message); return; }
    throw new RuntimeException($message);
}

final class FixtureHttp extends Http
{
    public array $calls = [];
    public bool $failSubmit = false;
    public string $profileMode = 'valid';
    public array $before;
    public function __construct()
    {
        $this->before = ['status' => 1, 'data' => [
            'school_id' => '999', 'run_zone_id' => '7', 'term_id' => '888',
            'term_start_time' => time() - 86400, 'term_end_time' => time() + 86400 * 60,
            'run_start_time' => 0, 'run_end_time' => 86400, 'week_time' => range(0, 6),
            'run_zone_list' => [['id' => '7', 'name' => 'Fixture field']],
            'total_num' => '4', 'term_num' => '20', 'set_point_type' => 1, 'repeat_num' => 0,
            'time_rule_arr' => [['start_time' => 0, 'end_time' => 86400, 'min_distance' => .2,
                'max_distance' => 10, 'max_pace' => 180, 'min_pace' => 540,
                'min_log_num' => 1, 'point_num_online' => 1, 'point_num_offline' => 0]],
            'run_line_info' => ['log_max_distance' => 100, 'point_num' => 1,
                'point_min_distance' => 0, 'point_max_distance1' => 3, 'point_max_distance2' => 3,
                'point_list' => [['id' => '501', 'address' => 'Fixture point', 'jingwei' => '30,110',
                    'type' => 1, 'point_type' => 1, 'is_online' => 1]]],
        ]];
    }

    public function json(string $method, string $url, array $options = []): array
    {
        $endpoint = basename(parse_url($url, PHP_URL_PATH));
        if (str_contains($url, 'api.weixin.qq.com/cgi-bin/token')) return ['access_token' => 'fixture-ticket-token'];
        if ($endpoint === 'getticket') return ['ticket' => 'fixture-ticket'];
        if (str_contains($url, 'open.weixin.qq.com/connect/l/')) return ['wx_errcode' => 405, 'wx_code' => 'fixture-code'];
        if ($endpoint === 'qrconnect') return ['uuid' => 'fixture-uuid', 'qrcode' => ['qrcodebase64' => base64_encode('fixture-image')]];
        if (str_contains($url, 'api.weixin.qq.com/sns/oauth2/access_token')) return ['access_token' => 'fixture-wx-token', 'openid' => 'fixture-wx-openid', 'unionid' => 'fixture-unionid'];
        if ($endpoint === 'userinfo') return ['nickname' => 'Fixture nickname', 'openid' => 'fixture-wx-openid'];
        $body = $options['form_params'] ?? [];
        if (isset($body['key'])) $body = json_decode(Crypto::decrypt('PARAMS_NEW_GAME', $body['key']), true);
        $this->calls[] = ['endpoint' => $endpoint, 'params' => $body];
        if ($endpoint === 'stopRunV278' && $this->failSubmit) throw new Problem('Fixture network timeout', 502);
        return match ($endpoint) {
            'quickLoginV339' => ['status' => 1, 'data' => ['uid' => '4242', 'access_token' => 'fixture-token']],
            'InfoV14' => $this->profileMode === 'invalid' ? ['status' => 102] : ['status' => 1, 'data' => array_merge(['uid' => '4242'], $this->profileMode === 'missing' ? [] : ['student_num' => 'fixture-student', 'school_id' => '999'])],
            'beforeRunV260' => $this->before,
            'getTimestampV278' => ['status' => 1, 'data' => ['str' => 'fixture-stamp']],
            'getOssSts' => ['status' => 1, 'data' => ['bucket' => 'fixture-bucket', 'AccessKeyId' => 'fixture',
                'AccessKeySecret' => 'fixture', 'SecurityToken' => 'fixture']],
            'stopRunV278' => ['status' => 1, 'data' => ['id' => '1001', 'record_status' => 1]],
            'allRecordList' => ['status' => 1, 'data' => ['list' => []]],
            default => throw new RuntimeException('Unexpected fixture endpoint '.$endpoint),
        };
    }

    public function request(string $method, string $url, array $options = []): array
    {
        if ($method !== 'PUT' || !str_contains($url, '.oss-cn-hangzhou.aliyuncs.com/')) throw new RuntimeException('Unexpected request');
        check(isset($options['headers']['Content-MD5'], $options['headers']['Authorization']), 'OSS signing headers');
        $decoded = json_decode(Crypto::decrypt('NEW_GAME', $options['body']), true);
        check(count($decoded) > 40 && isset($decoded[0]['a'], $decoded[0]['o'], $decoded[0]['s']), 'OSS trajectory payload');
        return ['status' => 200, 'body' => '', 'headers' => []];
    }
}

$http = new FixtureHttp();
$directory = sys_get_temp_dir().'/autolepao-test-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$app = new Application($directory, $http);
$device = Device::generated();
$credentials = ['uid' => '4242', 'access_token' => 'fixture-token', 'device_profile' => $device];
$account = str_repeat('a', 32);
$app->db->execute('INSERT INTO accounts(id,uid,provider,openid,nickname,credentials,updated_at) VALUES(?,?,?,?,?,?,?)',
    [$account, '4242', 'qq', 'fixture-openid', 'Test account', Crypto::json($credentials), time()]);
$input = ['account_id' => $account, 'mode' => 'earliest', 'config' => [
    'game_id' => '7', 'distance_km' => .3, 'pace_s_per_km' => 360,
    'control_points' => [['a' => 30, 'o' => 110], ['a' => 30.001, 'o' => 110], ['a' => 30.001, 'o' => 110.001]], 'closed' => false,
]];
$overview = $app->checkAccount($account);
check($overview['available'] && $overview['overview']['semester']['count'] === 4, 'Account and semester lookup');
$plan = $app->plan($input);
check($plan['config']['school_id'] === '999' && $plan['config']['term_id'] === '888', 'Account-specific school and term');
check(count($plan['config']['assigned_checkpoints']) === 1, 'Candidate list produces exactly assigned checkpoint count');
check($plan['report']['precheck'] === 'pass', 'Drawn-route preflight');
$route = $plan['route'];
check(abs(end($route)['distance_m'] - 300) < .05, 'Resampled path preserves distance');
check(abs(end($route)['elapsed'] - 108) < .01, 'Resampled path preserves duration');
check(max(array_column($route, 'o')) <= 110.00100001, 'Open path never overshoots drawn segments');
check($route === Rules::route($plan['config']), 'Preview generation is deterministic');
$bad = $input;
$bad['config']['control_points'] = [['a' => 31, 'o' => 111], ['a' => 31.001, 'o' => 111]];
rejects(fn() => $app->plan($bad), 'Route missing checkpoints is rejected before queueing');
$policy = $plan['policy'];
$policy['rules']['windows'][0]['end'] = 23 * 3600;
$policy['time_limits'] = [[['start' => 0, 'end' => 23 * 3600]]];
$day = strtotime('today');
$slot = Schedule::choose($policy, $plan['config'], 'fixed', $day + 22 * 3600 + 10, $day);
check($slot['start_at'] > $day + 22 * 3600, 'Schools can use their own closing time after 22:00');
rejects(fn() => Schedule::choose($policy, $plan['config'], 'fixed', $day + 23 * 3600 - 30, $day), 'Full duration and submission margin checked');
rejects(fn() => Schedule::choose($policy, $plan['config'], 'fixed', $day - 1, $day), 'Past reservations rejected');
$params = $app->lepao->params($credentials, ['version' => '216', 'version_name' => '4.1.6']);
check($params['version'] === '233' && $params['version_name'] === '4.3.3', 'Supported version cannot be overridden');
check($params['mobileDeviceId'] === $device['mobileDeviceId'], 'Device identity is stable');
check(!isset($app->lepao->params([])['token']), 'Fresh login does not carry a captured account token');
$app->lepao->login(['provider' => 'wechat', 'openid' => 'fixture-openid', 'access_token' => 'fixture-wx-token', 'unionid' => 'fixture-unionid'], $device);
$last = end($http->calls)['params'];
check($last['unionid'] === 'fixture-unionid' && $last['type'] === '3', 'WeChat optional unionid and type');
check($last['openid'] !== 'fixture-openid' && $last['accesstoken'] !== 'fixture-wx-token', 'Login fields use nonce encoding');
$context = $app->runner->prepare($plan['config'], $app->credentials($account) + ['submission_ip' => '127.0.0.1'], $plan['policy']);
check($context['params']['school_id'] === '999' && $context['params']['ip'] === '127.0.0.1', 'Submission uses account school and captured request IP');
rejects(fn() => $app->runner->finish($context), 'Early submission rejected');
$fakeClock = new class extends Runtime { public float $value; public function now(): float { return $this->value; } };
$fakeClock->value = $context['resume_at'];
$runner = new Runner($app->lepao, $http, $fakeClock);
check($runner->finish($context)['data']['record_status'] === 1, 'Submission reaches signed endpoint after timeline');
$context['resume_at'] = time() - 1;
$context['window_end'] = time() + 1000;
$task = str_repeat('b', 32);
$app->db->execute('INSERT INTO tasks(id,account_id,state,due_at,created_at,config,policy,context,mode) VALUES(?,?,?,?,?,?,?,?,?)',
    [$task, $account, 'running', time() - 1, time(), Crypto::json($plan['config']), Crypto::json($plan['policy']), Crypto::json($context), 'earliest']);
$http->failSubmit = true;
$worker = new Worker($app);
$worker->tick();
check($app->db->task($task)['state'] === 'uncertain', 'Ambiguous submission stays uncertain');
$count = count($http->calls);
check(!$worker->tick() && count($http->calls) === $count, 'Ambiguous submission is never retried automatically');
$public = Crypto::json(['accounts' => $app->accounts(), 'tasks' => $app->tasks()]);
check(!str_contains($public, 'fixture-token') && !str_contains($public, 'fixture-openid'), 'Public API never exposes tokens or OpenID');
$app->db->execute("UPDATE tasks SET state='submitting' WHERE id=?", [$task]);
$worker->recover();
check($app->db->task($task)['state'] === 'uncertain', 'Crash during submission is not blindly repeated');
$http->profileMode = 'missing';
check(!$app->checkAccount($account)['available'] && $app->db->account($account)['status'] === 'valid', 'Uncertified account is not reported as an invalid login');
$http->profileMode = 'invalid';
check(!$app->checkAccount($account)['available'] && $app->db->account($account)['status'] === 'invalid', 'Kicked session is marked invalid');
$http->profileMode = 'valid';
$app->saveSettings(['wechat_app_id' => 'wx0000000000000000', 'wechat_secret' => str_repeat('0', 32)]);
$binding = $app->startBinding('wechat');
$bound = $app->pollBinding($binding['id']);
check($bound['state'] === 'connected' && $bound['account_id'] === $account, 'QR exchange updates the original account');
check(count($app->accounts()) === 1 && $app->accounts()[0]['device']['mobileDeviceId'] === $device['mobileDeviceId'], 'QR re-login preserves account identity and device');
check($app->pollBinding($binding['id'])['state'] === 'connected', 'Completed QR polling is idempotent');
$app->db->execute("INSERT INTO hosting(account_id,state,pause_reason,target,term_id,config,submission_ip,next_at) VALUES(?,'paused','login_invalid',20,'888','{}','127.0.0.1',0)", [$account]);
$binding = $app->startBinding('wechat');
$app->pollBinding($binding['id']);
check($app->hosting()[0]['state'] === 'active', 'Re-login resumes hosting paused by an invalid login');
$app->db->execute("UPDATE hosting SET state='paused',pause_reason='manual' WHERE account_id=?", [$account]);
$binding = $app->startBinding('wechat');
$app->pollBinding($binding['id']);
check($app->hosting()[0]['state'] === 'paused', 'Re-login respects a manual hosting pause');
check(Qq::parsePoll("ptuiCB('67','0','','0','scanned','');")['state'] === 'scanned', 'QQ scanned-state parsing');
check(!Qq::trusted('https://openmobile.qq.com.example.com/callback'), 'QQ rejects lookalike callback domains');
check(Qq::grant('https://evil.example/#openid=x&access_token=y') === null, 'QQ rejects tokens from an untrusted callback');
check(Qq::appSign(1700000000) === md5('com.lptiyu.tanke_d6ab92f37e0bdbff74d08a64c19ea1d8_1700000000'), 'QQ mobile application signature');
$binding = $app->startBinding('wechat');
$app->db->execute("UPDATE bindings SET state='working',expires=? WHERE id=?", [time() - 1, $binding['id']]);
check($app->pollBinding($binding['id'])['state'] === 'expired', 'Interrupted QR poll eventually expires');
$lock = fopen($directory.'/worker.lock', 'c');
flock($lock, LOCK_EX);
$app->db->execute("UPDATE tasks SET state='failed' WHERE id=?", [$task]);
$created = $app->createTask($input, '127.0.0.1');
check($app->db->task($created['id'])['state'] === 'scheduled', 'Validated reservation is queued');
rejects(fn() => $app->createTask($input, '127.0.0.1'), 'An account cannot have two active reservations');
$app->cancelTask($created['id']);
check($app->db->task($created['id'])['state'] === 'cancelled', 'Cancellation persists before worker dispatch');
$created = $app->createTask($input, '127.0.0.1');
$app->db->execute('UPDATE tasks SET due_at=? WHERE id=?', [time() - 1, $created['id']]);
$worker->tick();
check($app->db->task($created['id'])['state'] === 'running', 'Worker resumes serialized policy and uploads exactly once');
check(!$worker->tick(), 'Timeline wait yields the worker until the finish time');
flock($lock, LOCK_UN);
fclose($lock);
check(Crypto::decrypt('NEW_GAME', Crypto::encrypt('NEW_GAME', '中文/route')) === '中文/route', 'Protocol envelope round trip');
echo $passed." checks passed. All remote calls used fixtures.\n";
unset($worker, $app);
foreach (glob($directory.'/*') as $file) unlink($file);
rmdir($directory);
