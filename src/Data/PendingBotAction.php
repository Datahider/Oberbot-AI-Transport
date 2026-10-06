<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Data;

use losthost\DB\DB;
use losthost\DB\DBObject;

final class PendingBotAction extends DBObject
{
    public const METADATA = [
        'bot_key' => 'VARCHAR(64) NOT NULL',
        'chat_id' => 'VARCHAR(64) NOT NULL',
        'user_id' => 'BIGINT NOT NULL',
        'action' => 'VARCHAR(16) NOT NULL',
        'expires_at' => 'BIGINT NOT NULL',
        'token_message_id' => 'BIGINT NOT NULL DEFAULT 0',
        'PRIMARY KEY' => ['bot_key', 'chat_id', 'user_id'],
    ];

    public static function tableName(): string { return DB::$prefix . 'pending_bot_action'; }
}
