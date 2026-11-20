<?php

declare(strict_types=1);

namespace AutoLepao\Core;

use AutoLepao\Support\Http;
use AutoLepao\Support\SecretBox;

final class Wechat
{
    public function __construct(private Http $http, private SecretBox $box)
    {
    }
    private function config(): array
    {
        $c = $this->box->settings();
        $appid = $c['app_id'] ?? 'wx5a2e1ff396785475';
        $secret = $c['app_secret'] ?? '';
        if (!$secret) {
            throw new Problem('请先配置微信应用 ID 和 Secret', 409);
        }
        return [$appid,$secret];
    }
    private function get(string $path, array $query): array
    {
        return $this->http->json('GET', 'https://api.weixin.qq.com/'.$path, ['query' => $query]);
    }
    public function start(): array
    {
        [$appid,$secret] = $this->config();
        $file = $this->box->directory.'/wx-ticket.json';
        $lock = fopen($this->box->directory.'/wx-ticket.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $cache = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
            if (($cache['app_id'] ?? null) === $appid && ($cache['expires'] ?? 0) > time() + 60 && !empty($cache['ticket'])) {
                $ticket = $cache['ticket'];
            } else {
                $token = $this->get('cgi-bin/token', ['grant_type' => 'client_credential','appid' => $appid,'secret' => $secret]);
                if (empty($token['access_token'])) {
                    throw new Problem('微信应用授权失败，请检查后台配置', 502);
                }
                $result = $this->get('cgi-bin/ticket/getticket', ['access_token' => $token['access_token'],'type' => 2]);
                if (empty($result['ticket'])) {
                    throw new Problem('微信扫码授权暂不可用', 502);
                }
                $ticket = $result['ticket'];
                file_put_contents($file, Crypto::json(['app_id' => $appid,'ticket' => $ticket,'expires' => time() + 6900]), LOCK_EX);
                chmod($file, 0600);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $nonce = bin2hex(random_bytes(8));
        $timestamp = (string)time();
        $signature = sha1("appid=$appid&noncestr=$nonce&sdk_ticket=$ticket&timestamp=$timestamp");
        $result = $this->http->json('GET', 'https://open.weixin.qq.com/connect/sdk/qrconnect', ['query' => ['appid' => $appid,'noncestr' => $nonce,'timestamp' => $timestamp,'scope' => 'snsapi_userinfo','signature' => $signature]]);
        if (empty($result['uuid']) || empty($result['qrcode']['qrcodebase64'])) {
            throw new Problem('无法获取微信二维码，请重试', 502);
        }
        return ['uuid' => $result['uuid'],'image' => $result['qrcode']['qrcodebase64'],'expires' => time() + 300];
    }
    public function poll(string $uuid): array
    {
        return $this->http->json('GET', 'https://open.weixin.qq.com/connect/l/qrconnect', ['query' => ['f' => 'json','uuid' => $uuid],'timeout' => 10]);
    }
    public function exchange(string $code): array
    {
        [$appid,$secret] = $this->config();
        $result = $this->get('sns/oauth2/access_token', ['appid' => $appid,'secret' => $secret,'code' => $code,'grant_type' => 'authorization_code']);
        if (empty($result['openid']) || empty($result['access_token'])) {
            throw new Problem('微信扫码授权已失效，请重新扫码', 409);
        }
        return $result;
    }
    public function profile(array $credentials): array
    {
        if (empty($credentials['wechat_token']) || empty($credentials['openid'])) {
            return [];
        }
        return $this->get('sns/userinfo', ['access_token' => $credentials['wechat_token'],'openid' => $credentials['openid'],'lang' => 'zh_CN']);
    }
}
