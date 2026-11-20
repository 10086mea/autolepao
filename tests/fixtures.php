<?php
use AutoLepao\Core\{Crypto,Problem};
use AutoLepao\Support\Http;
final class FixtureHttp extends Http
{
    public array $calls = [];
    public bool $failSubmit = false;
    public string $profileMode = 'valid';
    public array $records = [];
    public function localIp(): string { return '192.0.2.20'; }
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
            'allRecordList' => ['status' => 1, 'data' => ['list' => ($body['page'] ?? '1') === '1' ? $this->records : []]],
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

final class QqFixtureHttp extends Http
{
    public function request(string $method, string $url, array $options = []): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        $body = ''; $headers = [];
        if (str_ends_with($path, '/m_authorize')) {
            $context = ['appid' => '716027609', 'daid' => '383', 'pt_3rd_aid' => '1105660458',
                'h5sig' => 'fixture-h5', 's_url' => 'https://connect.qq.com/login_success', 'style' => '35'];
            $body = '<iframe src="'.htmlspecialchars('https://xui.ptlogin2.qq.com/cgi-bin/xlogin?'.http_build_query($context), ENT_QUOTES).'"></iframe>';
        } elseif ($path === '/cgi-bin/xlogin' || $path === '/ssl/ptqrshow') {
            $curl = curl_init();
            $jar = $options['cookies'];
            $jar->import($curl);
            $name = $path === '/cgi-bin/xlogin' ? 'pt_login_sig' : 'qrsig';
            curl_setopt($curl, CURLOPT_COOKIELIST, ".ptlogin2.qq.com\tTRUE\t/\tTRUE\t0\t".$name."\tfixture-cookie");
            $jar->collect($curl);
            curl_close($curl);
            $body = $path === '/ssl/ptqrshow' ? "\x89PNG\r\n\x1a\nfixture" : 'login';
        } elseif ($path === '/ssl/ptqrlogin') {
            $body = "ptuiCB('0','0','https://graph.qq.com/success','0','accepted','');";
        } elseif ($path === '/success') {
            $body = "location.href = 'auth://tauth.qq.com/#openid=fixture-qq&access_token=fixture-qq-token';";
        } elseif ($path === '/oauth2.0/me') {
            $body = json_encode(['client_id' => '1105660458', 'openid' => 'fixture-qq']);
        } elseif ($path === '/user/get_simple_userinfo') {
            $body = json_encode(['ret' => 0, 'nickname' => 'Fixture QQ']);
        } else throw new RuntimeException('Unexpected QQ fixture path');
        return ['status' => 200, 'body' => $body, 'headers' => $headers];
    }
}

