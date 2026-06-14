<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Router\RouterUnavailableException;

final class IngressRunner
{
    private const TRANSIENT_RETRY_DELAY_MS = 5000;

    /** @var null|callable(int): void */
    private $sleep_fn;

    public function __construct(
        private TelegramTransportConfig $config,
        private TelegramClientInterface $telegram,
        private TelegramTransport $transport,
        private CursorStoreInterface $cursor_store,
        ?callable $sleep_fn = null
    ) {
        $this->sleep_fn = $sleep_fn;
    }

    public function run(): void
    {
        $this->telegram->setMyCommands(TelegramBotCommandCatalog::all());

        $telegram_offset = $this->cursor_store->loadTelegramOffset();

        while (true) {
            try {
                $updates = $this->telegram->getUpdates(
                    $telegram_offset > 0 ? $telegram_offset : null,
                    $this->config->telegram_poll_timeout_seconds,
                    100
                );
            } catch (TelegramUnavailableException $e) {
                RuntimeLog::error('Telegram polling temporarily unavailable', [
                    'offset' => $telegram_offset,
                    'retry_after_ms' => self::TRANSIENT_RETRY_DELAY_MS,
                    'error' => $e->getMessage(),
                    'exception_class' => $e::class,
                ]);
                $this->pause(self::TRANSIENT_RETRY_DELAY_MS);
                continue;
            }

            if ($updates !== []) {
                RuntimeLog::info('Telegram updates polled', [
                    'count' => count($updates),
                    'offset_before' => $telegram_offset,
                ]);
            }

            foreach ($updates as $update) {
                $update_id = (int) ($update['update_id'] ?? 0);

                while (true) {
                    try {
                        RuntimeLog::info('Handling Telegram update', [
                            'update_id' => $update_id,
                        ]);
                        $this->transport->handleIncomingUpdate($update);

                        if ($update_id >= $telegram_offset) {
                            $telegram_offset = $update_id + 1;
                            $this->cursor_store->saveTelegramOffset($telegram_offset);
                        }
                        break;
                    } catch (RouterUnavailableException | TelegramUnavailableException $e) {
                        RuntimeLog::error('Ingress update temporarily unavailable', [
                            'update_id' => $update_id,
                            'offset' => $telegram_offset,
                            'retry_after_ms' => self::TRANSIENT_RETRY_DELAY_MS,
                            'error' => $e->getMessage(),
                            'exception_class' => $e::class,
                        ]);
                        $this->pause(self::TRANSIENT_RETRY_DELAY_MS);
                    }
                }
            }
        }
    }

    private function pause(int $milliseconds): void
    {
        if ($this->sleep_fn !== null) {
            ($this->sleep_fn)($milliseconds);
            return;
        }

        usleep($milliseconds * 1000);
    }
}
