<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class TransportRunner
{
    public function __construct(
        private TelegramTransportConfig $config,
        private TelegramClientInterface $telegram,
        private RouterClientInterface $router,
        private TelegramTransport $transport,
        private CursorStoreInterface $cursor_store
    ) {
    }

    public function run(): void
    {
        $telegram_offset = $this->cursor_store->loadTelegramOffset();
        $router_after_id = $this->cursor_store->loadRouterAfterId();
        $cycle_timeout = max(1, min(
            $this->config->telegram_poll_timeout_seconds,
            (int) ceil($this->config->outbound_poll_interval_ms / 1000)
        ));

        while (true) {
            $events = $this->router->getOutboundEvents($router_after_id, $cycle_timeout, 100);
            if ($events !== []) {
                RuntimeLog::info('Router outbound polled', [
                    'count' => count($events),
                    'after_id_before' => $router_after_id,
                ]);
            }

            foreach ($events as $payload) {
                $event = OutboundEvent::fromArray($payload);
                if ($event->event_id > $router_after_id) {
                    $router_after_id = $event->event_id;
                    $this->cursor_store->saveRouterAfterId($router_after_id);
                }

                RuntimeLog::info('Delivering outbound event', [
                    'event_id' => $event->event_id,
                    'kind' => $event->kind,
                    'opaque' => $event->opaque,
                ]);
                $this->transport->deliverOutboundEvent($event);
            }

            $updates = $this->telegram->getUpdates(
                $telegram_offset > 0 ? $telegram_offset : null,
                $cycle_timeout,
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
