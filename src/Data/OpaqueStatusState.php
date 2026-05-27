<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Data;

use losthost\DB\DB;
use losthost\DB\DBObject;

final class OpaqueStatusState extends DBObject
{
    public const METADATA = [
        'opaque' => 'VARCHAR(96) NOT NULL COMMENT "Opaque session"',
        'enabled' => 'TINYINT(1) NOT NULL DEFAULT 1 COMMENT "Status enabled"',
        'message_id' => 'BIGINT UNSIGNED NULL COMMENT "Telegram status message id"',
        'last_text' => 'TEXT NULL COMMENT "Last status text"',
        'PRIMARY KEY' => 'opaque',
    ];

    public static function tableName(): string
    {
        return DB::$prefix . 'telegram_opaque_status_state';
    }
}
