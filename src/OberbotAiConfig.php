<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use RuntimeException;

final class OberbotAiConfig
{
    public function __construct(
        public readonly string $hidden_chat_id,
        public readonly string $ai_timer_subject,
        public readonly int $turn_timeout_seconds = 12,
        public readonly int $heartbeat_coverage_seconds = 4
    ) {
        if (trim($hidden_chat_id) === '' || trim($ai_timer_subject) === '') {
            throw new RuntimeException('Oberbot hidden chat and AI timer subject are required');
        }
        if ($turn_timeout_seconds < 1 || $heartbeat_coverage_seconds < 1) {
            throw new RuntimeException('Oberbot accounting intervals must be positive');
        }
    }
}
