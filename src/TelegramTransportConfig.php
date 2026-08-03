<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use RuntimeException;

final class TelegramTransportConfig
{
    public const CONFIG_PATH = __DIR__ . '/../etc/config.php';

    public function __construct(
        public readonly string $router_base_url,
        public readonly string $router_token,
        public readonly string $telegram_bot_token,
        public readonly string $telegram_base_url,
        public readonly string $telegram_endpoint_prefix,
        public readonly string $file_exchange_base_url,
        public readonly string $file_exchange_token,
        public readonly string $transport_instance_id,
        public readonly int $outbound_poll_interval_ms,
        public readonly int $telegram_poll_timeout_seconds,
        public readonly string $unknown_chat_text,
        public readonly string $status_instruction_text,
        public readonly string $idle_status_text,
        public readonly string $done_forum_topic_icon_custom_emoji_id = '',
        public readonly string $db_host = '',
        public readonly string $db_user = '',
        public readonly string $db_pass = '',
        public readonly string $db_name = '',
        public readonly string $db_prefix = ''
    ) {
    }

    public static function fromDefaultConfig(): self
    {
        return self::fromConfigPath(self::CONFIG_PATH);
    }

    public static function fromConfigPath(string $config_path): self
    {
        $config = self::loadConfig($config_path);
        $router = self::requireArray($config, 'router');
        $telegram = self::requireArray($config, 'telegram');
        $file_exchange = self::requireArray($config, 'file_exchange');
        $transport = self::requireArray($config, 'transport');
        $db = self::requireArray($config, 'db');

        $router_base_url = trim((string) ($router['base_url'] ?? ''));
        $router_token = trim((string) ($router['transport_token'] ?? ''));
        $telegram_bot_token = trim((string) ($telegram['bot_token'] ?? ''));
        $telegram_base_url = rtrim(trim((string) ($telegram['base_url'] ?? '')), '/');
        $telegram_endpoint_prefix = trim((string) ($telegram['endpoint_prefix'] ?? 'bot'), '/');
        $file_exchange_base_url = rtrim(trim((string) ($file_exchange['base_url'] ?? '')), '/');
        $file_exchange_token = trim((string) ($file_exchange['token'] ?? ''));
        $transport_instance_id = trim((string) ($transport['transport_instance_id'] ?? ''));
        $unknown_chat_text = trim((string) ($transport['unknown_chat_text'] ?? ''));
        $status_instruction_text = trim((string) ($transport['status_instruction_text'] ?? ''));
        $idle_status_text = trim((string) ($transport['idle_status_text'] ?? ''));
        $done_forum_topic_icon_custom_emoji_id = trim((string) ($transport['done_forum_topic_icon_custom_emoji_id'] ?? ''));

        if ($router_base_url === '') {
            throw new RuntimeException('Missing required config value: router.base_url');
        }

        if ($router_token === '') {
            throw new RuntimeException('Missing required config value: router.transport_token');
        }

        if ($telegram_bot_token === '') {
            throw new RuntimeException('Missing required config value: telegram.bot_token');
        }

        if ($telegram_base_url === '') {
            throw new RuntimeException('Missing required config value: telegram.base_url');
        }

        if ($transport_instance_id === '') {
            throw new RuntimeException('Missing required config value: transport.transport_instance_id');
        }

        if ($file_exchange_base_url === '') {
            throw new RuntimeException('Missing required config value: file_exchange.base_url');
        }

        if ($file_exchange_token === '') {
            throw new RuntimeException('Missing required config value: file_exchange.token');
        }

        if ($unknown_chat_text === '') {
            throw new RuntimeException('Missing required config value: transport.unknown_chat_text');
        }

        if ($status_instruction_text === '') {
            throw new RuntimeException('Missing required config value: transport.status_instruction_text');
        }

        if ($idle_status_text === '') {
            throw new RuntimeException('Missing required config value: transport.idle_status_text');
        }

        $db_host = trim((string) ($db['host'] ?? ''));
        $db_user = trim((string) ($db['user'] ?? ''));
        $db_pass = (string) ($db['pass'] ?? '');
        $db_name = trim((string) ($db['name'] ?? ''));
        $db_prefix = trim((string) ($db['prefix'] ?? ''));

        if ($db_host === '') {
            throw new RuntimeException('Missing required config value: db.host');
        }

        if ($db_user === '') {
            throw new RuntimeException('Missing required config value: db.user');
        }

        if ($db_name === '') {
            throw new RuntimeException('Missing required config value: db.name');
        }

        if ($db_prefix === '') {
            throw new RuntimeException('Missing required config value: db.prefix');
        }

        return new self(
            router_base_url: $router_base_url,
            router_token: $router_token,
            telegram_bot_token: $telegram_bot_token,
            telegram_base_url: $telegram_base_url,
            telegram_endpoint_prefix: $telegram_endpoint_prefix,
            file_exchange_base_url: $file_exchange_base_url,
            file_exchange_token: $file_exchange_token,
            transport_instance_id: $transport_instance_id,
            outbound_poll_interval_ms: max(100, (int) ($transport['outbound_poll_interval_ms'] ?? 250)),
            telegram_poll_timeout_seconds: max(1, (int) ($transport['telegram_poll_timeout_seconds'] ?? 20)),
            unknown_chat_text: $unknown_chat_text,
            status_instruction_text: $status_instruction_text,
            idle_status_text: $idle_status_text,
            done_forum_topic_icon_custom_emoji_id: $done_forum_topic_icon_custom_emoji_id,
            db_host: $db_host,
            db_user: $db_user,
            db_pass: $db_pass,
            db_name: $db_name,
            db_prefix: $db_prefix
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadConfig(string $config_path): array
    {
        $config_path = trim($config_path);
        if ($config_path === '') {
            throw new RuntimeException('Runtime config path cannot be empty');
        }

        if (!is_file($config_path)) {
            throw new RuntimeException('Missing runtime config: etc/config.php');
        }

        $config = require $config_path;
        if (!is_array($config)) {
            throw new RuntimeException('Runtime config must return array: etc/config.php');
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function requireArray(array $config, string $key): array
    {
        $value = $config[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException("Missing required config section: {$key}");
        }

        return $value;
    }
}
