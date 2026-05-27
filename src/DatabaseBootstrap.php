<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Data\ChatTenantBinding;
use CodexMultitenant\TransportTelegram\Data\IngressIgnoreScopeState;
use CodexMultitenant\TransportTelegram\Data\OpaqueStatusState;
use CodexMultitenant\TransportTelegram\Data\ProcessCursorState;
use losthost\DB\DB;

final class DatabaseBootstrap
{
    public static function connect(TelegramTransportConfig $config): void
    {
        DB::connect(
            $config->db_host,
            $config->db_user,
            $config->db_pass,
            $config->db_name,
            $config->db_prefix
        );
    }

    public static function initDataStructure(): void
    {
        ChatTenantBinding::initDataStructure();
        IngressIgnoreScopeState::initDataStructure();
        OpaqueStatusState::initDataStructure();
        ProcessCursorState::initDataStructure();
    }
}
