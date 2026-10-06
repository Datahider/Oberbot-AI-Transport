<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface StatusMessageResetInterface
{
    public function resetForChat(string $chat_id): void;
}
