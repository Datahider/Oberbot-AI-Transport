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

    /** @return array<string, mixed> */
    public function claimCoreBinding(string $binding, string $pairing_code, string $label): array;

    /** @return array<string, mixed> */
    public function createInstallPairingCode(string $binding, string $label): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getOutboundEvents(int $after_id, int $wait, int $limit): array;
}
