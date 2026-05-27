<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Data\ChatTenantBinding;
use Throwable;

final class DbTenantMap implements TenantMapInterface
{
    public function tenantForChat(int|string $chat_id): ?string
    {
        try {
            $binding = new ChatTenantBinding(['chat_id' => (string) $chat_id]);
        } catch (Throwable) {
            return null;
        }

        $tenant = trim((string) ($binding->tenant ?? ''));

        return $tenant !== '' ? $tenant : null;
    }
}
