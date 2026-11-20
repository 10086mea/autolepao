<?php

declare(strict_types=1);

namespace AutoLepao\Support;

use GuzzleHttp\Client;
use AutoLepao\Core\Problem;

class Http
{
    public function request(string $method, string $url, array $options = []): array
    {
        $started = microtime(true);
        try {
            $response = (new Client())->request($method, $url, $options + [
                'timeout' => 20,'connect_timeout' => 6,'http_errors' => false,'allow_redirects' => false,
                'headers' => ['User-Agent' => 'okhttp/3.12.13'],
            ]);
            return ['status' => $response->getStatusCode(),'body' => (string)$response->getBody(),'headers' => $response->getHeaders()];
        } catch (\Throwable $e) {
            $context = $e instanceof \GuzzleHttp\Exception\RequestException || $e instanceof \GuzzleHttp\Exception\ConnectException ? $e->getHandlerContext() : [];
            // Never log query strings, request bodies, credentials or exception messages.
            error_log('AutoLepao transport '.json_encode(['host' => parse_url($url, PHP_URL_HOST),'path' => parse_url($url, PHP_URL_PATH),'method' => $method,'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),'exception' => get_class($e),'curl_errno' => $context['errno'] ?? null,'connect_time' => $context['connect_time'] ?? null,'total_time' => $context['total_time'] ?? null], JSON_UNESCAPED_SLASHES));
            throw new Problem('远端请求失败，请稍后重试', 502);
        }
    }
    public function json(string $method, string $url, array $options = []): array
    {
        $result = $this->request($method, $url, $options);
        $data = json_decode($result['body'], true);
        if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($data)) {
            throw new Problem('远端接口返回异常，请稍后重试', 502);
        }
        return $data;
    }
}
