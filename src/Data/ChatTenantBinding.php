<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Data;

use losthost\DB\DB;
use losthost\DB\DBObject;

final class ChatTenantBinding extends DBObject
{
    public const METADATA = [
        'chat_id' => 'VARCHAR(64) NOT NULL COMMENT "Telegram chat ID"',
        'tenant' => 'VARCHAR(190) NOT NULL COMMENT "Router tenant"',
        'PRIMARY KEY' => 'chat_id',
        'INDEX tenant' => 'tenant',
    ];

    public static function tableName(): string
    {
        return DB::$prefix . 'telegram_chat_tenant_binding';
    }
}
