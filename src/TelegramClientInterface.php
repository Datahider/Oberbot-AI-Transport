<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface TelegramClientInterface
{
    /**
     * @return array<string, mixed>
     */
    public function sendMessage(
        int|string $chat_id,
        string $text,
        ?int $reply_to_message_id = null,
        ?string $parse_mode = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array;

    public function editMessageText(
        int|string $chat_id,
        int $message_id,
        string $text,
        ?string $parse_mode = null,
        ?int $message_thread_id = null
    ): void;

    public function sendChatAction(
        int|string $chat_id,
        string $action = 'typing',
        ?int $message_thread_id = null
    ): void;

    public function editForumTopic(
        int|string $chat_id,
        int $message_thread_id,
        ?string $name = null,
        ?string $icon_custom_emoji_id = null
    ): void;

    public function closeForumTopic(int|string $chat_id, int $message_thread_id): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function getUpdates(?int $offset, int $timeout, int $limit = 100): array;
}
