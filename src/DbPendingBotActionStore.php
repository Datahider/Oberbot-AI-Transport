<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Data\PendingBotAction;
use Throwable;

final class DbPendingBotActionStore implements PendingBotActionStoreInterface
{
    public function begin(string $bot_key, string $chat_id, int $user_id, string $action, int $expires_at): void
    {
        try { $pending = new PendingBotAction(compact('bot_key', 'chat_id', 'user_id')); }
        catch (Throwable $e) {
            if ($e->getCode() !== -10002) throw $e;
            $pending = new PendingBotAction(); $pending->bot_key = $bot_key; $pending->chat_id = $chat_id; $pending->user_id = $user_id;
        }
        $pending->action = $action;
        $pending->expires_at = $expires_at;
        $pending->token_message_id = 0;
        $pending->write();
    }

    public function find(string $bot_key, string $chat_id, int $user_id, int $now): ?string
    {
        try { $pending = new PendingBotAction(compact('bot_key', 'chat_id', 'user_id')); }
        catch (Throwable $e) {
            if ($e->getCode() !== -10002) throw $e;
            return null;
        }
        if ((int) $pending->expires_at >= $now) return (string) $pending->action;
        $pending->delete();
        return null;
    }

    public function tokenMessageWasDeleted(string $bot_key, string $chat_id, int $user_id, int $message_id): bool
    {
        $pending = new PendingBotAction(compact('bot_key', 'chat_id', 'user_id'));
        return (int) $pending->token_message_id === $message_id;
    }

    public function markTokenMessageDeleted(string $bot_key, string $chat_id, int $user_id, int $message_id): void
    {
        $pending = new PendingBotAction(compact('bot_key', 'chat_id', 'user_id'));
        $pending->token_message_id = $message_id;
        $pending->write();
    }

    public function clear(string $bot_key, string $chat_id, int $user_id): void
    {
        $pending = new PendingBotAction(compact('bot_key', 'chat_id', 'user_id'));
        $pending->delete();
    }
}
