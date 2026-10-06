<?php
declare(strict_types=1);
namespace CodexMultitenant\TransportTelegram\Data;
use losthost\DB\DB;
use losthost\DB\DBObject;
final class GroupAccessSetting extends DBObject
{
    public const METADATA = [
        'chat_id' => 'VARCHAR(64) NOT NULL COMMENT "Telegram group chat ID"',
        'mode' => 'VARCHAR(16) NOT NULL COMMENT "admins or members"',
        'PRIMARY KEY' => 'chat_id',
    ];
    public static function tableName(): string { return DB::$prefix . 'group_access_setting'; }
}
