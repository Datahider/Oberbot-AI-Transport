<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class BotAwareIncomingUpdateHandler implements IncomingUpdateHandlerInterface
{
    public function __construct(
        private BotRecord $bot,
        private BotRegistryInterface $registry,
        private ChatLockInterface $lock,
        private BotHandoverHandler $handover,
        private IncomingUpdateHandlerInterface $next,
        private ?BotManagementHandler $management = null,
        private ?StatusMessageResetInterface $status_reset = null
    ) {
    }

    public function handleIncomingUpdate(array $update): array
    {
        $message = $update['message'] ?? null;
        $chat = is_array($message) ? ($message['chat'] ?? null) : null;
        if (is_array($chat) && in_array(($chat['type'] ?? null), ['group', 'supergroup'], true) && $this->mustIgnoreGroupCommand($message)) {
            return ['accepted' => false, 'messages' => []];
        }

        if ($this->handover->handle($this->bot, $update)) {
            return ['accepted' => true, 'messages' => []];
        }
        if ($this->management?->handle($update)) {
            return ['accepted' => true, 'messages' => []];
        }

        if (is_array($chat) && in_array(($chat['type'] ?? null), ['group', 'supergroup'], true) && isset($chat['id'])) {
            $active_bot = $this->registry->activeBotForChat((string) $chat['id']);
            if ($active_bot->bot_key !== $this->bot->bot_key) {
                RuntimeLog::info('Ignored group update received by inactive bot', [
                    'chat_id' => (string) $chat['id'],
                    'bot_key' => $this->bot->bot_key,
                    'active_bot_key' => $active_bot->bot_key,
                ]);
                return ['accepted' => false, 'messages' => []];
            }
        }
        if (is_array($chat) && ($chat['type'] ?? null) === 'private' && isset($chat['id'])) {
            $chat_id = (string) $chat['id'];
            $this->lock->synchronized('telegram-bot-route:' . $chat_id, function () use ($chat_id): void {
                $active_bot = $this->registry->activeBotForChat($chat_id);
                $this->registry->setActiveBotForChat($chat_id, $this->bot->bot_key, 'private');
                if ($active_bot->bot_key !== $this->bot->bot_key) {
                    $this->status_reset?->resetForChat($chat_id);
                }
            });
        }

        return $this->next->handleIncomingUpdate($update);
    }

    /** @param array<string, mixed> $message */
    private function mustIgnoreGroupCommand(array $message): bool
    {
        $text = trim((string) ($message['text'] ?? ''));
        if (preg_match('/^\/done(?:@\S+)?(?:\s|$)/ui', $text) === 1) {
            return true;
        }
        if (preg_match('/^\/[a-z0-9_]+@([a-z0-9_]+)(?:\s|$)/i', $text, $matches) !== 1) {
            return false;
        }

        return strcasecmp($matches[1], ltrim($this->bot->username, '@')) !== 0;
    }
}
