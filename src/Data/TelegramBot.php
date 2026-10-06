<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Data;

use losthost\DB\DB;
use losthost\DB\DBObject;

final class TelegramBot extends DBObject
{
    public const METADATA = [
        'bot_key' => 'VARCHAR(64) NOT NULL COMMENT "Public webhook bot key"',
        'telegram_user_id' => 'BIGINT NOT NULL COMMENT "Telegram bot user ID"',
        'username' => 'VARCHAR(64) NOT NULL COMMENT "Telegram bot username"',
        'encrypted_token' => 'TEXT NOT NULL COMMENT "Encrypted Bot API token"',
        'webhook_secret' => 'VARCHAR(256) NOT NULL COMMENT "Telegram webhook secret"',
        'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Default outbound bot"',
        'enabled' => 'TINYINT(1) NOT NULL DEFAULT 1 COMMENT "Bot enabled"',
        'PRIMARY KEY' => 'bot_key',
        'UNIQUE INDEX telegram_user_id' => 'telegram_user_id',
        'UNIQUE INDEX username' => 'username',
        'INDEX default_enabled' => ['is_default', 'enabled'],
    ];

    public static function tableName(): string
    {
        return DB::$prefix . 'telegram_bot';
    }
}
