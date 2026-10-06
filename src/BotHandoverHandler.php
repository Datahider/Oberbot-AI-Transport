<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class BotHandoverHandler
{
    public function __construct(
        private BotRegistryInterface $registry,
        private ChatLockInterface $lock,
        private BotClientFactoryInterface $clients
    ) {
    }

    /** @param array<string, mixed> $update */
    public function handle(BotRecord $new_bot, array $update): bool
    {
        $membership = $update['my_chat_member'] ?? null;
        if (!is_array($membership)) {
            return false;
        }
        $chat = $membership['chat'] ?? null;
        $new_member = $membership['new_chat_member'] ?? null;
        if (!is_array($chat) || !is_array($new_member)) {
            return false;
        }
        $chat_type = (string) ($chat['type'] ?? '');
        $status = (string) ($new_member['status'] ?? '');
        if (!in_array($chat_type, ['group', 'supergroup'], true) || !in_array($status, ['member', 'administrator'], true)) {
            return false;
        }
        $chat_id = (string) ($chat['id'] ?? '');
        if ($chat_id === '') {
            return false;
        }

        $this->lock->synchronized('telegram-bot-handover:' . $chat_id, function () use ($chat_id, $new_bot): void {
            $old_bot = $this->registry->explicitBotForChat($chat_id) ?? $this->registry->defaultBot();
            if ($old_bot->bot_key === $new_bot->bot_key) {
                $this->registry->setActiveBotForChat($chat_id, $new_bot->bot_key);
                return;
            }

            $old_client = $this->clients->create($old_bot);
            $new_client = $this->clients->create($new_bot);
            $old_client->sendMessage($chat_id, 'О, коллега! Привет. Пост сдал.');
            $new_client->sendMessage($chat_id, 'Пост принял.');
            $this->registry->setActiveBotForChat($chat_id, $new_bot->bot_key);
            $old_client->leaveChat($chat_id);
        });

        return true;
    }
}
