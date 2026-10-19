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
        $synchronized = 0;
        $failed = 0;
        foreach ($bots as $bot) {
            try {
                TelegramBotCommandCatalog::install($this->clients->create($bot));
                $synchronized++;
            } catch (\Throwable $error) {
                $failed++;
                RuntimeLog::error('Telegram bot command synchronization failed', [
                    'bot_key' => $bot->bot_key,
                    'username' => $bot->username,
                    'error' => $error->getMessage(),
                    'exception_class' => $error::class,
                ]);
            }
        }

        RuntimeLog::info('Telegram bot commands synchronized', [
            'bot_count' => count($bots),
            'synchronized' => $synchronized,
            'failed' => $failed,
        ]);
    }
}
