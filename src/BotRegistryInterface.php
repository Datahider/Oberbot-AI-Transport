<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface BotRegistryInterface
{
    public function botByKey(string $bot_key): BotRecord;
    public function defaultBot(): BotRecord;
    public function activeBotForChat(string $chat_id): BotRecord;
    public function explicitBotForChat(string $chat_id): ?BotRecord;
    public function setActiveBotForChat(string $chat_id, string $bot_key): void;
}
