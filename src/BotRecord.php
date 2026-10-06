<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class BotRecord
{
    public function __construct(
        public readonly string $bot_key,
        public readonly int $telegram_user_id,
        public readonly string $username,
        public readonly string $token,
        public readonly string $webhook_secret,
        public readonly bool $is_default
    ) {
    }
}
