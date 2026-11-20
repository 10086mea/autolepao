<?php

declare(strict_types=1);

namespace AutoLepao\Support;

use Psr\Log\AbstractLogger;

final class Diagnostics extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        error_log(json_encode(['at' => gmdate('c'), 'level' => $level,
            'message' => (string) $message, 'context' => $context], JSON_UNESCAPED_UNICODE));
    }
}
