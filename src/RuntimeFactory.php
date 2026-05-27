<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Router\ApiClient;
use CodexMultitenant\TransportTelegram\Router\CurlHttpClient;

final class RuntimeFactory
{
    public static function createRunner(TelegramTransportConfig $config): TransportRunner
    {
        DatabaseBootstrap::connect($config);
        DatabaseBootstrap::initDataStructure();

        $router = new ApiClient(
            $config->router_base_url,
            $config->router_token,
            new CurlHttpClient()
        );

        $telegram = new TelegramApiClient(
            $config->telegram_bot_token,
            $config->telegram_base_url,
            $config->telegram_endpoint_prefix
        );

        $transport = new TelegramTransport(
            $config,
            $router,
            $telegram,
            new DbTenantMap(),
            new DbStatusStateStore()
        );

        return new TransportRunner(
            $config,
            $telegram,
            $router,
            $transport,
            new DbCursorStore()
        );
    }
}
