#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexMultitenant\TransportTelegram\OberbotRuntimeConfig;
use CodexMultitenant\TransportTelegram\RuntimeFactory;

$config_path = (string) ($argv[1] ?? '');
$job_path = (string) ($argv[2] ?? '');
if ($config_path === '' || !is_file($job_path)) throw new RuntimeException('Usage: submit-oberbot-ticket.php <config-path> <job-path>');
$job = json_decode((string) file_get_contents($job_path), true);
if (!is_array($job)) throw new RuntimeException('Oberbot handoff job must be a JSON object');
$runtime = OberbotRuntimeConfig::fromConfigPath($config_path);
if (!hash_equals($runtime->submit_secret, (string) ($job['secret'] ?? ''))) throw new RuntimeException('Invalid Oberbot submit secret');
unset($job['secret']);
$record = RuntimeFactory::createOberbotTicketSubmitter($config_path)->submit($job);
echo json_encode(['accepted' => true, 'hidden_thread_id' => $record->hidden_thread_id], JSON_UNESCAPED_SLASHES) . PHP_EOL;
