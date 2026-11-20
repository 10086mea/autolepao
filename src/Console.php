<?php

declare(strict_types=1);

namespace AutoLepao;

use AutoLepao\Core\{Crypto, Device, Problem, Qq, Rules, Wechat};
use AutoLepao\Support\Http;

final class Console
{
    public const VERSION = '1.0.0';
    private State $state;
    private Personal $personal;
    private Http $http;

    public function main(array $args): int
    {
        $command = 'run';
        if ($args && !str_starts_with($args[0], '--')) {
            $command = array_shift($args);
        }
        if (in_array('--version', $args, true)) {
            echo 'AutoLepao '.self::VERSION.PHP_EOL;
            return 0;
        }
        if ($command === 'help' || in_array('--help', $args, true)) {
            $this->help();
            return 0;
        }
        $options = $this->arguments($args);
        if (!in_array($command, ['run','login','setup','status','logout','clear'], true)) {
            throw new Problem('未知命令。执行 --help 查看用法');
        }
        foreach (['curl','openssl','simplexml'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new Problem('缺少 PHP 扩展 '.$extension.'，Windows 用户请下载 Releases 中的完整运行包');
            }
        }
        $directory = getenv('LEPAO_DATA_DIR') ?: LEPAO_HOME.'/data';
        $this->state = new State($directory);
        ini_set('log_errors', '1');
        ini_set('display_errors', '0');
        ini_set('error_log', $directory.'/application.log');
        $this->http = new Http();
        $this->personal = new Personal($this->state, $this->http);
        echo 'AutoLepao '.self::VERSION.PHP_EOL;
        if ($command === 'login') {
            $this->login();
            return 0;
        }
        if ($command === 'logout') {
            if ($this->state->get('pending')) {
                throw new Problem('还有未确认的任务，请先执行 status');
            }
            $this->state->set('account', null);
            echo '已退出账号。'.PHP_EOL;
            return 0;
        }
        if (!$this->state->get('account')) {
            $this->login();
        }
        if ($command === 'clear') {
            $pending = $this->state->get('pending');
            if (!$pending || in_array($pending['phase'], ['running','preparing'], true)) {
                throw new Problem('当前没有可清除的待确认提交');
            }
            if ($this->ask('请先在乐跑 App 核对。确认本次没有成绩后输入 CLEAR') !== 'CLEAR') {
                throw new Problem('未清除');
            }
            $this->state->set('pending', null);
            echo '已清除待确认状态。'.PHP_EOL;
            return 0;
        }
        if ($command === 'status') {
            $this->status();
            return 0;
        }
        if ($command === 'setup' || !$this->state->get('route') || !$this->state->get('options')) {
            $this->setup($options['route'] ?? null);
        } elseif (isset($options['route'])) {
            $this->state->set('route', Route::load($options['route']));
        }
        if ($command === 'setup') {
            return 0;
        }
        if ($options['daily'] && isset($options['at'])) {
            throw new Problem('每日运行会在有效时段内自动安排，请去掉 --at');
        }
        do {
            if ($this->state->get('pending')) {
                $pending = $this->state->get('pending');
                if ($pending['phase'] === 'running') {
                    $this->finish();
                    if (!$options['daily']) {
                        return 0;
                    }
                } elseif (!$this->personal->reconcile()) {
                    throw new Problem('上次提交结果尚未确认。执行 status，或在乐跑 App 核对后执行 clear');
                }
            }
            $account = $this->personal->profile();
            $overview = $this->personal->policy->overview($account);
            $stats = $overview['semester'];
            echo ($account['nickname'] ?? $account['uid']).' · 乐跑 ID '.$account['uid'].' · 本学期 '.$stats['count'].' 次'.PHP_EOL;
            if ($stats['remaining'] === 0) {
                echo '本学期目标已完成。'.PHP_EOL;
                return 0;
            }
            $records = $this->personal->recordsToday();
            $existing = array_filter($records, fn ($record) => (string) ($record['record_status'] ?? '') !== '0');
            if ($existing) {
                echo '今天已有有效或待判定记录。'.PHP_EOL;
                if (!$options['daily']) {
                    return 0;
                }
                $this->waitUntil(strtotime('tomorrow') + 60);
                continue;
            }
            $requested = null;
            if (isset($options['at'])) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $options['at'], new \DateTimeZone('Asia/Shanghai'));
                if (!$date || $date->format('Y-m-d H:i') !== $options['at']) {
                    throw new Problem('--at 请填写为 "年-月-日 时:分"，使用北京时间');
                }
                $requested = (float) $date->getTimestamp();
            }
            $mode = $requested !== null ? 'fixed' : ($options['daily'] ? 'random' : 'earliest');
            $plan = $this->personal->plan($mode, $requested);
            echo '本次 '.$plan['config']['distance_km'].' km · 配速 '.$plan['config']['pace_s_per_km'].' 秒/公里'.PHP_EOL;
            echo '打卡点：'.implode('、', array_map(fn ($point) => $point['name'] ?: $point['id'], $plan['config']['assigned_checkpoints'])).PHP_EOL;
            $this->waitUntil((int) ceil($plan['slot']['start_at']));
            $this->personal->begin($plan);
            echo '轨迹已上传。'.PHP_EOL;
            $this->finish();
            if ($options['daily']) {
                $this->waitUntil(strtotime('tomorrow') + 60);
            }
        } while ($options['daily']);
        return 0;
    }

    private function arguments(array $args): array
    {
        $options = ['daily' => false];
        while ($args) {
            $flag = array_shift($args);
            if ($flag === '--daily') {
                $options['daily'] = true;
            } elseif (in_array($flag, ['--route','--at'], true) && $args) {
                $options[substr($flag, 2)] = array_shift($args);
            } else {
                throw new Problem('参数无效：'.$flag.'。执行 --help 查看用法');
            }
        }
        return $options;
    }

    private function ask(string $question, string $default = ''): string
    {
        fwrite(STDOUT, $question.($default !== '' ? ' ['.$default.']' : '').'：');
        $line = fgets(STDIN);
        if ($line === false) {
            throw new Problem('首次配置需要交互终端，请运行程序后按提示操作');
        }
        $value = trim($line);
        return $value === '' ? $default : $value;
    }

    private function login(): void
    {
        $pending = $this->state->get('pending');
        if ($pending && in_array($pending['phase'], ['preparing','running'], true)) {
            throw new Problem('请先恢复尚未结束的时间线，再重新登录');
        }
        $provider = $this->ask('登录方式：1 QQ，2 微信', '1');
        if (!in_array($provider, ['1','2'], true)) {
            throw new Problem('请选择1或2');
        }
        $provider = $provider === '1' ? 'qq' : 'wechat';
        $box = $this->state;
        $qq = new Qq($this->http, $box);
        $wechat = new Wechat($this->http, $box);
        if ($provider === 'wechat' && empty($box->settings()['app_secret'])) {
            echo '微信扫码需要移动应用的授权配置。'.PHP_EOL;
            $id = $this->ask('微信应用 ID', 'wx5a2e1ff396785475');
            $secret = $this->ask('微信应用 Secret');
            if (!preg_match('/^wx[a-zA-Z0-9]{10,32}$/D', $id) || !preg_match('/^[a-zA-Z0-9]{16,128}$/D', $secret)) {
                throw new Problem('微信授权配置格式无效');
            }
            $this->state->set('wechat', ['app_id' => $id, 'app_secret' => $secret]);
        }
        $qr = $provider === 'qq' ? $qq->start() : $wechat->start();
        $image = base64_decode($qr['image'], true);
        if ($image === false || !preg_match('/^(?:\x89PNG\r\n\x1a\n|\xff\xd8|GIF8)/', $image)) {
            throw new Problem('登录服务未返回二维码图片');
        }
        $file = $this->state->directory.'/login.png';
        file_put_contents($file, $image, LOCK_EX);
        chmod($file, 0600);
        echo '请使用'.($provider === 'qq' ? 'QQ' : '微信').'扫码并在手机上确认。二维码文件：'.$file.PHP_EOL;
        $this->openImage($file);
        try {
            $grant = null;
            while (time() < $qr['expires']) {
                if ($provider === 'qq') {
                    $poll = $qq->poll($qr);
                    $qr = $poll['qr'];
                    if ($poll['state'] === 'ok') {
                        $grant = $qq->exchange($qr, $poll['sig_url']);
                        break;
                    }
                    if ($poll['state'] === 'expired') {
                        break;
                    }
                } else {
                    $poll = $wechat->poll($qr['uuid']);
                    $code = (int) ($poll['wx_errcode'] ?? -500);
                    if ($code === 405) {
                        $grant = $wechat->exchange($poll['wx_code']);
                        break;
                    }
                    if (in_array($code, [402,403], true)) {
                        break;
                    }
                    if (!in_array($code, [404,408], true)) {
                        throw new Problem('微信扫码未完成，状态码 '.$code);
                    }
                }
                sleep(2);
            }
            if (!$grant) {
                throw new Problem('二维码已过期或授权已取消，请重新执行 login');
            }
            $grant['provider'] = $provider;
            $previous = $this->state->get('account', []);
            $device = ($previous['openid'] ?? null) === $grant['openid'] && ($previous['provider'] ?? null) === $provider
                ? $previous['device_profile'] : Device::generated();
            $data = $this->personal->api->login($grant, $device);
            if ($pending && (string) $data['uid'] !== (string) ($pending['context']['uid'] ?? '')) {
                throw new Problem('请扫描待核对任务所属的账号，完成成绩核对后再切换账号');
            }
            $account = ['uid' => (string) $data['uid'], 'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? '', 'provider' => $provider,
                'openid' => $grant['openid'], 'device_profile' => $device,
                $provider === 'qq' ? 'qq_token' : 'wechat_token' => $grant['access_token']];
            try {
                $profile = $provider === 'qq' ? $qq->profile($account) : $wechat->profile($account);
            } catch (\Throwable) {
                $profile = [];
            }
            $account['nickname'] = (string) ($profile['nickname'] ?? $data['name'] ?? $account['uid']);
            if (($previous['uid'] ?? null) !== $account['uid']) {
                $this->state->set('options', null);
                $this->state->set('last_result', null);
            }
            $this->state->set('account', $account);
            echo '登录成功：'.$account['nickname'].' · '.$account['uid'].PHP_EOL;
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function setup(?string $file): void
    {
        $account = $this->personal->profile();
        $overview = $this->personal->policy->overview($account);
        if ($file || !$this->state->get('route')) {
            $file ??= $this->ask('将 GPX 或 GeoJSON 路线文件拖入终端，然后回车');
            $this->state->set('route', Route::load($file));
        } elseif ($this->ask('继续使用 '.$this->state->get('route')['name'].'？y/n', 'y') !== 'y') {
            $this->state->set('route', Route::load($this->ask('路线文件')));
        }
        $zones = $overview['zones'];
        if (!$zones) {
            $zones = [['id' => $overview['default_zone'], 'name' => '当前跑区']];
        }
        foreach ($zones as $index => $zone) {
            echo ($index + 1).'. '.($zone['name'] ?? $zone['id']).PHP_EOL;
        }
        $selection = (int) Rules::number($this->ask('选择跑区', '1'), '跑区序号', 1, count($zones));
        $zone = (string) $zones[$selection - 1]['id'];
        $policy = $this->personal->policy->fetch($account, ['school_id' => $account['school_id'], 'game_id' => $zone]);
        $window = $policy['rules']['windows'][0];
        $defaultDistance = min($window['max_distance'], max(.2, round($window['min_distance'] * 1.05, 2)));
        $defaultPace = (int) max($window['fastest_pace'], min(360, $window['slowest_pace']));
        echo '当前规则：'.$window['min_distance'].'–'.$window['max_distance'].' km，配速 '.$window['fastest_pace'].'–'.$window['slowest_pace'].' 秒/公里'.PHP_EOL;
        $distance = Rules::number($this->ask('每次里程 / km', (string) $defaultDistance), '里程', .2, 100);
        $pace = Rules::number($this->ask('配速 / 秒每公里', (string) $defaultPace), '配速', 60, 3600);
        $this->state->set('options', ['game_id' => $zone, 'distance_km' => $distance, 'pace_s_per_km' => $pace]);
        $this->personal->plan();
        echo '路线与参数已保存。'.PHP_EOL;
    }

    private function status(): void
    {
        $account = $this->personal->profile();
        $stats = $this->personal->policy->overview($account)['semester'];
        echo ($account['nickname'] ?? $account['uid']).' · '.$account['uid'].' · 学校 '.$account['school_id'].PHP_EOL;
        echo '本学期已跑 '.$stats['count'].' 次'.($stats['target'] === null ? '' : '，目标 '.$stats['target'].' 次').PHP_EOL;
        $pending = $this->state->get('pending');
        if ($pending && $pending['phase'] !== 'running') {
            echo $this->personal->reconcile() ? '已核对上次结果。'.PHP_EOL : '上次提交结果未确认，请先在乐跑 App 核对。'.PHP_EOL;
        } elseif ($pending) {
            echo '时间线待恢复，预计提交：'.date('Y-m-d H:i:s', $pending['context']['resume_at']).PHP_EOL;
        }
        if ($result = $this->state->get('last_result')) {
            $this->showResult($result);
        }
    }

    private function finish(): void
    {
        $context = $this->state->get('pending')['context'];
        $this->waitUntil($context['resume_at']);
        $this->showResult($this->personal->finish());
    }

    private function showResult(array $result): void
    {
        if ((string) $result['status'] !== '1') {
            throw new Problem('乐跑拒绝本次提交，请在 App 查看账号和记录状态');
        }
        $message = match ((string) ($result['record_status'] ?? '')) {
            '1' => '成绩有效', '0' => '成绩无效', default => '等待最终判定'
        };
        echo '已提交 · '.$message.($result['record_id'] ? ' · 记录 '.$result['record_id'] : '').PHP_EOL;
    }

    private function waitUntil(int $at): void
    {
        if ($at > time()) {
            echo '等待至北京时间 '.date('Y-m-d H:i:s', $at).'。保持程序运行即可。'.PHP_EOL;
        }
        while (($left = $at - time()) > 0) {
            sleep(min(60, $left));
        }
    }

    private function openImage(string $file): void
    {
        if (getenv('LEPAO_NO_OPEN')) {
            return;
        }
        if (PHP_OS_FAMILY === 'Linux' && !getenv('DISPLAY') && !getenv('WAYLAND_DISPLAY')) {
            return;
        }
        $command = match (PHP_OS_FAMILY) {
            'Windows' => ['explorer.exe', $file], 'Darwin' => ['open', $file], default => ['xdg-open', $file]
        };
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [['file', $null, 'r'], ['file', $null, 'a'], ['file', $null, 'a']], $pipes);
        if (is_resource($process)) {
            proc_close($process);
        }
    }

    private function help(): void
    {
        echo <<<'HELP'
AutoLepao — 自己账号的乐跑命令行工具

直接启动                 首次扫码与配置，之后执行一次
login                    重新扫码登录
setup                    更换路线、跑区、里程或配速
status                   查询学期次数和上次提交结果
logout                   退出当前账号
clear                    在 App 核对后清除待确认状态

--daily                  每天在有效时段内自动安排一次
--at "2026-12-01 14:00"   指定一次开始时间（北京时间）
--route "路线.gpx"       导入 GPX 或 GeoJSON 路线

Windows 下载包运行 lepao.cmd；源码运行 php lepao.php。

HELP;
    }
}
