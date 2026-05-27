<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class OutboundEvent
{
    /**
     * @param list<array<string, mixed>> $attachments
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly int $event_id,
        public readonly string $opaque,
        public readonly string $kind,
        public readonly string $text,
        public readonly array $attachments,
        public readonly array $meta
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            event_id: (int) ($payload['event_id'] ?? 0),
            opaque: trim((string) ($payload['opaque'] ?? '')),
            kind: trim((string) ($payload['kind'] ?? '')),
            text: (string) ($payload['text'] ?? ''),
            attachments: is_array($payload['attachments'] ?? null) ? array_values($payload['attachments']) : [],
            meta: is_array($payload['meta'] ?? null) ? $payload['meta'] : []
        );
    }
}
