<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CodexMultitenant\TransportTelegram\MainRunner;
use CodexMultitenant\TransportTelegram\RuntimeLog;

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

    assertTrue(str_contains($bootstrap, '$log_file = %s;'), 'bootstrap has log file placeholder');
    assertTrue(str_contains($bootstrap, 'RuntimeLog::setObserver'), 'bootstrap configures runtime log observer');
    assertTrue(str_contains($bootstrap, "fopen(\$log_file, 'ab')"), 'bootstrap opens log file for append');
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
testMainRunnerRestartsExitedWorkerAndLogsIt();

fwrite(STDOUT, "MainRunner tests: OK\n");
