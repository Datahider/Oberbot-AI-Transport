<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class IngressRunner
{
    public function __construct(
        private TelegramTransportConfig $config,
        private TelegramClientInterface $telegram,
        private TelegramTransport $transport,
        private CursorStoreInterface $cursor_store
    ) {
    }

    public function run(): void
    {
        $telegram_offset = $this->cursor_store->loadTelegramOffset();

        while (true) {
            $updates = $this->telegram->getUpdates(
                $telegram_offset > 0 ? $telegram_offset : null,
                $this->config->telegram_poll_timeout_seconds,
                100
            );

            if ($updates !== []) {
                RuntimeLog::info('Telegram updates polled', [
                    'count' => count($updates),
                    'offset_before' => $telegram_offset,
                ]);
            }

            foreach ($updates as $update) {
                $update_id = (int) ($update['update_id'] ?? 0);
                if ($update_id >= $telegram_offset) {
                    $telegram_offset = $update_id + 1;
                    $this->cursor_store->saveTelegramOffset($telegram_offset);
                }

                RuntimeLog::info('Handling Telegram update', [
                    'update_id' => $update_id,
                ]);
                $this->transport->handleIncomingUpdate($update);
            }
        }
    }
}
