<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface RouterClientInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postIngress(array $payload): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getOutboundEvents(int $after_id, int $wait, int $limit): array;
}
