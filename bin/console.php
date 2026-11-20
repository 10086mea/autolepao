<?php
declare(strict_types=1);

/** @var AutoLepao\App\Application $app */
$app = require dirname(__DIR__).'/bootstrap.php';
$command = $argv[1] ?? 'help';
if ($command === 'worker') {
    (new AutoLepao\App\Worker($app))->run();
} elseif ($command === 'status') {
    echo json_encode(['worker' => $app->workerOnline(), 'tasks' => $app->db->all('SELECT state,COUNT(*) AS count FROM tasks GROUP BY state')], JSON_PRETTY_PRINT).PHP_EOL;
} elseif ($command === 'serve') {
    $url = getenv('LEPAO_URL') ?: 'http://127.0.0.1:8900';
    $host = parse_url($url, PHP_URL_HOST);
    $port = parse_url($url, PHP_URL_PORT) ?: 8900;
    $listen = getenv('LEPAO_LISTEN') ?: $host.':'.$port;
    $listenHost = parse_url('http://'.$listen, PHP_URL_HOST);
    if (!in_array($listenHost, ['127.0.0.1','localhost'], true) && !getenv('LEPAO_PASSWORD')) {
        throw new RuntimeException('Set LEPAO_PASSWORD before exposing the application');
    }
    $root = dirname(__DIR__);
    $options = [];
    // Preserve a command-line SQLite extension override on Windows installations.
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('Enable pdo_sqlite in php.ini');
    $loadedIni = php_ini_loaded_file();
    if ($loadedIni) $options = ['-c', $loadedIni];
    $extra = json_decode(getenv('LEPAO_PHP_OPTIONS') ?: '[]', true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($extra) || array_filter($extra, fn($value) => !is_string($value))) throw new RuntimeException('LEPAO_PHP_OPTIONS must be a JSON string array');
    $base = array_merge([PHP_BINARY], $options, $extra);
    $worker = proc_open([...$base, $root.'/bin/console.php', 'worker'], [STDIN, STDOUT, STDERR], $pipes, $root);
    $web = proc_open([...$base, '-S', $listen, '-t', $root.'/public', $root.'/public/router.php'], [STDIN, STDOUT, STDERR], $pipes, $root);
    if (!is_resource($worker) || !is_resource($web)) throw new RuntimeException('Could not start application processes');
    register_shutdown_function(function () use ($worker, $web) { proc_terminate($web); proc_terminate($worker); });
    echo 'Open '.$url.PHP_EOL;
    while (proc_get_status($worker)['running'] && proc_get_status($web)['running']) sleep(1);
    exit(1);
} else {
    echo "AutoLepao\n  php bin/console.php serve    Start the web app and scheduler\n  php bin/console.php worker   Run only the scheduler\n  php bin/console.php status   Show task counts\n";
}
