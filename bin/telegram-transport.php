#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexMultitenant\TransportTelegram\RuntimeFactory;
use CodexMultitenant\TransportTelegram\TelegramTransportConfig;

$config_path = $argv[1] ?? TelegramTransportConfig::CONFIG_PATH;
$config = TelegramTransportConfig::fromConfigPath($config_path);

RuntimeFactory::createRunner($config)->run();
