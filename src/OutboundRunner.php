<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Router\RouterUnavailableException;

final class OutboundRunner
{
    private const TRANSIENT_RETRY_DELAY_MS = 5000;

    /** @var null|callable(int): void */
    private $sleep_fn;

    public function __construct(
        private RouterClientInterface $router,
        private TelegramTransport $transport,
        private CursorStoreInterface $cursor_store,
        ?callable $sleep_fn = null
    ) {
        $this->sleep_fn = $sleep_fn;
    }

    public function run(): void
    {
        $router_after_id = $this->cursor_store->loadRouterAfterId();

        while (true) {
            try {
                $events = $this->router->getOutboundEvents($router_after_id, 20, 100);
            } catch (RouterUnavailableException $e) {
                RuntimeLog::error('Router outbound polling temporarily unavailable', [
                    'after_id' => $router_after_id,
                    'retry_after_ms' => self::TRANSIENT_RETRY_DELAY_MS,
                    'error' => $e->getMessage(),
                    'exception_class' => $e::class,
                ]);
                $this->pause(self::TRANSIENT_RETRY_DELAY_MS);
                continue;
            }

            if ($events !== []) {
                RuntimeLog::info('Router outbound polled', [
                    'count' => count($events),
                    'after_id_before' => $router_after_id,
                ]);
            }

            foreach ($events as $payload) {
                $event = OutboundEvent::fromArray($payload);
                RuntimeLog::info('Delivering outbound event', [
                    'event_id' => $event->event_id,
                    'kind' => $event->kind,
                    'opaque' => $event->opaque,
                ]);

                while (true) {
                    try {
                        $this->transport->deliverOutboundEvent($event);
                        break;
                    } catch (TelegramRateLimitException $e) {
                        RuntimeLog::error('Outbound delivery rate limited', [
                            'event_id' => $event->event_id,
                            'kind' => $event->kind,
                            'opaque' => $event->opaque,
                            'retry_after_seconds' => $e->retry_after_seconds,
                            'error' => $e->getMessage(),
                        ]);
                        $this->pause($e->retry_after_seconds * 1000);
                    } catch (RouterUnavailableException | TelegramUnavailableException $e) {
                        RuntimeLog::error('Outbound delivery temporarily unavailable', [
                            'event_id' => $event->event_id,
                            'kind' => $event->kind,
                            'opaque' => $event->opaque,
                            'retry_after_ms' => self::TRANSIENT_RETRY_DELAY_MS,
                            'error' => $e->getMessage(),
                            'exception_class' => $e::class,
                        ]);
                        $this->pause(self::TRANSIENT_RETRY_DELAY_MS);
                    } catch (\Throwable $e) {
                        RuntimeLog::error('Outbound delivery failed', [
                            'event_id' => $event->event_id,
                            'kind' => $event->kind,
                            'opaque' => $event->opaque,
                            'error' => $e->getMessage(),
                        ]);
                        try {
                            $this->transport->reportOutboundDeliveryFailure($event, $e);
                        } catch (\Throwable $diagnostic_error) {
                            RuntimeLog::error('Outbound delivery failure diagnostic failed', [
                                'event_id' => $event->event_id,
                                'kind' => $event->kind,
                                'opaque' => $event->opaque,
                                'error' => $diagnostic_error->getMessage(),
                                'exception_class' => $diagnostic_error::class,
                            ]);
                        }
                        break;
                    }
                }

                if ($event->event_id > $router_after_id) {
                    $router_after_id = $event->event_id;
                    $this->cursor_store->saveRouterAfterId($router_after_id);
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
