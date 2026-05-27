#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexMultitenant\TransportTelegram\TelegramTransportConfig;
use CodexMultitenant\TransportTelegram\RuntimeFactory;

$config_path = $argv[1] ?? TelegramTransportConfig::CONFIG_PATH;
RuntimeFactory::createMainRunner($config_path)->run();
