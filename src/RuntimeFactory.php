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
        if ($config->telegram_bot_token === '') {
            throw new \RuntimeException('telegram.bot_token is required for legacy polling');
        }
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
                new DbIgnoreStateStore(),
                new FileExchangeApiClient(
                    $config->file_exchange_base_url,
                    $config->file_exchange_token
                ),
                new DbGroupAccessStore()
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

        $registry = self::createBotRegistry($config);

        return new OutboundRunner(
            $router,
            new BotAwareOutboundDelivery(
                $config,
                $router,
                $registry,
                new DbTenantMap(),
                new DbStatusStateStore(),
                new DbIgnoreStateStore(),
                new FileExchangeApiClient(
                    $config->file_exchange_base_url,
                    $config->file_exchange_token
                ),
                new DbGroupAccessStore()
            ),
            new DbCursorStore()
        );
    }

    public static function createWebhookEndpoint(string $config_path, string $bot_key): WebhookEndpoint
    {
        $config = TelegramTransportConfig::fromConfigPath($config_path);
        DatabaseBootstrap::connect($config);
        DatabaseBootstrap::initDataStructure();

        $router = new ApiClient(
            $config->router_base_url,
            $config->router_token,
            new CurlHttpClient()
        );

        $registry = self::createBotRegistry($config);
        $bot = $registry->botByKey($bot_key);
        $telegram = new TelegramApiClient(
            $bot->token,
            $config->telegram_base_url,
            $config->telegram_endpoint_prefix
        );

        $transport = new TelegramTransport(
            $config,
            $router,
            $telegram,
            new DbTenantMap(),
            new DbStatusStateStore(),
            new DbIgnoreStateStore(),
            new FileExchangeApiClient(
                $config->file_exchange_base_url,
                $config->file_exchange_token
            ),
            new DbGroupAccessStore()
        );
        $lock = new DbChatLock();
        $clients = new TelegramBotClientFactory($config->telegram_base_url, $config->telegram_endpoint_prefix);
        $status_reset = new DbStatusStateStore();

        return new WebhookEndpoint(
            $bot->webhook_secret,
            new BotAwareIncomingUpdateHandler(
                $bot,
                $registry,
                $lock,
                new BotHandoverHandler($registry, $lock, $clients, $status_reset),
                $transport,
                new BotManagementHandler(
                    $bot,
                    $registry,
                    new DbPendingBotActionStore(),
                    $clients,
                    $config->telegram_webhook_base_url,
                    $config->telegram_webhook_ip_address,
                    static fn(): int => time()
                ),
                $status_reset
            )
        );
    }

    private static function createBotRegistry(TelegramTransportConfig $config): DbBotRegistry
    {
        if ($config->telegram_bot_token_encryption_key === '') {
            throw new \RuntimeException('telegram.bot_token_encryption_key is required');
        }
        return new DbBotRegistry(new BotTokenCipher($config->telegram_bot_token_encryption_key));
    }
}
