<?php
declare(strict_types=1);

require __DIR__.'/autoload.php';

define('LEPAO_HOME', Phar::running(false) ? dirname(Phar::running(false)) : __DIR__);
umask(0077);
try {
    exit((new AutoLepao\Console())->main(array_slice($argv, 1)));
} catch (AutoLepao\Core\Problem $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    error_log('Unexpected error '.get_class($error).' code='.$error->getCode());
    fwrite(STDERR, '运行未完成，请查看 data/application.log。'.PHP_EOL);
    exit(1);
}
