#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexMultitenant\TransportTelegram\Data\ChatTenantBinding;
use losthost\DB\DB;
use losthost\DB\DBView;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $config_path = dirname(__DIR__) . '/etc/config.php';
    if (!is_file($config_path)) {
        throw new RuntimeException('Missing etc/config.php');
    }

    $config = require $config_path;
    if (!is_array($config) || !is_array($config['db'] ?? null)) {
        throw new RuntimeException('Invalid etc/config.php');
    }

    $db = $config['db'];
    DB::connect($db['host'], $db['user'], $db['pass'], $db['name'], $db['prefix']);
    $view = new DBView(
        'SELECT chat_id, tenant FROM ' . ChatTenantBinding::tableName() . ' ORDER BY chat_id ASC'
    );

    foreach ($view->asArray() as $row) {
        $binding = trim((string) ($row['chat_id'] ?? ''));
        $tenant = trim((string) ($row['tenant'] ?? ''));
        if ($binding === '' || $tenant === '') {
            throw new RuntimeException('Stored binding contains an empty value');
        }

        fwrite(STDOUT, json_encode([
            'tenant' => $tenant,
            'binding' => $binding,
            'label' => 'Telegram ' . $binding,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Binding export failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
