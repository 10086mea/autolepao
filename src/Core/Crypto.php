<?php

declare(strict_types=1);

namespace AutoLepao\Core;

final class Crypto
{
    private const ENVELOPES = ['NEW_GAME' => ['Wet2C8d34f62ndi3','K6iv85jBD8jgf32D'],'PARAMS_NEW_GAME' => ['goMEzJ1pDpncNHb9','ZUrAS2lUidjyY2gK']];
    private const QQ_FIELDS = [
        ['R7gT3pL9sF2kD5mQ','B8jN4wH6xJ2vK7rE'],['M5dS8fG3hJ7kL2pR','C9tY6rU3eW4qX8sZ'],
        ['P2bN7vF4cD9mK6jA','S3nH5gJ8dL2fT9wQ'],['X6rV9tB3yG5hN7kP','E4sW2qA8dZ6fC3jM'],
        ['K8jD3mF7nH2pL5rT','Q6wS4eX9cV2bN7gJ'],['F3dG7hJ2kL5pM8rN','Z9xC4vB6nA2sD7fH'],
        ['B5vN8cM3xZ6dF2gJ','H7kL4jP9mQ2rT5sW'],['N6mK3lJ7pO2iU5yT','G8hF4dS2fA6gH9jK'],
        ['V2bC7nM3vX6cZ9dF','J5kL8jH3gD7fS2dA'],['H9jG4fD7sA2dF5gH','K3lJ6pO9iU2yT5rE'],
    ];
    public static function qqField(string $value, string $nonce): string
    {
        [$key,$iv] = self::QQ_FIELDS[(int)$nonce % 10];
        $cipher = openssl_encrypt($value, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new Problem('QQ 登录字段编码失败', 500);
        }
        return base64_encode($cipher);
    }
    public static function encrypt(string $label, string $plain): string
    {
        [$key,$iv] = self::ENVELOPES[$label];
        $key = str_pad(substr($key, 0, 16), 16, "\0");
        $value = openssl_encrypt($plain, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($value === false) {
            throw new Problem('协议加密失败', 500);
        }
        return base64_encode($value);
    }
    public static function decrypt(string $label, string $blob): string
    {
        [$key,$iv] = self::ENVELOPES[$label];
        $value = openssl_decrypt((string)base64_decode($blob, true), 'aes-128-cbc', str_pad(substr($key, 0, 16), 16, "\0"), OPENSSL_RAW_DATA, $iv);
        if ($value === false) {
            throw new Problem('远端响应解密失败', 502);
        }
        return $value;
    }
    public static function sign(array $params, bool $new, bool $login = false): string
    {
        ksort($params, SORT_STRING);
        $text = $new ? 'eFoqdHOdDy6ViJ0i' : '';
        foreach ($params as $key => $value) {
            if (strtolower((string)$key) === 'file' || ($new && $key === 'mobileDeviceId') || ($new && $login && in_array($key, ['nick_name','avatar_url'], true))) {
                continue;
            }
            $text .= $key.(string)$value;
        }
        return md5($text.($new ? '' : 'rDJiNB9j7vD2'));
    }
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
