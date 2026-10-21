<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface ClosedTimeEventWriterInterface
{
    public function add(int $ticket_id, string $source_chat_id, \DateTimeImmutable $started_at, \DateTimeImmutable $ended_at): void;
}
