<?php

declare(strict_types=1);

namespace AutoLepao\Core;

use AutoLepao\Support\Http;
use AutoLepao\Support\Diagnostics;

final class Lepao
{
    private const VERSION = '233';
    private const VERSION_NAME = '4.3.3';
    private const ENCRYPTED = ['Login/quickLoginV339','Login/RefreshToken','Run2/beforeRunV260','Run/stopRunV278','report/getOssSts'];
    public function __construct(private Http $http, private Diagnostics $logger)
    {
    }
    public function call(string $uri, array $credentials = [], array $extra = []): array
    {
        $params = $this->params($credentials, $extra);
        if ($uri === 'Login/quickLoginV339') {
            // APK 4.3.3 uses the same nonce-based field encoding for WeChat and QQ.
            foreach (['openid','accesstoken'] as $field) {
                $params[$field] = Crypto::qqField((string)$params[$field], $params['nonce']);
            }
        }
        $new = in_array($uri, self::ENCRYPTED, true);
        $params['sign'] = Crypto::sign($params, $new, $uri === 'Login/quickLoginV339');
        $body = $new ? ['key' => Crypto::encrypt('PARAMS_NEW_GAME', Crypto::json($params))] : $params;
        $response = $this->http->json('POST', 'https://api2.lptiyu.com/v3/api.php/'.$uri, ['form_params' => $body]);
        if (($response['is_encrypt'] ?? 0) == 1 && is_string($response['data'] ?? null)) {
            $response['data'] = json_decode(Crypto::decrypt('NEW_GAME', $response['data']), true, 512, JSON_THROW_ON_ERROR);
        }
        return $response;
    }
    public function params(array $credentials, array $extra = []): array
    {
        foreach (['uid','student_num','school_id'] as $key) {
            if (isset($credentials[$key],$extra[$key]) && (string)$credentials[$key] !== (string)$extra[$key]) {
                throw new Problem('请求中的账号资料与当前账号不一致', 409);
            }
        }
        $device = Device::params($credentials);
        foreach ($device as $key => $value) {
            if (isset($extra[$key]) && (string)$extra[$key] !== $value) {
                throw new Problem('请求中的设备资料与账号配置不一致', 409);
            }
        }
        $params = array_merge([
            'n' => '211',
            'school_id' => (string)($credentials['school_id'] ?? '-1'),'student_num' => (string)($credentials['student_num'] ?? ''),'uid' => (string)($credentials['uid'] ?? '1'),
            'token' => (string)($credentials['access_token'] ?? ''),
            'timestamp' => (string)time(),'nonce' => (string)random_int(100000, 999999),
        ], $device, $extra, ['version' => self::VERSION,'version_name' => self::VERSION_NAME]);
        // ea/h.java omits the token in a fresh, unauthenticated uid=1 request.
        if ($params['uid'] === '1' && $params['token'] === '') {
            unset($params['token']);
        }
        if ($params['student_num'] === '') {
            unset($params['student_num']);
        }
        return $params;
    }
    public static function studentProfile(array $response, string $uid): array
    {
        $health = Health::lepao($response);
        if ($health['status'] === 'invalid') {
            throw new Problem($health['message'], 409);
        }
        $d = $response['data'] ?? null;
        if ((string)($response['status'] ?? '') !== '1' || !is_array($d)) {
            throw new Problem('无法读取学籍资料，请检查乐跑登录和学生认证状态', 409);
        }
        if (!is_string($d['uid'] ?? null) && !is_int($d['uid'] ?? null)) {
            throw new Problem('学籍资料缺少账号标识', 409);
        }
        if ((string)$d['uid'] !== $uid) {
            throw new Problem('学籍资料与当前账号不一致', 409);
        }
        foreach (['student_num','school_id'] as $key) {
            if ((!is_string($d[$key] ?? null) && !is_int($d[$key] ?? null)) || trim((string)$d[$key]) === '' || (string)$d[$key] === '0') {
                throw new Problem('账号缺少学号或学校资料，请先在乐跑完成学生认证', 409);
            }
        }
        return ['student_num' => (string)$d['student_num'],'school_id' => (string)$d['school_id'],
            'name' => is_string($d['name'] ?? null) ? $d['name'] : '','profile_uid' => $uid,'profile_source' => 'Student/InfoV14','profile_checked_at' => microtime(true)];
    }
    public function profile(array $credentials): array
    {
        if (empty($credentials['uid']) || empty($credentials['access_token'])) {
            throw new Problem('账号登录信息不完整，请重新扫码', 409);
        }
        // Fetch with the account token; cached student details must not influence this lookup.
        $lookup = $credentials;
        unset($lookup['student_num'],$lookup['school_id']);
        return self::studentProfile($this->call('Student/InfoV14', $lookup), (string)$credentials['uid']);
    }
    public function login(array $wx, array $device): array
    {
        $provider = $wx['provider'] ?? 'wechat';
        if (!in_array($provider, ['wechat','qq'], true)) {
            throw new Problem('不支持的登录渠道');
        }
        $fields = ['openid' => $wx['openid'],'accesstoken' => $wx['access_token'],'type' => $provider === 'qq' ? '2' : '3','nick_name' => $wx['nickname'] ?? '','avatar_url' => $wx['avatar'] ?? ''];
        if ($provider === 'wechat' && is_string($wx['unionid'] ?? null) && $wx['unionid'] !== '') {
            $fields['unionid'] = $wx['unionid'];
        }
        $response = $this->call('Login/quickLoginV339', ['device_profile' => Device::forLogin($device)], $fields);
        $data = $response['data'] ?? [];
        $status = $response['status'] ?? null;
        if (!is_scalar($status) || (string)$status !== '1' || !is_array($data) || empty($data['uid']) || empty($data['access_token'])) {
            $context = [
                'provider' => $provider,
                'data_type' => get_debug_type($response['data'] ?? null),
                'has_uid' => is_array($data) && !empty($data['uid']),
                'has_token' => is_array($data) && !empty($data['access_token']),
            ];
            foreach (['status', 'code', 'errcode'] as $key) {
                $value = $response[$key] ?? null;
                if (is_scalar($value) && preg_match('/^-?[0-9]{1,6}$/D', (string)$value)) {
                    $context[$key] = (string)$value;
                }
            }
            // Only log diagnostic vocabulary. Free text can contain account IDs or credentials.
            $terms = [];
            foreach (['msg', 'message', 'error', 'errmsg', 'info', 'data'] as $key) {
                if (!is_string($response[$key] ?? null)) {
                    continue;
                }
                preg_match_all('/微信|授权|令牌|登录|登陆|认证|参数|签名|版本|升级|更新|客户端|设备|系统|服务|网络|账号|帐号|绑定|维护|频繁|限制|异常|失败|错误|过期|失效|非法|无效|拒绝|重试|稍后|openid|token|expired|invalid|denied/i', $response[$key], $matches);
                $terms = array_merge($terms, $matches[0]);
            }
            $context['upstream_terms'] = array_values(array_unique($terms));
            $this->logger->warning('AutoLepao QR login rejected', $context);
            throw new Problem('乐跑登录失败，请重新扫码', 409);
        }
        return $data;
    }
}
