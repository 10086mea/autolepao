<?php

declare(strict_types=1);

namespace AutoLepao\Support;

/** cURL's native cookie engine owns domain, path, expiry and secure-cookie rules. */
final class CookieJar
{
    public function __construct(private array $lines = [])
    {
    }

    public function import(\CurlHandle $curl): void
    {
        curl_setopt($curl, CURLOPT_COOKIEFILE, '');
        foreach ($this->lines as $line) {
            curl_setopt($curl, CURLOPT_COOKIELIST, $line);
        }
    }

    public function collect(\CurlHandle $curl): void
    {
        $this->lines = curl_getinfo($curl, CURLINFO_COOKIELIST) ?: [];
    }

    public function value(string $name): ?string
    {
        foreach (array_reverse($this->lines) as $line) {
            $parts = explode("\t", $line, 7);
            if (count($parts) === 7 && $parts[5] === $name) {
                return $parts[6];
            }
        }
        return null;
    }

    public function export(): array
    {
        return $this->lines;
    }
}
