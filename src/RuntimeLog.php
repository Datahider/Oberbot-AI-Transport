<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class RuntimeLog
{
    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        $payload = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line = $encoded !== false ? $encoded : $level . ' ' . $message;
        fwrite(STDERR, $line . PHP_EOL);
    }
}
