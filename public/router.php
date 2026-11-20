<?php
declare(strict_types=1);

use AutoLepao\Core\{Crypto, Problem};

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$static = ['/app.css','/app.js','/vendor/leaflet.js','/vendor/leaflet.css',
    '/vendor/images/layers.png','/vendor/images/layers-2x.png','/vendor/images/marker-icon.png',
    '/vendor/images/marker-icon-2x.png','/vendor/images/marker-shadow.png'];
if (in_array($path, $static, true) && PHP_SAPI === 'cli-server') return false;

/** @var AutoLepao\App\Application $app */
$app = require dirname(__DIR__).'/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'");

$password = getenv('LEPAO_PASSWORD') ?: '';
$expectedHost = parse_url(getenv('LEPAO_URL') ?: 'http://127.0.0.1:8900', PHP_URL_HOST);
$host = parse_url('http://'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
if (!in_array($host, [$expectedHost, '127.0.0.1','localhost','::1','[::1]'], true)) {
    http_response_code(403); exit('Unexpected host');
}
if (!$password && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1','::1'], true)) {
    http_response_code(403); exit('Set LEPAO_PASSWORD before allowing remote access');
}
session_save_path($app->directory);
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$authenticated = $password === '' || ($_SESSION['authenticated'] ?? '') === hash('sha256', $password);
if ($path === '/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $valid = hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? '')) && hash_equals($password, (string) ($_POST['password'] ?? ''));
    if ($valid) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = hash('sha256', $password);
        header('Location: /', true, 303); exit;
    }
    http_response_code(403);
}
if (!$authenticated) {
    if (str_starts_with($path, '/api/')) {
        http_response_code(401); header('Content-Type: application/json'); echo '{"error":"请先登录"}'; exit;
    }
    $csrf = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);
    require __DIR__.'/login.php'; exit;
}
if ($path === '/' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require __DIR__.'/index.html'; exit;
}

header('Content-Type: application/json; charset=utf-8');
try {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET','POST','DELETE'], true)) throw new Problem('请求方式不支持', 405);
    if ($method !== 'GET' && !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) throw new Problem('页面已过期，请刷新', 403);
    $csrf = $_SESSION['csrf'];
    // Long QR polls and upstream calls must not lock out other tabs.
    session_write_close();
    $body = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input', false, null, 0, 200001);
        if (strlen($raw) > 200000) throw new Problem('请求内容过大', 413);
        $body = json_decode($raw ?: '{}', true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($body)) throw new Problem('JSON 内容无效');
    }
    $result = match (true) {
        $path === '/api/state' && $method === 'GET' => ['csrf' => $csrf, 'accounts' => $app->accounts(),
            'tasks' => $app->tasks(), 'hosting' => $app->hosting(), 'routes' => $app->db->all('SELECT * FROM routes ORDER BY name'), 'settings' => $app->settings()],
        $path === '/api/settings' && $method === 'POST' => $app->saveSettings($body),
        $path === '/api/bindings' && $method === 'POST' => $app->startBinding((string) ($body['provider'] ?? 'wechat')),
        preg_match('#^/api/bindings/([a-f0-9]{32})$#D', $path, $m) && $method === 'GET' => $app->pollBinding($m[1]),
        preg_match('#^/api/accounts/([a-f0-9]{32})/check$#D', $path, $m) && $method === 'POST' => $app->checkAccount($m[1]),
        preg_match('#^/api/accounts/([a-f0-9]{32})/zone$#D', $path, $m) && $method === 'POST' => $app->zone($m[1], (string) ($body['game_id'] ?? '')),
        preg_match('#^/api/accounts/([a-f0-9]{32})$#D', $path, $m) && $method === 'DELETE' => $app->deleteAccount($m[1]),
        $path === '/api/preview' && $method === 'POST' => $app->plan($body),
        $path === '/api/tasks' && $method === 'POST' => $app->createTask($body, $_SERVER['REMOTE_ADDR']),
        $path === '/api/hosting' && $method === 'POST' => (new AutoLepao\App\Hosting($app))->start($body, $_SERVER['REMOTE_ADDR']),
        preg_match('#^/api/hosting/([a-f0-9]{32})/stop$#D', $path, $m) && $method === 'POST' => (new AutoLepao\App\Hosting($app))->stop($m[1]),
        preg_match('#^/api/tasks/([a-f0-9]{32})/cancel$#D', $path, $m) && $method === 'POST' => $app->cancelTask($m[1]),
        $path === '/api/routes' && $method === 'POST' => $app->saveRoute($body),
        default => throw new Problem('接口不存在', 404),
    };
    echo Crypto::json($result ?? ['ok' => true]);
} catch (Throwable $error) {
    $status = $error instanceof Problem ? $error->status : ($error instanceof JsonException ? 400 : 500);
    http_response_code($status);
    if ($status === 500) error_log('API error '.get_class($error).' code='.$error->getCode());
    echo Crypto::json(['error' => $error instanceof Problem ? $error->getMessage() : ($status === 400 ? 'JSON 内容无效' : '应用处理失败，请查看日志')]);
}
