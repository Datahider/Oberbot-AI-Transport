<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface HandoverBotClientInterface
{
    /** @return array<string, mixed> */
    public function sendMessage(
        int|string $chat_id,
        string $text,
        ?int $reply_to_message_id = null,
        ?string $parse_mode = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array;

    public function leaveChat(int|string $chat_id): void;
}
