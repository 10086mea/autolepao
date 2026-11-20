<?php

declare(strict_types=1);

namespace AutoLepao;

use AutoLepao\Core\Problem;

final class State
{
    private $lock;
    private array $data;

    public function __construct(public readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new Problem('无法创建数据目录');
        }
        $this->lock = fopen($directory.'/lock', 'c');
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new Problem('另一个乐跑进程正在使用此目录');
        }
        $file = $directory.'/state.json';
        $this->data = is_file($file) ? json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR) : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function seal(array $value): string
    {
        return base64_encode(json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function open(string $value): array
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new Problem('扫码会话无效，请重新登录');
        }
        return json_decode($decoded, true, 64, JSON_THROW_ON_ERROR);
    }

    public function settings(): array
    {
        $settings = $this->get('wechat', []);
        if ($value = getenv('LEPAO_WECHAT_APP_ID')) {
            $settings['app_id'] = $value;
        }
        if ($value = getenv('LEPAO_WECHAT_SECRET')) {
            $settings['app_secret'] = $value;
        }
        return $settings;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $file = tempnam($this->directory, 'state-');
        try {
            $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($file, $json, LOCK_EX) === false) {
                throw new Problem('无法写入账号数据');
            }
            chmod($file, 0600);
            if (!rename($file, $this->directory.'/state.json')) {
                throw new Problem('无法保存账号数据');
            }
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
