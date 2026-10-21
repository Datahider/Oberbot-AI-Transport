<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class TurnAccountingService
{
    public function __construct(
        private OberbotHandoffStoreInterface $handoffs,
        private TurnStateStoreInterface $turns,
        private ClosedTimeEventWriterInterface $writer,
        private OberbotAiConfig $config
    ) {}

    public function observe(OutboundEvent $event, ?\DateTimeImmutable $at = null): void
    {
        if (!in_array($event->kind, ['heartbeat', 'final'], true)) return;
        $handoff = $this->handoffs->findByOpaque($event->opaque);
        if ($handoff === null) return;
        $at ??= new \DateTimeImmutable();
        $state = $this->turns->loadTurn($event->opaque);

        if ($event->kind === 'heartbeat') {
            $this->turns->saveTurn($state === null
                ? new TurnState($event->opaque, $handoff->ticket_id, $handoff->source_chat_id, $at, $at)
                : $state->withHeartbeat($at));
            return;
        }

        if ($state !== null) $this->close($state, $at);
    }

    public function closeTimedOut(?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();
        $cutoff = $at->modify('-' . $this->config->turn_timeout_seconds . ' seconds');
        foreach ($this->turns->timedOutBefore($cutoff) as $state) {
            $this->close($state, $state->last_heartbeat_at->modify('+' . $this->config->heartbeat_coverage_seconds . ' seconds'));
        }
    }

    private function close(TurnState $state, \DateTimeImmutable $ended_at): void
    {
        $this->writer->add($state->ticket_id, $state->source_chat_id, $state->started_at, $ended_at);
        $this->turns->deleteTurn($state->opaque);
    }
}
