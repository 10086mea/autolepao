<?php

declare(strict_types=1);

namespace AutoLepao\Support;

class Runtime
{
    public function now(): float
    {
        return microtime(true);
    }
}
