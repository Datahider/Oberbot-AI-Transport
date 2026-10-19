<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class BotCommandSynchronizer
{
    public function __construct(
        private BotRegistryInterface $registry,
        private BotManagementClientFactoryInterface $clients
    ) {
    }

    public function syncAll(): void
    {
        $bots = $this->registry->enabledBots();
        foreach ($bots as $bot) {
            TelegramBotCommandCatalog::install($this->clients->create($bot));
        }

        RuntimeLog::info('Telegram bot commands synchronized', ['bot_count' => count($bots)]);
    }
}
