<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface OberbotHandoffStoreInterface
{
    public function findByTicketId(int $ticket_id): ?OberbotHandoffRecord;
    public function findByOpaque(string $opaque): ?OberbotHandoffRecord;
    public function saveHandoff(OberbotHandoffRecord $record): void;
}
