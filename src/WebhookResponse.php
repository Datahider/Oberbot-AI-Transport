<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class WebhookResponse
{
    public function __construct(
        public readonly int $status_code,
        public readonly string $body = ''
    ) {
    }
}
