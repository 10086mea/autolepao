<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$output = $root.'/dist';
if (!is_dir($output)) mkdir($output, 0755, true);
$file = $output.'/lepao.phar';
if (is_file($file)) unlink($file);
$phar = new Phar($file);
$phar->startBuffering();
foreach (['lepao.php','autoload.php','LICENSE'] as $name) $phar->addFile($root.'/'.$name, $name);
foreach (['src','resources'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
        if ($entry->isFile()) {
            $name = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            $phar->addFile($entry->getPathname(), $name);
        }
    }
}
$phar->setStub("#!/usr/bin/env php\n<?php Phar::mapPhar('lepao.phar'); require 'phar://lepao.phar/lepao.php'; __HALT_COMPILER();");
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();
echo $file.PHP_EOL;
