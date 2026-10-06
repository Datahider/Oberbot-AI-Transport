<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface ChatLockInterface
{
    public function synchronized(string $key, callable $callback): mixed;
}
