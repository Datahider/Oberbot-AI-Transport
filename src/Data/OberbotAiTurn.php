<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Data;

use losthost\DB\DB;
use losthost\DB\DBObject;

final class OberbotAiTurn extends DBObject
{
    public const METADATA = [
        'opaque' => 'VARCHAR(128) NOT NULL',
        'ticket_id' => 'BIGINT(20) NOT NULL',
        'source_chat_id' => 'VARCHAR(64) NOT NULL',
        'started_at' => 'DATETIME NOT NULL',
        'last_heartbeat_at' => 'DATETIME NOT NULL',
        'PRIMARY KEY' => 'opaque',
        'INDEX timeout_scan' => 'last_heartbeat_at',
    ];
    public static function tableName(): string { return DB::$prefix . 'oberbot_ai_turn'; }
}
