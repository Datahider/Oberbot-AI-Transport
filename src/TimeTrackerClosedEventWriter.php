<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use losthost\timetracker\Timer;

final class TimeTrackerClosedEventWriter implements ClosedTimeEventWriterInterface
{
    public function __construct(private string $subject) {}
    public function add(int $ticket_id, string $source_chat_id, \DateTimeImmutable $started_at, \DateTimeImmutable $ended_at): void
    {
        (new Timer($this->subject))->add($ticket_id, $source_chat_id, $started_at, $ended_at, 'Codex AI turn', []);
    }
}
