<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CodexMultitenant\TransportTelegram\MainRunner;
use CodexMultitenant\TransportTelegram\RuntimeLog;
use CodexMultitenant\TransportTelegram\BotCommandSynchronizer;

function testMainRunnerStartsInboundAndOutboundWorkers(): void
{
    $started = [];
    $runner = new MainRunner(
        buildConfig(),
        __DIR__,
        __FILE__,
        __FILE__,
        function (string $name, string $project_root, string $bootstrap_path, string $config_path, string $log_file) use (&$started): int {
            $started[] = [
                'name' => $name,
                'project_root' => $project_root,
                'bootstrap_path' => $bootstrap_path,
                'config_path' => $config_path,
                'log_file' => $log_file,
            ];

            return count($started) + 1000;
        },
        static fn (int $pid): bool => true,
        static function (int $milliseconds): void {
        },
        1
    );

    RuntimeLog::setObserver(null);
    $runner->run();

    assertSame('inbound', $started[0]['name'] ?? null, 'inbound started first');
    assertSame('outbound', $started[1]['name'] ?? null, 'outbound started second');
    assertTrue(str_ends_with((string) ($started[0]['log_file'] ?? ''), '/var/log/telegram-ingress.log'), 'inbound log path');
    assertTrue(str_ends_with((string) ($started[1]['log_file'] ?? ''), '/var/log/router-outbound.log'), 'outbound log path');
}

function testMainRunnerWorkerBootstrapUsesLogFileObserver(): void
{
    $runner = new MainRunner(
        buildConfig(),
        __DIR__,
        __FILE__,
        __FILE__,
        null,
        static fn (int $pid): bool => true,
        static function (int $milliseconds): void {
        },
        1
    );

    $method = new ReflectionMethod(MainRunner::class, 'buildWorkerBootstrap');
    $method->setAccessible(true);

    $bootstrap = $method->invoke($runner, 'outbound');
    $rendered = sprintf(
        $bootstrap,
        '/tmp/project-root',
        '/tmp/bootstrap.php',
        '/tmp/router-outbound.log',
        '/tmp/runtime-config.php'
    );

    assertTrue(str_contains($bootstrap, '$log_file = %s;'), 'bootstrap has log file placeholder');
    assertTrue(str_contains($bootstrap, 'RuntimeLog::setObserver'), 'bootstrap configures runtime log observer');
    assertTrue(str_contains($bootstrap, "fopen(\$log_file, 'ab')"), 'bootstrap opens log file for append');
    assertTrue(str_contains($rendered, '/tmp/router-outbound.log'), 'rendered bootstrap uses log file as log destination');
    assertTrue(str_contains($rendered, '/tmp/runtime-config.php'), 'rendered bootstrap uses runtime config path');
}

function testMainRunnerStartsOnlyOutboundWhenTelegramPollingIsDisabled(): void
{
    $started = [];
    $base = buildConfig();
    $config = new \CodexMultitenant\TransportTelegram\TelegramTransportConfig(
        router_base_url: $base->router_base_url,
        router_token: $base->router_token,
        telegram_bot_token: $base->telegram_bot_token,
        telegram_base_url: $base->telegram_base_url,
        telegram_endpoint_prefix: $base->telegram_endpoint_prefix,
        file_exchange_base_url: $base->file_exchange_base_url,
        file_exchange_token: $base->file_exchange_token,
        transport_instance_id: $base->transport_instance_id,
        outbound_poll_interval_ms: $base->outbound_poll_interval_ms,
        telegram_poll_timeout_seconds: $base->telegram_poll_timeout_seconds,
        unknown_chat_text: $base->unknown_chat_text,
        status_instruction_text: $base->status_instruction_text,
        idle_status_text: $base->idle_status_text,
        telegram_polling_enabled: false
    );

    $runner = new MainRunner(
        $config,
        __DIR__,
        __FILE__,
        __FILE__,
        static function (string $name) use (&$started): int {
            $started[] = $name;
            return 3001;
        },
        static fn (int $pid): bool => true,
        static function (int $milliseconds): void {
        },
        1
    );

    $runner->run();

    assertSame(['outbound'], $started, 'only outbound worker started');
}

function testMainRunnerSynchronizesCommandsBeforeWorkers(): void
{
    $events = [];
    $factory = new CommandSyncClientFactory();
    $synchronizer = new BotCommandSynchronizer(new CommandSyncRegistry([syncBot('a', 1)]), $factory);

    $runner = new MainRunner(
        buildConfig(),
        __DIR__,
        __FILE__,
        __FILE__,
        static function (string $name) use (&$events): int {
            $events[] = 'worker:' . $name;
            return count($events) + 4000;
        },
        static fn (int $pid): bool => true,
        static function (int $milliseconds): void {},
        1,
        $synchronizer
    );

    $runner->run();

    assertSame(3, count($factory->clients['a']->command_sets), 'commands synchronized during main startup');
    assertSame(['sync:a'], $factory->events, 'command synchronization executed');
    assertSame(['worker:inbound', 'worker:outbound'], $events, 'workers started');
}

function testMainRunnerRestartsExitedWorkerAndLogsIt(): void
{
    $launches = [];
    $log_records = [];
    $alive_by_pid = [
        2001 => true,
        2002 => false,
        2003 => true,
    ];

    RuntimeLog::setObserver(static function (array $payload) use (&$log_records): void {
        $log_records[] = $payload;
    });

    try {
        $runner = new MainRunner(
            buildConfig(),
            __DIR__,
            __FILE__,
            __FILE__,
            function (string $name, string $project_root, string $bootstrap_path, string $config_path, string $log_file) use (&$launches): int {
                $pid = 2001 + count($launches);
                $launches[] = [
                    'name' => $name,
                    'pid' => $pid,
                    'log_file' => $log_file,
                ];

                return $pid;
            },
            static fn (int $pid): bool => $alive_by_pid[$pid] ?? true,
            static function (int $milliseconds): void {
            },
            1
        );

        $runner->run();
    } finally {
        RuntimeLog::setObserver(null);
    }

    assertSame([
        'inbound',
        'outbound',
        'outbound',
    ], array_column($launches, 'name'), 'outbound restarted');

    assertSame('ERROR', $log_records[2]['level'] ?? null, 'exit logged as error');
    assertSame('Transport worker exited', $log_records[2]['message'] ?? null, 'exit log message');
    assertSame('outbound', $log_records[2]['context']['worker'] ?? null, 'exited worker name');

    assertSame('INFO', $log_records[3]['level'] ?? null, 'restart logged as info');
    assertSame('Transport worker restarted', $log_records[3]['message'] ?? null, 'restart log message');
    assertSame('outbound', $log_records[3]['context']['worker'] ?? null, 'restarted worker name');
    assertSame(1, $log_records[3]['context']['restart_count'] ?? null, 'restart count');
}

testMainRunnerStartsInboundAndOutboundWorkers();
testMainRunnerWorkerBootstrapUsesLogFileObserver();
testMainRunnerStartsOnlyOutboundWhenTelegramPollingIsDisabled();
testMainRunnerSynchronizesCommandsBeforeWorkers();
testMainRunnerRestartsExitedWorkerAndLogsIt();

fwrite(STDOUT, "MainRunner tests: OK\n");
