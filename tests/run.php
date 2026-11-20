<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';

use AutoLepao\{Personal,Route,State};
use AutoLepao\Core\{Crypto,Device,Problem,Qq,Rules,Schedule};
use AutoLepao\Support\{CookieJar,Http,Runtime};

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
require __DIR__.'/fixtures.php';
$directory = sys_get_temp_dir().'/autolepao-cli-'.bin2hex(random_bytes(8));
$state = new State($directory);
rejects(fn() => new State($directory), 'Concurrent CLI process is rejected');
$device = Device::generated();
$state->set('account', ['uid' => '4242', 'access_token' => 'fixture-token', 'device_profile' => $device]);
$state->set('route', ['control_points' => [['a' => 30, 'o' => 110], ['a' => 30.001, 'o' => 110], ['a' => 30.001, 'o' => 110.001]], 'closed' => false]);
$state->set('options', ['game_id' => '7', 'distance_km' => .3, 'pace_s_per_km' => 360]);
$clock = new class extends Runtime {
    public float $value;
    public function now(): float { return $this->value; }
};
$clock->value = strtotime('today 12:00');
$http = new FixtureHttp();
$personal = new Personal($state, $http, $clock);
$plan = $personal->plan();
check($plan['config']['school_id'] === '999' && $plan['config']['term_id'] === '888', 'Account-specific school and term');
$route = Rules::route($plan['config']);
check(count($plan['config']['assigned_checkpoints']) === 1, 'Exactly the assigned checkpoint count');
check(abs(end($route)['distance_m'] - 300) < .05, 'Route preserves requested distance');
check(abs(end($route)['elapsed'] - 108) < .01, 'Route preserves requested duration');
check($route === Rules::route($plan['config']), 'Trajectory is deterministic within a run');
check(max(array_column($route, 'o')) <= 110.00100001, 'Drawn segments have no curve overshoot');
$personal->begin($plan);
check($state->get('pending')['phase'] === 'running', 'Upload persists a resumable timeline');
check($state->get('pending')['context']['params']['ip'] === '192.0.2.20', 'IP comes from the real transport connection');
rejects(fn() => $personal->finish(), 'Early submission rejected');
$clock->value = $state->get('pending')['context']['resume_at'];
$result = $personal->finish();
check($result['record_status'] === 1 && $state->get('pending') === null, 'Successful submission clears timeline');
$plan = $personal->plan();
$personal->begin($plan);
$clock->value = $state->get('pending')['context']['resume_at'];
$http->failSubmit = true;
rejects(fn() => $personal->finish(), 'Transport timeout is reported');
check($state->get('pending')['phase'] === 'uncertain', 'Ambiguous submission is retained');
$count = count($http->calls);
rejects(fn() => $personal->finish(), 'Ambiguous submission cannot be sent twice');
check(count($http->calls) === $count, 'Rejected retry makes no upstream call');
check(!$personal->reconcile(), 'Unavailable remote result does not clear uncertainty');
$start = $state->get('pending')['context']['start'];
$http->records = [['id' => '1002','uid' => '4242','start_time' => $start,'type' => '1','record_status' => '1']];
check($personal->reconcile() && $state->get('pending') === null, 'Remote matching start time resolves uncertainty');
check($state->get('last_result')['record_id'] === '1002', 'Reconciled record ID is retained');
$http->records = [];
$params = $personal->api->params($state->get('account'), ['version' => '216', 'version_name' => '4.1.6']);
check($params['version'] === '233' && $params['version_name'] === '4.3.3', 'Version cannot be overridden');
check($params['mobileDeviceId'] === $device['mobileDeviceId'], 'Device stays stable');
check(!isset($personal->api->params(['device_profile' => $device])['token']), 'Fresh login has no captured account token');
$personal->api->login(['provider' => 'wechat', 'openid' => 'fixture-openid', 'access_token' => 'fixture-wx-token','unionid' => 'fixture-unionid'], $device);
$login = end($http->calls)['params'];
check($login['unionid'] === 'fixture-unionid' && $login['type'] === '3', 'WeChat unionid and provider');
check($login['openid'] !== 'fixture-openid' && $login['accesstoken'] !== 'fixture-wx-token', 'Nonce-based login field encoding');
check(Crypto::decrypt('NEW_GAME',Crypto::encrypt('NEW_GAME','中文/route')) === '中文/route', 'Protocol encryption round trip');
check(Qq::parsePoll("ptuiCB('67','0','','0','scanned','');")['state'] === 'scanned', 'QQ poll parser');
check(!Qq::trusted('https://openmobile.qq.com.evil.example/callback'), 'QQ callback host allowlist');
check(Qq::grant('https://evil.example/#openid=x&access_token=y') === null, 'QQ rejects foreign grants');
check(Http::resolve('https://graph.qq.com/a/b','../c?x=1#done') === 'https://graph.qq.com/c?x=1#done', 'Relative QQ redirect resolution');
$jar = new CookieJar([".qq.com\tTRUE\t/\tTRUE\t0\tqrsig\tfixture-value"]);
check($jar->value('qrsig') === 'fixture-value', 'Native cookie jar lookup');
check((new CookieJar($jar->export()))->value('qrsig') === 'fixture-value', 'Cookie jar resumes QR state');
$policy = $plan['policy'];
$day = strtotime('today');
$policy['rules']['windows'][0]['end'] = 23*3600;
$policy['time_limits'] = [[['start' => 0,'end' => 23*3600]]];
check(Schedule::choose($policy,$plan['config'],'fixed',$day+22*3600+10,$day)['start_at'] > $day+22*3600, 'School-specific closing times');
rejects(fn() => Schedule::choose($policy,$plan['config'],'fixed',$day+23*3600-30,$day), 'Submission margin checked');
rejects(fn() => Schedule::choose($policy,$plan['config'],'fixed',$day-1,$day), 'Past reservation rejected');
$geo = $directory.'/route.geojson';
file_put_contents($geo,json_encode(['type' => 'LineString','coordinates' => [[110,30],[110,30.001],[110.001,30.001]]]));
$loaded = Route::load($geo);
check(count($loaded['control_points']) === 3 && !$loaded['closed'], 'GeoJSON route import');
check(abs($loaded['control_points'][0]['o'] - 110) > .001, 'Imported WGS84 converts to GCJ02');
$gpx = $directory.'/route.gpx';
file_put_contents($gpx,'<gpx xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg><trkpt lat="30" lon="110"/><trkpt lat="30.001" lon="110"/><trkpt lat="30.001" lon="110.001"/><trkpt lat="30" lon="110"/></trkseg></trk></gpx>');
check(Route::load($gpx)['closed'], 'GPX closed-loop detection');
file_put_contents($gpx,'<!DOCTYPE gpx [<!ENTITY secret SYSTEM "file:///not-read">]><gpx>&secret;</gpx>');
rejects(fn() => Route::load($gpx), 'GPX external entities are not expanded');
$bad = $state->get('route');
$state->set('route',['control_points' => [['a' => 31,'o' => 111],['a' => 31.001,'o' => 111]],'closed' => false]);
rejects(fn() => $personal->plan(), 'Checkpoint coverage checked before upload');
$state->set('route',$bad);
$http->profileMode = 'invalid';
rejects(fn() => $personal->profile(), 'Expired account stops execution');
$state->set('wechat', ['app_id' => 'wx0000000000000000','app_secret' => str_repeat('0',32)]);
$wx = new AutoLepao\Core\Wechat($http, $state);
$qr = $wx->start();
check($qr['uuid'] === 'fixture-uuid', 'WeChat QR entry uses local configuration');
$grant = $wx->exchange('fixture-code');
check($grant['openid'] === 'fixture-wx-openid', 'WeChat code exchange');
check($state->open($state->seal(['cookies' => $jar->export()]))['cookies'] === $jar->export(), 'QR cookie context round trip');
$qq = new Qq(new QqFixtureHttp(), $state);
$qr = $qq->start();
$poll = $qq->poll($qr);
check($poll['state'] === 'ok', 'QQ mobile QR start and polling retain native cookies');
$grant = $qq->exchange($poll['qr'], $poll['sig_url']);
check($grant['provider'] === 'qq' && $grant['openid'] === 'fixture-qq', 'QQ grant follows JavaScript redirects and verifies application identity');
$personal->api->login($grant, $device);
check(end($http->calls)['params']['type'] === '2', 'QQ grant reaches the signed Lepao login');
unset($wx,$qq,$personal,$state);
$state = new State($directory);
check($state->get('account')['device_profile']['mobileDeviceId'] === $device['mobileDeviceId'], 'JSON state survives process restart');
unset($state);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$previousDirectory = getcwd();
if (is_dir(dirname(PHP_BINARY))) chdir(dirname(PHP_BINARY));
$server = proc_open([basename(PHP_BINARY), '-S', $address, __DIR__.'/http.php'], [['file',$null,'r'],['file',$directory.'/http.log','a'],['file',$directory.'/http.log','a']], $pipes);
chdir($previousDirectory);
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @stream_socket_client('tcp://'.$address, $errno, $error, .1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(100000);
    }
    check($ready, 'Local transport fixture starts');
    $network = new Http();
    $jar = new CookieJar();
    $network->json('GET', 'http://'.$address.'/set', ['cookies' => $jar]);
    $response = $network->json('POST','http://'.$address.'/allowed', ['cookies' => new CookieJar($jar->export()), 'form_params' => ['text' => '中文&a=1']]);
    check($response['form']['text'] === '中文&a=1', 'Form encoding preserves UTF-8 and delimiters');
    check($response['cookies']['fixture'] === 'cookie-value', 'Resumed cookies are sent to matching paths');
    check($response['agent'] === 'okhttp/3.12.13', 'Transport retains the expected User-Agent');
    check($network->json('GET','http://'.$address.'/other', ['cookies' => $jar])['cookies'] === [], 'Cookie path scope is enforced');
    check($network->request('GET','http://'.$address.'/redirect')['status'] === 302, 'Authorization redirects are inspected before following');
    check($network->localIp() === '127.0.0.1', 'Local connection IP is captured by cURL');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
}
foreach (glob($directory.'/*') as $file) unlink($file);
rmdir($directory);
echo $passed." checks passed. Remote login and submissions used fixtures.\n";
