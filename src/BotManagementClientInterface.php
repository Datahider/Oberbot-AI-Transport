<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface BotManagementClientInterface extends HandoverBotClientInterface, TelegramCommandClientInterface
{
    /** @return array<string, mixed> */
    public function getMe(): array;
    public function setWebhook(string $url, string $secret_token, string $ip_address): void;
    public function deleteWebhook(): void;
    public function deleteMessage(int|string $chat_id, int $message_id): void;
}
