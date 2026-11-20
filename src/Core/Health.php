<?php

declare(strict_types=1);

namespace AutoLepao\Core;

final class Health
{
    public static function snapshot(string $status, string $reason, string $message): array
    {
        return compact('status', 'reason', 'message') + ['checked_at' => microtime(true)];
    }
    public static function lepao(array $r): array
    {
        $status = (string)($r['status'] ?? '');
        if ($status === '1' && is_array($r['data'] ?? null)) {
            return self::snapshot('valid', 'accepted', '乐跑登录有效');
        }
        if ($status === '101') {
            return self::snapshot('invalid', 'expired', '乐跑登录已失效或设备参数与登录时不一致，请重新扫码绑定');
        }
        if ($status === '102') {
            return self::snapshot('invalid', 'kicked', '该账号已在其他手机登录，当前凭据已被下线，请重新扫码绑定');
        }
        $message = strtolower((string)($r['info'] ?? ''));
        foreach (['登录过期','登录已过期','登录失效','请重新登录','请重新登陆','未登录','token无效','token已失效','token已过期','invalid token','token expired'] as $text) {
            if ($status !== '1' && str_contains($message, $text)) {
                return self::snapshot('invalid', 'expired', '乐跑登录已失效，请重新扫码绑定');
            }
        }
        return self::snapshot('unknown', 'rejected', '乐跑未确认登录有效性，请重试检测');
    }
    public static function wechat(array $r): array
    {
        $code = (string)($r['errcode'] ?? '0');
        if (in_array($code, ['40001','40014','42001'], true)) {
            return self::snapshot('invalid', 'expired', '微信资料授权已失效，重新扫码可更新昵称');
        }
        if ($code === '0' && (!empty($r['openid']) || isset($r['nickname']))) {
            return self::snapshot('valid', 'accepted', '微信资料授权有效');
        }
        return self::snapshot('unknown', 'rejected', '微信资料接口未确认授权有效性');
    }
    public static function empty(): array
    {
        return ['status' => 'unknown','reason' => 'not_checked','message' => '尚未检测','checked_at' => 0];
    }
    public static function qq(array $r): array
    {
        if ((string)($r['ret'] ?? '') === '0' && isset($r['nickname'])) {
            return self::snapshot('valid', 'accepted', 'QQ 资料授权有效');
        }
        if (in_array((string)($r['ret'] ?? ''), ['100013','100014','100015','100016'], true)) {
            return self::snapshot('invalid', 'expired', 'QQ 资料授权已失效，重新扫码可更新昵称');
        }
        return self::snapshot('unknown', 'rejected', 'QQ 资料接口未确认授权有效性');
    }
    public static function account(array $health, string $provider = 'wechat'): array
    {
        $identity = $health['identity'] ?? $health[$provider] ?? $health['wechat'] ?? self::empty();
        // The wechat alias keeps older console clients working for either provider.
        return ['lepao' => $health['lepao'] ?? self::empty(),'identity' => $identity,'wechat' => $identity] + ($provider === 'qq' ? ['qq' => $identity] : []);
    }
}
