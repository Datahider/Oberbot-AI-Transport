<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram\Data;

use losthost\DB\DB;
use losthost\DB\DBObject;

final class IngressIgnoreScopeState extends DBObject
{
    public const METADATA = [
        'scope' => 'VARCHAR(96) NOT NULL COMMENT "Ingress ignore scope"',
        'ignored' => 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Ingress ignore flag"',
        'PRIMARY KEY' => 'scope',
    ];

    public static function tableName(): string
    {
        return DB::$prefix . 'telegram_ingress_ignore_scope_state';
    }
}
