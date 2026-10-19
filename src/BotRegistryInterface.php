<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface BotRegistryInterface
{
    /** @return list<BotRecord> */
    public function enabledBots(): array;
    public function botByKey(string $bot_key): BotRecord;
    public function botByTelegramUserId(int $telegram_user_id): ?BotRecord;
    public function defaultBot(): BotRecord;
    public function activeBotForChat(string $chat_id): BotRecord;
    public function explicitBotForChat(string $chat_id): ?BotRecord;
    public function setActiveBotForChat(string $chat_id, string $bot_key, string $chat_type): void;
    public function register(BotRecord $record): void;
    public function hasTelegramUserId(int $telegram_user_id): bool;
    /** @return list<BotRecord> */
    public function protectedBots(): array;
    /** @return list<string> */
    public function activeGroupChatIds(string $bot_key): array;
    public function deleteRoutesForBot(string $bot_key): void;
    public function deleteBot(string $bot_key): void;
}
