<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use losthost\BackgroundProcess\BackgroundProcess;

final class MainRunner
{
    public function __construct(
        private TelegramTransportConfig $config,
        private IngressRunner $ingress,
        private string $project_root,
        private string $bootstrap_path,
        private string $config_path
    ) {
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

        $outbound_log = $log_dir . '/router-outbound.log';
        $outbound_process = BackgroundProcess::create(<<<'PHP'
<?php
chdir(%s);
require %s;
use CodexMultitenant\TransportTelegram\RuntimeFactory;
$runner = RuntimeFactory::createOutboundRunner(%s);
$runner->run();
PHP)->run(
            $project_root,
            $bootstrap_path,
            $config_path,
            $outbound_log
        );

        RuntimeLog::info('Started outbound background worker', [
            'pid' => $outbound_process->getPid(),
        ]);

        $this->ingress->run();
    }
}
