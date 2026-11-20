<?php
declare(strict_types=1);

if (function_exists('sapi_windows_cp_set')) sapi_windows_cp_set(65001);

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'AutoLepao\\')) return;
    $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, 10)).'.php';
    if (is_file($file)) require $file;
});
date_default_timezone_set('Asia/Shanghai');
