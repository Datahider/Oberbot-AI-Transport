<?php

declare(strict_types=1);

return [
    'router' => [
        'base_url' => 'https://router.example',
        'transport_token' => 'router-transport-token',
    ],
    'telegram' => [
        'bot_token' => 'telegram-bot-token',
        'base_url' => 'https://api.telegram.org',
        'endpoint_prefix' => 'bot',
        'webhook_secret_token' => 'telegram-webhook-secret-token',
    ],
    'transport' => [
        'transport_instance_id' => 'telegram-main',
        'outbound_poll_interval_ms' => 250,
        'telegram_poll_timeout_seconds' => 20,
        'telegram_polling_enabled' => true,
        'unknown_chat_text' => 'Этот чат не привязан к tenant.',
        'status_instruction_text' => 'Закрепите следующее сообщение для отображения текущего статуса.',
        'idle_status_text' => 'Свободен',
        'done_forum_topic_icon_custom_emoji_id' => '5408906741125490282',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'user' => 'db-user',
        'pass' => 'db-pass',
        'name' => 'telegram_transport',
        'prefix' => 'ct_',
    ],
];
