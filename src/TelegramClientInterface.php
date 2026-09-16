<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface TelegramClientInterface
{
    /**
     * @param list<array{command: string, description: string}> $commands
     */
    public function setMyCommands(array $commands): void;

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

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(
        int|string $chat_id,
        string $file_path,
        ?string $caption = null,
        ?string $parse_mode = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array;

    /** @return array<string, mixed> */
    public function sendPhoto(
        int|string $chat_id,
        string $file_path,
        ?string $caption = null,
        ?string $parse_mode = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array;

    /** @return array<string, mixed> */
    public function sendVoice(
        int|string $chat_id,
        string $file_path,
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

    /**
     * @return array<string, mixed>
     */
    public function sendAnimation(
        int|string $chat_id,
        string $animation,
        ?string $caption = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array;

    public function deleteMessage(int|string $chat_id, int $message_id): void;

    /**
     * @return array<string, mixed>
     */
    public function getChatMember(int|string $chat_id, int $user_id): array;

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

    /**
     * @return array<string, mixed>
     */
    public function getFile(string $file_id): array;

    public function downloadFile(string $file_path): string;
}
