<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
date_default_timezone_set('Asia/Shanghai');
umask(0077);
$directory = getenv('LEPAO_DATA_DIR') ?: __DIR__.'/data';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create application data directory');
}
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', $directory.'/application.log');
return new AutoLepao\App\Application($directory);
