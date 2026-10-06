#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexMultitenant\TransportTelegram\DatabaseBootstrap;
use CodexMultitenant\TransportTelegram\Data\TelegramBot;
use CodexMultitenant\TransportTelegram\TelegramTransportConfig;

require __DIR__ . '/../src/bootstrap.php';

$bot_key = trim((string) ($argv[1] ?? ''));
$mode = trim((string) ($argv[2] ?? ''));
$config_path = (string) ($argv[3] ?? TelegramTransportConfig::CONFIG_PATH);
if ($bot_key === '' || !in_array($mode, ['on', 'off'], true)) {
    throw new RuntimeException('Usage: set-bot-deletion-protection.php BOT_KEY on|off [CONFIG]');
}

$config = TelegramTransportConfig::fromConfigPath($config_path);
DatabaseBootstrap::connect($config);
DatabaseBootstrap::initDataStructure();
$bot = new TelegramBot(['bot_key' => $bot_key]);
$bot->deletion_protected = $mode === 'on';
$bot->write();

fwrite(STDOUT, $bot_key . ' deletion_protected=' . ($mode === 'on' ? 'true' : 'false') . PHP_EOL);
