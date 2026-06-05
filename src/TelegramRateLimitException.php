<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use RuntimeException;

final class TelegramRateLimitException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retry_after_seconds
    ) {
        parent::__construct($message);
    }
}
