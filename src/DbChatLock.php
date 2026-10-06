<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use losthost\DB\DB;
use RuntimeException;

final class DbChatLock implements ChatLockInterface
{
    public function __construct(private int $timeout_seconds = 5) {}

    public function synchronized(string $key, callable $callback): mixed
    {
        if (!DB::getLock($key, $this->timeout_seconds)) {
            throw new RuntimeException('Cannot acquire Telegram chat lock');
        }
        try {
            return $callback();
        } finally {
            DB::releaseLock($key);
        }
    }
}
