<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface IncomingUpdateHandlerInterface
{
    /**
     * @param array<string, mixed> $update
     * @return array{accepted: bool, messages: list<array<string, mixed>>}
     */
    public function handleIncomingUpdate(array $update): array;
}
