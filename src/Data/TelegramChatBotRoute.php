<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Data;

use losthost\DB\DB;
use losthost\DB\DBObject;

final class TelegramChatBotRoute extends DBObject
{
    public const METADATA = [
        'chat_id' => 'VARCHAR(64) NOT NULL COMMENT "Telegram chat ID"',
        'bot_key' => 'VARCHAR(64) NOT NULL COMMENT "Active Telegram bot key"',
        'PRIMARY KEY' => 'chat_id',
        'INDEX bot_key' => 'bot_key',
    ];

    public static function tableName(): string
    {
        return DB::$prefix . 'telegram_chat_bot_route';
    }
}
