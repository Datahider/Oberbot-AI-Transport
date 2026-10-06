<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface PendingBotActionStoreInterface
{
    public function begin(string $bot_key, string $chat_id, int $user_id, string $action, int $expires_at): void;
    public function find(string $bot_key, string $chat_id, int $user_id, int $now): ?string;
    public function tokenMessageWasDeleted(string $bot_key, string $chat_id, int $user_id, int $message_id): bool;
    public function markTokenMessageDeleted(string $bot_key, string $chat_id, int $user_id, int $message_id): void;
    public function clear(string $bot_key, string $chat_id, int $user_id): void;
}
