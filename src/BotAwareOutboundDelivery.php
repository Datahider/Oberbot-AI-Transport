<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use Throwable;

final class BotAwareOutboundDelivery implements OutboundDeliveryInterface
{
    public function __construct(
        private TelegramTransportConfig $config,
        private RouterClientInterface $router,
        private BotRegistryInterface $registry,
        private TenantMapInterface $tenant_map,
        private StatusStateStoreInterface $status_store,
        private IgnoreStateStoreInterface $ignore_store,
        private FileExchangeClientInterface $file_exchange
    ) {
    }

    public function deliverOutboundEvent(OutboundEvent $event): void
    {
        $this->transportFor($event)->deliverOutboundEvent($event);
    }

    public function reportOutboundDeliveryFailure(OutboundEvent $event, Throwable $error): void
    {
        $this->transportFor($event)->reportOutboundDeliveryFailure($event, $error);
    }

    private function transportFor(OutboundEvent $event): TelegramTransport
    {
        $chat_id = explode(':', $event->opaque, 2)[0];
        $bot = $this->registry->activeBotForChat($chat_id);
        return new TelegramTransport(
            $this->config,
            $this->router,
            new TelegramApiClient($bot->token, $this->config->telegram_base_url, $this->config->telegram_endpoint_prefix),
            $this->tenant_map,
            $this->status_store,
            $this->ignore_store,
            $this->file_exchange
        );
    }
}
