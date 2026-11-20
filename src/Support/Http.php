<?php

declare(strict_types=1);

namespace AutoLepao\Support;

use AutoLepao\Core\Problem;

class Http
{
    private string $localIp = '';

    public function localIp(): string
    {
        if (!filter_var($this->localIp, FILTER_VALIDATE_IP)) {
            throw new Problem('无法读取本机连接地址');
        }
        return $this->localIp;
    }

    public function request(string $method, string $url, array $options = []): array
    {
        if (!empty($options['query'])) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($options['query']);
        }
        $curl = curl_init($url);
        $headers = [];
        $requestHeaders = [];
        foreach ($options['headers'] ?? [] as $name => $value) {
            $requestHeaders[] = $name.': '.$value;
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? 6,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 20,
            CURLOPT_USERAGENT => 'okhttp/3.12.13',
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                }
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))][] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if (isset($options['form_params'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($options['form_params']));
        } elseif (isset($options['body'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $options['body']);
        }
        $root = defined('LEPAO_HOME') ? LEPAO_HOME : dirname(__DIR__, 2);
        $ca = $root.'/runtime/cacert.pem';
        if (is_file($ca)) {
            curl_setopt($curl, CURLOPT_CAINFO, $ca);
        }
        $cookies = $options['cookies'] ?? null;
        if ($cookies instanceof CookieJar) {
            $cookies->import($curl);
        }
        try {
            $body = curl_exec($curl);
            $errno = curl_errno($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $this->localIp = (string) curl_getinfo($curl, CURLINFO_LOCAL_IP);
            if ($cookies instanceof CookieJar) {
                $cookies->collect($curl);
            }
            if ($body === false) {
                error_log('HTTP failure '.json_encode(['host' => parse_url($url, PHP_URL_HOST),
                    'path' => parse_url($url, PHP_URL_PATH), 'method' => $method, 'curl_errno' => $errno]));
                throw new Problem('连接远端失败（网络错误 '.$errno.'），请稍后重试', 502);
            }
            return ['status' => $status, 'body' => $body, 'headers' => $headers];
        } finally {
            curl_close($curl);
        }
    }

    public function json(string $method, string $url, array $options = []): array
    {
        $result = $this->request($method, $url, $options);
        $data = json_decode($result['body'], true);
        if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($data)) {
            throw new Problem('远端接口返回异常（HTTP '.$result['status'].'）', 502);
        }
        return $data;
    }

    public static function resolve(string $base, string $target): string
    {
        if (parse_url($target, PHP_URL_SCHEME)) {
            return $target;
        }
        $b = parse_url($base);
        if (str_starts_with($target, '//')) {
            return $b['scheme'].':'.$target;
        }
        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($target, '?')) {
            return $origin.($b['path'] ?? '/').$target;
        }
        if (str_starts_with($target, '#')) {
            return preg_replace('/#.*$/', '', $base).$target;
        }
        $t = parse_url($target);
        $path = $t['path'] ?? '';
        if (!str_starts_with($path, '/')) {
            $path = substr($b['path'] ?? '/', 0, strrpos($b['path'] ?? '/', '/') + 1).$path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        return $origin.'/'.implode('/', $parts).(str_ends_with($path, '/') ? '/' : '')
            .(isset($t['query']) ? '?'.$t['query'] : '').(isset($t['fragment']) ? '#'.$t['fragment'] : '');
    }
}
