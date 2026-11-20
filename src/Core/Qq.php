<?php

declare(strict_types=1);

namespace AutoLepao\Core;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\{Uri,UriResolver};
use AutoLepao\Support\{Http,SecretBox};

/** Mobile application QR authorization, not PC website OAuth. */
final class Qq
{
    public const APP_ID = '1105660458';
    public const CALLBACK = 'auth://tauth.qq.com/';
    private const PACKAGE = 'com.lptiyu.tanke';
    // MD5 of the signing certificate DER in the official 4.1.6 and 4.3.1 APKs.
    private const CERTIFICATE_MD5 = 'd6ab92f37e0bdbff74d08a64c19ea1d8';
    private const UA = 'Mozilla/5.0 (Linux; Android 11; Android Device) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
    private const HOSTS = ['openmobile.qq.com','graph.qq.com','xui.ptlogin2.qq.com','ssl.ptlogin2.qq.com','ptlogin2.qq.com','ptlogin2.graph.qq.com','ssl.ptlogin2.graph.qq.com','connect.qq.com'];

    public function __construct(private Http $http, private SecretBox $box)
    {
    }

    public static function hash33(string $value): int
    {
        $result = 0;
        foreach (str_split($value) as $char) {
            $result = ($result * 33 + ord($char)) & 0x7fffffff;
        }return $result;
    }
    public static function appSign(int $timestamp): string
    {
        // QQ Android SDK AuthAgent -> SystemUtils.getSignValidString.
        return md5(self::PACKAGE.'_'.self::CERTIFICATE_MD5.'_'.$timestamp);
    }
    public static function trusted(string $url, bool $callback = false): bool
    {
        $p = parse_url($url);
        if (!$p || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return false;
        }
        if ($callback && ($p['scheme'] ?? '') === 'auth') {
            return ($p['host'] ?? '') === 'tauth.qq.com';
        }
        // Mobile QR success returns credentials in this QQ-owned proxy fragment.
        // Recognize that callback without granting network access to arbitrary CDN paths.
        if ($callback && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === 'imgcache.qq.com' && ($p['path'] ?? '') === '/open/connect/widget/mobile/login/proxy.htm') {
            return true;
        }
        return in_array($p['scheme'] ?? '', ['https','http'], true) && in_array($p['host'] ?? '', self::HOSTS, true);
    }
    public static function grant(string $url): ?array
    {
        if (!self::trusted($url, true)) {
            return null;
        }
        $p = parse_url($url);
        parse_str($p['query'] ?? '', $q);
        parse_str($p['fragment'] ?? '', $f);
        $q = array_merge($q, $f);
        if (!is_string($q['openid'] ?? null) || !is_string($q['access_token'] ?? null) || $q['openid'] === '' || $q['access_token'] === '') {
            return null;
        }
        return array_intersect_key($q, array_flip(['openid','access_token','expires_in']));
    }
    public static function decodeUrl(string $value): string
    {
        return html_entity_decode(str_replace('\\/', '/', preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', fn ($m) => chr(hexdec($m[1])), $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    public static function parsePoll(string $body): array
    {
        // A quoted argument can itself contain parentheses (e.g. error(100044)).
        if (!preg_match('/\bptuiCB\((.*)\)\s*;?\s*$/s', $body, $m)) {
            throw new Problem('QQ 返回了无法识别的扫码状态', 409);
        }
        $parts = str_getcsv($m[1], ',', "'", '\\');
        $code = (string)($parts[0] ?? '');
        $state = match($code) {
            '66' => 'waiting','67' => 'scanned','65','68' => 'expired','0' => 'ok',default => null
        };
        if ($state === null) {
            $safeCode = preg_match('/^-?\d{1,9}$/D', $code) ? $code : 'unknown';
            $message = (string)($parts[4] ?? '');
            error_log('AutoLepao QQ poll '.json_encode(['code' => $safeCode,'arguments' => count($parts),'message_kind' => str_contains($message, '100044') ? 'app_signature' : 'upstream_rejected'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            if (str_contains($message, 'sign of app is error(100044)')) {
                throw new Problem('QQ 应用签名校验失败（100044），请重新获取二维码', 409);
            }
            throw new Problem('QQ 扫码未完成（状态 '.$safeCode.'），请重新获取二维码', 409);
        }
        return ['state' => $state,'sig_url' => $code === '0' ? ($parts[2] ?? '') : ''];
    }
    private function get(string $url, CookieJar $cookies, string $referer): array
    {
        if (!self::trusted($url) || !str_starts_with($url, 'https://')) {
            throw new Problem('QQ 授权跳转地址不可信', 409);
        }
        $r = $this->http->request('GET', $url, ['cookies' => $cookies,'allow_redirects' => false,
            'headers' => ['User-Agent' => self::UA,'Referer' => $referer,'Accept-Language' => 'zh-CN,zh;q=0.9']]);
        if ($r['status'] < 200 || $r['status'] >= 400) {
            throw new Problem('QQ 授权服务暂不可用，请稍后重试', 502);
        }
        return $r;
    }
    private function cookie(CookieJar $cookies, string $name): string
    {
        return (string)($cookies->getCookieByName($name)?->getValue() ?? '');
    }
    private function diagnostic(string $stage, array $data): void
    {
        error_log('AutoLepao QQ authorization failed at '.$stage);
    }
    public function start(): array
    {
        $timestamp = time();
        $auth = 'https://openmobile.qq.com/oauth2.0/m_authorize?'.http_build_query([
            'client_id' => self::APP_ID,'response_type' => 'token','redirect_uri' => self::CALLBACK,
            'display' => 'mobile','style' => 'qr','scope' => 'all','sdkp' => 'a','sdkv' => '3.5.11.lite',
            'pf' => 'openmobile_android','format' => 'json','switch' => '1',
            'time' => (string)$timestamp,'sign' => self::appSign($timestamp)], '', '&', PHP_QUERY_RFC3986);
        $cookies = new CookieJar();
        $r = $this->get($auth, $cookies, 'https://xui.ptlogin2.qq.com/');
        if (!preg_match('/src\s*=\s*"([^"]+)"/', $r['body'], $m)) {
            throw new Problem('QQ 未建立移动应用授权会话，请重试', 409);
        }
        $xlogin = self::decodeUrl($m[1]);
        $p = parse_url($xlogin);
        if (!self::trusted($xlogin) || ($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') !== 'xui.ptlogin2.qq.com' || ($p['path'] ?? '') !== '/cgi-bin/xlogin') {
            throw new Problem('QQ 返回的应用授权入口不可信', 409);
        }
        parse_str($p['query'] ?? '', $context);
        foreach (['appid','daid','pt_3rd_aid','h5sig','s_url','style'] as $key) {
            if (!is_string($context[$key] ?? null) || $context[$key] === '') {
                throw new Problem('QQ 应用授权上下文不完整', 409);
            }
        }
        if ($context['pt_3rd_aid'] !== self::APP_ID) {
            throw new Problem('QQ 应用授权与乐跑不匹配', 409);
        }
        $this->get($xlogin, $cookies, $auth);
        if (!$this->cookie($cookies, 'pt_login_sig')) {
            throw new Problem('QQ 未签发扫码票据，请重试', 409);
        }
        $r = $this->get('https://xui.ptlogin2.qq.com/ssl/ptqrshow?'.http_build_query([
            's' => '8','e' => '0','appid' => $context['appid'],'type' => '0','t' => (string)microtime(true),
            'daid' => $context['daid'],'pt_3rd_aid' => self::APP_ID,'u1' => $context['s_url']]), $cookies, $xlogin);
        if (!$this->cookie($cookies, 'qrsig') || !preg_match('/^(?:\x89PNG\r\n\x1a\n|\xff\xd8|GIF8)/', $r['body'])) {
            throw new Problem('QQ 未返回二维码图片，请重试', 409);
        }
        return ['provider' => 'qq','image' => base64_encode($r['body']),'expires' => time() + 150,
            'qq_context' => $this->box->seal(['xlogin' => $xlogin,'params' => $context,'cookies' => $cookies->toArray()])];
    }
    private function session(array $qr): array
    {
        if (($qr['expires'] ?? 0) <= time()) {
            throw new Problem('QQ 二维码已过期，请重新扫码', 409);
        }
        try {
            $c = $this->box->open($qr['qq_context']);
            $cookies = new CookieJar(false, $c['cookies']);
        } catch (\Throwable $e) {
            throw new Problem('QQ 扫码会话已失效，请重新扫码', 409);
        }
        return [$c,$cookies];
    }
    public function poll(array $qr): array
    {
        [$c,$cookies] = $this->session($qr);
        $p = $c['params'];
        $r = $this->get('https://xui.ptlogin2.qq.com/ssl/ptqrlogin?'.http_build_query([
            'u1' => $p['s_url'],'from_ui' => '1','type' => '1','ptlang' => '2052',
            'ptqrtoken' => (string)self::hash33($this->cookie($cookies, 'qrsig')),'daid' => $p['daid'],'aid' => $p['appid'],
            // QQ's standalone mobile page uses getFlexOpenData(), which appends
            // pt_flex=1 to the ORIGINAL query before encoding the outer request.
            'pt_3rd_aid' => self::APP_ID,'pt_openlogin_data' => parse_url($c['xlogin'], PHP_URL_QUERY).'&pt_flex=1',
            'device' => '2','ptopt' => '1','pt_uistyle' => $p['style'],'jsver' => 'v1.36.0',
            'login_sig' => $this->cookie($cookies, 'pt_login_sig'),'r' => (string)microtime(true)], '', '&', PHP_QUERY_RFC3986), $cookies, $c['xlogin']);
        $c['cookies'] = $cookies->toArray();
        $qr['qq_context'] = $this->box->seal($c);
        try {
            return self::parsePoll($r['body']) + ['qr' => $qr];
        } catch (Problem $e) {
            // Keep the complete upstream response in private storage for diagnosis.
            $this->diagnostic('poll', ['response' => $r['body']]);
            throw $e;
        }
    }
    public function exchange(array $qr, string $url): array
    {
        [$c,$cookies] = $this->session($qr);
        $seen = [];
        $initial = $url;
        $hops = [];
        try {
            for ($i = 0;$i < 10;$i++) {
                $hops[] = ['url' => $url,'trusted' => self::trusted($url, true),'repeated' => isset($seen[$url])];
                if (!self::trusted($url, true) || isset($seen[$url])) {
                    throw new Problem('QQ 授权跳转异常，请重新扫码', 409);
                }
                $seen[$url] = true;
                if ($grant = self::grant($url)) {
                    return $this->verify($grant);
                }
                if (str_starts_with($url, 'auth://')) {
                    break;
                }
                $url = preg_replace('/^http:/', 'https:', $url);
                $r = $this->get($url, $cookies, $c['xlogin']);
                $location = '';
                $hops[array_key_last($hops)]['response'] = $r;
                foreach ($r['headers'] ?? [] as $key => $value) {
                    if (strtolower($key) === 'location') {
                        $location = is_array($value) ? ($value[0] ?? '') : $value;
                    }
                }
                if (!$location && preg_match('/(?:location(?:\.href)?\s*=\s*|location\.(?:replace|assign)\(\s*)["\x27]([^"\x27]+)["\x27]/i', $r['body'], $m)) {
                    $location = $m[1];
                }
                if (!$location) {
                    break;
                }
                $url = (string)UriResolver::resolve(new Uri($url), new Uri(self::decodeUrl($location)));
            }
            throw new Problem('QQ 未返回乐跑应用授权结果，请重新扫码确认授权', 409);
        } catch (\Throwable $e) {
            // Keep the original callback and resumable encrypted context before
            // the API clears a failed binding. Full reports stay in private storage.
            $c['cookies'] = $cookies->toArray();
            $qr['qq_context'] = $this->box->seal($c);
            $this->diagnostic('exchange', ['initial_url' => $initial,'hops' => $hops,'qr' => $qr,'error' => $e->getMessage()]);
            throw $e;
        }
    }
    public function verify(array $grant): array
    {
        $r = $this->get('https://graph.qq.com/oauth2.0/me?'.http_build_query(['access_token' => $grant['access_token'],'fmt' => 'json']), new CookieJar(), 'https://graph.qq.com/');
        $identity = json_decode($r['body'], true);
        if (!is_array($identity) || isset($identity['error']) || (string)($identity['client_id'] ?? '') !== self::APP_ID || ($identity['openid'] ?? null) !== $grant['openid']) {
            throw new Problem('QQ 授权与乐跑应用不匹配或已失效，请重新扫码', 409);
        }
        return $grant + ['provider' => 'qq'];
    }
    public function profile(array $credentials): array
    {
        if (empty($credentials['qq_token']) || empty($credentials['openid'])) {
            return [];
        }
        $r = $this->get('https://graph.qq.com/user/get_simple_userinfo?'.http_build_query([
            'access_token' => $credentials['qq_token'],'openid' => $credentials['openid'],'oauth_consumer_key' => self::APP_ID,'format' => 'json']), new CookieJar(), 'https://graph.qq.com/');
        return json_decode($r['body'], true) ?: [];
    }
}
