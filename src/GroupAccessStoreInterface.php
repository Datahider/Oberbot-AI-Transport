<?php
declare(strict_types=1);
namespace CodexMultitenant\TransportTelegram;
interface GroupAccessStoreInterface
{
    public function modeForGroup(string $chat_id): string;
    public function setModeForGroup(string $chat_id, string $mode): void;
}
