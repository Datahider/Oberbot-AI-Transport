<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Router\ApiClient;
use CodexMultitenant\TransportTelegram\Router\CurlHttpClient;

final class RuntimeFactory
{
    public static function createMainRunner(string $config_path): MainRunner
    {
        $config = TelegramTransportConfig::fromConfigPath($config_path);
        DatabaseBootstrap::connect($config);
        DatabaseBootstrap::initDataStructure();

        return new MainRunner(
            $config,
            dirname(__DIR__),
            __DIR__ . '/bootstrap.php',
            $config_path
        );
    }

    public static function createIngressRunner(string $config_path): IngressRunner
    {
        $config = TelegramTransportConfig::fromConfigPath($config_path);
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

        return new IngressRunner(
            $config,
            $telegram,
            new TelegramTransport(
                $config,
                $router,
                $telegram,
                new DbTenantMap(),
                new DbStatusStateStore(),
                new DbIgnoreStateStore()
            ),
            new DbCursorStore()
        );
    }

    public static function createOutboundRunner(string $config_path): OutboundRunner
    {
        $config = TelegramTransportConfig::fromConfigPath($config_path);
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

        return new OutboundRunner(
            $router,
            new TelegramTransport(
                $config,
                $router,
                $telegram,
                new DbTenantMap(),
                new DbStatusStateStore(),
                new DbIgnoreStateStore()
            ),
            new DbCursorStore()
        );
    }
}
