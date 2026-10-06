<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface BotManagementClientInterface extends HandoverBotClientInterface
{
    /** @return array<string, mixed> */
    public function getMe(): array;
    /** @param list<array{command: string, description: string}> $commands */
    public function setMyCommands(array $commands): void;
    public function setWebhook(string $url, string $secret_token, string $ip_address): void;
    public function deleteWebhook(): void;
    public function deleteMessage(int|string $chat_id, int $message_id): void;
}
