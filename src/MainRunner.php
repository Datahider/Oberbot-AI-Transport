<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use losthost\BackgroundProcess\BackgroundProcess;

final class MainRunner
{
    /** @var null|callable(string, string, string, string, string): int */
    private $worker_launcher;

    /** @var null|callable(int): bool */
    private $worker_probe;

    /** @var null|callable(int): void */
    private $sleep_fn;

    public function __construct(
        private TelegramTransportConfig $config,
        private string $project_root,
        private string $bootstrap_path,
        private string $config_path,
        ?callable $worker_launcher = null,
        ?callable $worker_probe = null,
        ?callable $sleep_fn = null,
        private ?int $max_supervisor_ticks = null,
        private ?BotCommandSynchronizer $command_synchronizer = null
    ) {
        $this->worker_launcher = $worker_launcher;
        $this->worker_probe = $worker_probe;
        $this->sleep_fn = $sleep_fn;
    }

    public function run(): void
    {
        $project_root = realpath($this->project_root);
        $bootstrap_path = realpath($this->bootstrap_path);
        $config_path = realpath($this->config_path);
        if ($project_root === false || $bootstrap_path === false || $config_path === false) {
            throw new \RuntimeException('Cannot resolve outbound worker paths');
        }

        $log_dir = $project_root . '/var/log';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0775, true);
        }

        $this->command_synchronizer?->syncAll();

        $workers = [
            'inbound' => [
                'log_file' => $log_dir . '/telegram-ingress.log',
                'bootstrap_code' => $this->buildWorkerBootstrap('inbound'),
                'pid' => 0,
                'restart_count' => 0,
            ],
            'outbound' => [
                'log_file' => $log_dir . '/router-outbound.log',
                'bootstrap_code' => $this->buildWorkerBootstrap('outbound'),
                'pid' => 0,
                'restart_count' => 0,
            ],
        ];

        if (!$this->config->telegram_polling_enabled) {
            unset($workers['inbound']);
            RuntimeLog::info('Telegram polling worker disabled by config');
        }

        foreach ($workers as $name => &$worker) {
            $worker['pid'] = $this->launchWorker(
                $name,
                $project_root,
                $bootstrap_path,
                $config_path,
                $worker['log_file'],
                $worker['bootstrap_code']
            );
            RuntimeLog::info('Started transport worker', [
                'worker' => $name,
                'pid' => $worker['pid'],
                'log_file' => $worker['log_file'],
            ]);
        }
        unset($worker);

        $ticks = 0;
        while (true) {
            foreach ($workers as $name => &$worker) {
                if ($this->isWorkerAlive((int) $worker['pid'])) {
                    continue;
                }

                RuntimeLog::error('Transport worker exited', [
                    'worker' => $name,
                    'pid' => $worker['pid'],
                    'restart_count' => $worker['restart_count'],
                ]);

                $worker['restart_count']++;
                $worker['pid'] = $this->launchWorker(
                    $name,
                    $project_root,
                    $bootstrap_path,
                    $config_path,
                    $worker['log_file'],
                    $worker['bootstrap_code']
                );

                RuntimeLog::info('Transport worker restarted', [
                    'worker' => $name,
                    'pid' => $worker['pid'],
                    'restart_count' => $worker['restart_count'],
                    'log_file' => $worker['log_file'],
                ]);
            }
            unset($worker);

            $ticks++;
            if ($this->max_supervisor_ticks !== null && $ticks >= $this->max_supervisor_ticks) {
                return;
            }

            $this->pause(250);
        }
    }

    private function buildWorkerBootstrap(string $worker): string
    {
        $factory = match ($worker) {
            'inbound' => 'createIngressRunner',
            'outbound' => 'createOutboundRunner',
            default => throw new \RuntimeException('Unknown worker: ' . $worker),
        };

        return <<<PHP
<?php
chdir(%s);
require %s;
use CodexMultitenant\\TransportTelegram\\RuntimeFactory;
use CodexMultitenant\\TransportTelegram\\RuntimeLog;
\$log_file = %s;
RuntimeLog::setObserver(static function (array \$payload) use (\$log_file): void {
    \$log_dir = dirname(\$log_file);
    if (!is_dir(\$log_dir)) {
        mkdir(\$log_dir, 0775, true);
    }

    \$encoded = json_encode(\$payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    \$line = \$encoded !== false ? \$encoded : ((string) (\$payload['level'] ?? 'INFO')) . ' ' . ((string) (\$payload['message'] ?? ''));
    \$stream = fopen(\$log_file, 'ab');
    if (\$stream === false) {
        throw new \\RuntimeException('Cannot open worker log file: ' . \$log_file);
    }

    fwrite(\$stream, \$line . PHP_EOL);
    fclose(\$stream);
});
try {
    \$runner = RuntimeFactory::{$factory}(%s);
    \$runner->run();
} catch (\\Throwable \$e) {
    RuntimeLog::error('Transport worker crashed', [
        'worker' => '{$worker}',
        'error' => \$e->getMessage(),
        'exception_class' => \$e::class,
    ]);
    throw \$e;
}
PHP;
    }

    private function launchWorker(
        string $name,
        string $project_root,
        string $bootstrap_path,
        string $config_path,
        string $log_file,
        string $bootstrap_code
    ): int {
        if ($this->worker_launcher !== null) {
            return (int) ($this->worker_launcher)($name, $project_root, $bootstrap_path, $config_path, $log_file);
        }

        $process = BackgroundProcess::create($bootstrap_code)->run(
            $project_root,
            $bootstrap_path,
            $log_file,
            $config_path
        );

        return $process->getPid();
    }

    private function isWorkerAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if ($this->worker_probe !== null) {
            return (bool) ($this->worker_probe)($pid);
        }

        if (function_exists('posix_kill') && @posix_kill($pid, 0) === true) {
            return true;
        }

        $output = [];
        $exit_code = 1;
        exec('ps -p ' . $pid, $output, $exit_code);

        return $exit_code === 0 && count($output) > 1;
    }

    private function pause(int $milliseconds): void
    {
        if ($this->sleep_fn !== null) {
            ($this->sleep_fn)($milliseconds);
            return;
        }

        usleep($milliseconds * 1000);
    }
}
