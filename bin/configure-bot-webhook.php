#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexMultitenant\TransportTelegram\BotTokenCipher;
use CodexMultitenant\TransportTelegram\DatabaseBootstrap;
use CodexMultitenant\TransportTelegram\DbBotRegistry;
use CodexMultitenant\TransportTelegram\TelegramApiClient;
use CodexMultitenant\TransportTelegram\TelegramBotCommandCatalog;
use CodexMultitenant\TransportTelegram\TelegramTransportConfig;

require __DIR__ . '/../src/bootstrap.php';

$bot_key = trim((string) ($argv[1] ?? ''));
$config_path = (string) ($argv[2] ?? TelegramTransportConfig::CONFIG_PATH);
if ($bot_key === '') {
    throw new RuntimeException('Usage: configure-bot-webhook.php BOT_KEY [CONFIG]');
}
$config = TelegramTransportConfig::fromConfigPath($config_path);
DatabaseBootstrap::connect($config);
$registry = new DbBotRegistry(new BotTokenCipher($config->telegram_bot_token_encryption_key));
$bot = $registry->botByKey($bot_key);
$client = new TelegramApiClient($bot->token, $config->telegram_base_url, $config->telegram_endpoint_prefix);
TelegramBotCommandCatalog::install($client);
$client->setWebhook(
    $config->telegram_webhook_base_url . '/' . $bot->bot_key,
    $bot->webhook_secret,
    $config->telegram_webhook_ip_address
);
fwrite(STDOUT, "Webhook configured for @{$bot->username}\n");
