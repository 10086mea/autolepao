<?php

declare(strict_types=1);

namespace AutoLepao\Support;

final class Wakeup
{
    public static function address(): string
    {
        return 'udp://127.0.0.1:'.(getenv('LEPAO_WAKE_PORT') ?: '18901');
    }

    public static function notify(): void
    {
        $socket = @stream_socket_client(self::address(), $code, $message, .2);
        if ($socket) {
            fwrite($socket, 'changed');
            fclose($socket);
        }
    }
}
