<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class TurnState
{
    public function __construct(
        public readonly string $opaque,
        public readonly int $ticket_id,
        public readonly string $source_chat_id,
        public readonly \DateTimeImmutable $started_at,
        public readonly \DateTimeImmutable $last_heartbeat_at
    ) {}

    public function withHeartbeat(\DateTimeImmutable $at): self
    {
        return new self($this->opaque, $this->ticket_id, $this->source_chat_id, $this->started_at, $at);
    }
}
