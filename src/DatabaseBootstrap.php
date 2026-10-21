<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Data\ChatTenantBinding;
use CodexMultitenant\TransportTelegram\Data\IngressIgnoreScopeState;
use CodexMultitenant\TransportTelegram\Data\GroupAccessSetting;
use CodexMultitenant\TransportTelegram\Data\OpaqueStatusState;
use CodexMultitenant\TransportTelegram\Data\PendingBotAction;
use CodexMultitenant\TransportTelegram\Data\ProcessCursorState;
use CodexMultitenant\TransportTelegram\Data\TelegramBot;
use CodexMultitenant\TransportTelegram\Data\TelegramChatBotRoute;
use CodexMultitenant\TransportTelegram\Data\OberbotAiHandoff;
use CodexMultitenant\TransportTelegram\Data\OberbotAiTurn;
use losthost\timetracker\Timer;
use losthost\timetracker\TimerEvent;
use losthost\DB\DB;

final class DatabaseBootstrap
{
    public static function connect(TelegramTransportConfig $config): void
    {
        DB::connect(
            $config->db_host,
            $config->db_user,
            $config->db_pass,
            $config->db_name,
            $config->db_prefix
        );
    }

    public static function initDataStructure(): void
    {
        ChatTenantBinding::initDataStructure();
        IngressIgnoreScopeState::initDataStructure();
        GroupAccessSetting::initDataStructure();
        OpaqueStatusState::initDataStructure();
        ProcessCursorState::initDataStructure();
        TelegramBot::initDataStructure();
        TelegramChatBotRoute::initDataStructure();
        PendingBotAction::initDataStructure();
        OberbotAiHandoff::initDataStructure();
        OberbotAiTurn::initDataStructure();
        Timer::initDataStructure();
        TimerEvent::initDataStructure();
    }
}
