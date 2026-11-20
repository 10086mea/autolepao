<?php

declare(strict_types=1);

namespace AutoLepao\Support;

/** Encoded QR state stays in the local database and is never sent to a browser. */
final class SecretBox
{
    public function __construct(public readonly string $directory)
    {
    }

    public function seal(array $data): string
    {
        return base64_encode(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function open(string $value): array
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid QR state');
        }
        return json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
    }

    public function settings(): array
    {
        $file = $this->directory.'/settings.json';
        $settings = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
        if ($value = getenv('LEPAO_WECHAT_APP_ID')) {
            $settings['app_id'] = $value;
        }
        if ($value = getenv('LEPAO_WECHAT_SECRET')) {
            $settings['app_secret'] = $value;
        }
        return $settings;
    }
}
