<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Data;

use losthost\DB\DB;
use losthost\DB\DBObject;

final class ProcessCursorState extends DBObject
{
    public const METADATA = [
        'cursor_key' => 'VARCHAR(64) NOT NULL COMMENT "Cursor key"',
        'cursor_value' => 'BIGINT NOT NULL DEFAULT 0 COMMENT "Cursor value"',
        'PRIMARY KEY' => 'cursor_key',
    ];

    public static function tableName(): string
    {
        return DB::$prefix . 'telegram_process_cursor_state';
    }
}
