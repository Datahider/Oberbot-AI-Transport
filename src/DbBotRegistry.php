<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Data\TelegramBot;
use CodexMultitenant\TransportTelegram\Data\TelegramChatBotRoute;
use losthost\DB\DBList;
use RuntimeException;
use Throwable;

final class DbBotRegistry implements BotRegistryInterface
{
    public function __construct(private BotTokenCipher $cipher) {}

    public function botByKey(string $bot_key): BotRecord
    {
        try {
            $bot = new TelegramBot(['bot_key' => $bot_key]);
        } catch (Throwable $e) {
            throw new RuntimeException('Unknown Telegram bot key', 0, $e);
        }
        if (!(bool) $bot->enabled) {
            throw new RuntimeException('Telegram bot is disabled');
        }
        return $this->record($bot);
    }

    public function defaultBot(): BotRecord
    {
        $list = new DBList(TelegramBot::class, ['is_default' => true, 'enabled' => true]);
        $bot = $list->next();
        if (!$bot instanceof TelegramBot) {
            throw new RuntimeException('Default Telegram bot is not configured');
        }
        if ($list->next() !== false) {
            throw new RuntimeException('Multiple default Telegram bots are configured');
        }
        return $this->record($bot);
    }

    public function activeBotForChat(string $chat_id): BotRecord
    {
        return $this->explicitBotForChat($chat_id) ?? $this->defaultBot();
    }

    public function explicitBotForChat(string $chat_id): ?BotRecord
    {
        try {
            $route = new TelegramChatBotRoute(['chat_id' => $chat_id]);
        } catch (Throwable) {
            return null;
        }
        return $this->botByKey((string) $route->bot_key);
    }

    public function setActiveBotForChat(string $chat_id, string $bot_key): void
    {
        $this->botByKey($bot_key);
        try {
            $route = new TelegramChatBotRoute(['chat_id' => $chat_id]);
        } catch (Throwable) {
            $route = new TelegramChatBotRoute();
            $route->chat_id = $chat_id;
        }
        $route->bot_key = $bot_key;
        $route->write();
    }

    public function register(BotRecord $record): void
    {
        try {
            $bot = new TelegramBot(['bot_key' => $record->bot_key]);
        } catch (Throwable) {
            $bot = new TelegramBot();
            $bot->bot_key = $record->bot_key;
        }
        $bot->telegram_user_id = $record->telegram_user_id;
        $bot->username = $record->username;
        $bot->encrypted_token = $this->cipher->encrypt($record->token);
        $bot->webhook_secret = $record->webhook_secret;
        $bot->is_default = $record->is_default;
        $bot->enabled = true;
        $bot->write();
    }

    private function record(TelegramBot $bot): BotRecord
    {
        return new BotRecord(
            (string) $bot->bot_key,
            (int) $bot->telegram_user_id,
            (string) $bot->username,
            $this->cipher->decrypt((string) $bot->encrypted_token),
            (string) $bot->webhook_secret,
            (bool) $bot->is_default
        );
    }
}
