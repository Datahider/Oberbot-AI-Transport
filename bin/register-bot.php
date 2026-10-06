#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexMultitenant\TransportTelegram\BotRecord;
use CodexMultitenant\TransportTelegram\BotTokenCipher;
use CodexMultitenant\TransportTelegram\DatabaseBootstrap;
use CodexMultitenant\TransportTelegram\DbBotRegistry;
use CodexMultitenant\TransportTelegram\TelegramApiClient;
use CodexMultitenant\TransportTelegram\TelegramBotCommandCatalog;
use CodexMultitenant\TransportTelegram\TelegramTransportConfig;

require __DIR__ . '/../src/bootstrap.php';

$token = trim((string) ($argv[1] ?? ''));
$config_path = (string) ($argv[2] ?? TelegramTransportConfig::CONFIG_PATH);
$is_default = in_array('--default', $argv, true);
$skip_webhook = in_array('--no-webhook', $argv, true);
$deletion_protected = in_array('--protected', $argv, true);
if ($token === '') {
    throw new RuntimeException('Usage: register-bot.php TOKEN [CONFIG] [--default] [--protected] [--no-webhook]');
}

$config = TelegramTransportConfig::fromConfigPath($config_path);
if ($config->telegram_bot_token_encryption_key === '') {
    throw new RuntimeException('telegram.bot_token_encryption_key is required');
}
DatabaseBootstrap::connect($config);
DatabaseBootstrap::initDataStructure();

$client = new TelegramApiClient($token, $config->telegram_base_url, $config->telegram_endpoint_prefix);
$identity = $client->getMe();
$bot_key = bin2hex(random_bytes(16));
$webhook_secret = bin2hex(random_bytes(32));
$registry = new DbBotRegistry(new BotTokenCipher($config->telegram_bot_token_encryption_key));
$registry->register(new BotRecord(
    $bot_key,
    (int) ($identity['id'] ?? 0),
    (string) ($identity['username'] ?? ''),
    $token,
    $webhook_secret,
    $is_default,
    $deletion_protected
));

if (!$skip_webhook) {
    $client->setMyCommands(TelegramBotCommandCatalog::all());
    $client->setWebhook(
        $config->telegram_webhook_base_url . '/' . $bot_key,
        $webhook_secret,
        $config->telegram_webhook_ip_address
    );
}

fwrite(STDOUT, json_encode([
    'bot_key' => $bot_key,
    'telegram_user_id' => (int) ($identity['id'] ?? 0),
    'username' => (string) ($identity['username'] ?? ''),
    'webhook_configured' => !$skip_webhook,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
