<?php

declare(strict_types=1);

namespace AutoLepao\Support;

final class Diagnostics
{
    public function warning(string $message, array $context = []): void
    {
        error_log(json_encode(['at' => gmdate('c'), 'message' => $message, 'context' => $context], JSON_UNESCAPED_UNICODE));
    }
}
