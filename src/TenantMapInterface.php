<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface TenantMapInterface
{
    public function tenantForChat(int|string $chat_id): ?string;
}
