<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use RuntimeException;

final class OberbotRuntimeConfig
{
    public function __construct(public readonly OberbotAiConfig $ai, public readonly string $submit_secret) {}
    public static function fromConfigPath(string $path): self
    {
        $config = require $path;
        $section = is_array($config) ? ($config['oberbot'] ?? null) : null;
        if (!is_array($section)) throw new RuntimeException('Missing required config section: oberbot');
        $secret = trim((string) ($section['submit_command_secret'] ?? ''));
        if ($secret === '') throw new RuntimeException('Missing required config value: oberbot.submit_command_secret');
        return new self(new OberbotAiConfig(
            trim((string) ($section['hidden_chat_id'] ?? '')),
            trim((string) ($section['ai_timer_subject'] ?? '')),
            (int) ($section['turn_timeout_seconds'] ?? 12),
            (int) ($section['heartbeat_coverage_seconds'] ?? 4)
        ), $secret);
    }
}
