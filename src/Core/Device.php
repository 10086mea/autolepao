<?php

declare(strict_types=1);

namespace AutoLepao\Core;

final class Device
{
    public const FIELDS = ['mobileModel','mobileDeviceId','mobileOsVersion'];
    public static function models(): array
    {
        $text = @file_get_contents(__DIR__.'/../../resources/android-devices.txt');
        if ($text === false) {
            throw new Problem('设备型号列表读取失败', 500);
        }
        return self::parseModels($text);
    }
    public static function parseModels(string $text): array
    {
        $models = [];
        $seen = [];
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $fields = array_map('trim', explode("\t", $line));
            if (count($fields) !== 3) {
                throw new Problem('设备型号列表应为三列：型号代码、名称、Android 版本', 500);
            }
            [$model,$name,$android] = $fields;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._()+ -]{0,127}$/D', $model) || $name === '' || strlen($name) > 128 || preg_match('/[\x00-\x1f\x7f]/', $name) || !preg_match('/^[1-9][0-9]?(?:\.[0-9]{1,2})?$/D', $android) || isset($seen[$model])) {
                throw new Problem('设备型号列表包含无效或重复的配置', 500);
            }
            $seen[$model] = true;
            $models[] = ['model' => $model,'name' => $name,'android' => $android];
        }
        if (!$models) {
            throw new Problem('设备型号列表不能为空', 500);
        }
        return $models;
    }
    public static function withRandomModel(array $profile): array
    {
        $models = self::models();
        $model = $models[random_int(0, count($models) - 1)];
        return array_merge($profile, ['mobileModel' => $model['model'],'mobileOsVersion' => $model['android'],
            'device_name' => $model['name'],'model_source' => 'catalog_random','catalog_version' => 1,'updated_at' => microtime(true)]);
    }
    public static function upgrade(array $profile): array
    {
        if (!$profile || ($profile['source'] ?? 'legacy') === 'legacy') {
            return self::generated();
        }
        if (($profile['source'] ?? '') === 'generated' && !isset($profile['catalog_version'])) {
            return self::withRandomModel($profile);
        }
        return $profile;
    }
    public static function legacy(): array
    {
        return ['mobileModel' => '2206123SC','mobileDeviceId' => '00000000-0000-4000-8000-000000000000','mobileOsVersion' => '13','ostype' => '1','source' => 'legacy','updated_at' => null];
    }
    public static function generated(): array
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $id = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
        return self::withRandomModel(array_merge(self::legacy(), ['mobileDeviceId' => $id,'source' => 'generated','updated_at' => microtime(true)]));
    }
    public static function read(array $credentials): array
    {
        return $credentials['device_profile'] ?? self::legacy();
    }
    public static function validate(array $input): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new Problem('请填写有效的手机型号、设备 ID 和 Android 版本');
            }
            $out[$field] = trim($value);
        }
        return $out + ['ostype' => '1','source' => 'manual','updated_at' => microtime(true)];
    }
    public static function configuredParams(array $device): array
    {
        return array_intersect_key($device, array_flip([...self::FIELDS,'ostype']));
    }
    public static function forLogin(array $device): array
    {
        unset($device['session_device']);
        return $device;
    }
    public static function pending(array $device): bool
    {
        return isset($device['session_device']) && self::configuredParams($device) !== self::configuredParams($device['session_device']);
    }
    public static function params(array $credentials): array
    {
        $device = self::read($credentials);
        return self::configuredParams($device['session_device'] ?? $device);
    }
}
