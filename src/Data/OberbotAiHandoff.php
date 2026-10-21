<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Data;

use losthost\DB\DB;
use losthost\DB\DBObject;

final class OberbotAiHandoff extends DBObject
{
    public const METADATA = [
        'ticket_id' => 'BIGINT(20) NOT NULL',
        'source_chat_id' => 'VARCHAR(64) NOT NULL',
        'source_thread_id' => 'BIGINT(20) NOT NULL',
        'hidden_chat_id' => 'VARCHAR(64) NOT NULL',
        'hidden_thread_id' => 'BIGINT(20)',
        'log_sent' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'ingress_sent' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'PRIMARY KEY' => 'ticket_id',
        'UNIQUE INDEX hidden_target' => ['hidden_chat_id', 'hidden_thread_id'],
    ];
    public static function tableName(): string { return DB::$prefix . 'oberbot_ai_handoff'; }
}
