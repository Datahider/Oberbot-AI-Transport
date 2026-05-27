<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class OutboundRunner
{
    public function __construct(
        private RouterClientInterface $router,
        private TelegramTransport $transport,
        private CursorStoreInterface $cursor_store
    ) {
    }

    public function run(): void
    {
        $router_after_id = $this->cursor_store->loadRouterAfterId();

        while (true) {
            $events = $this->router->getOutboundEvents($router_after_id, 20, 100);
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
        }
    }
}
