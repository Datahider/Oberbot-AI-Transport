<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface TurnStateStoreInterface
{
    public function loadTurn(string $opaque): ?TurnState;
    public function saveTurn(TurnState $state): void;
    public function deleteTurn(string $opaque): void;

    /** @return list<TurnState> */
    public function timedOutBefore(\DateTimeImmutable $cutoff): array;
}
