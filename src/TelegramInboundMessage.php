<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class TelegramInboundMessage
{
    /**
     * @param list<array<string, mixed>> $attachments
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $opaque,
        public readonly string $chat_id,
        public readonly ?int $thread_id,
        public readonly string $text,
        public readonly ?int $message_id,
        public readonly array $attachments,
        public readonly array $meta
    ) {
    }
}
