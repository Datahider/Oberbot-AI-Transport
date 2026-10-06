<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use Closure;

final class BotManagementHandler
{
    private const PENDING_SECONDS = 300;

    public function __construct(
        private BotRecord $current_bot,
        private BotRegistryInterface $registry,
        private PendingBotActionStoreInterface $pending,
        private BotManagementClientFactoryInterface $clients,
        private string $webhook_base_url,
        private string $webhook_ip_address,
        private Closure $clock
    ) {}

    public function handle(array $update): bool
    {
        $message = $update['message'] ?? null;
        $chat = is_array($message) ? ($message['chat'] ?? null) : null;
        $from = is_array($message) ? ($message['from'] ?? null) : null;
        if (!is_array($message) || !is_array($chat) || !is_array($from) || ($chat['type'] ?? null) !== 'private') return false;
        if (!isset($chat['id'], $from['id'], $message['message_id']) || !is_string($message['text'] ?? null)) return false;

        $chat_id = (string) $chat['id'];
        $user_id = (int) $from['id'];
        $text = trim($message['text']);
        $client = $this->clients->create($this->current_bot);
        $now = ($this->clock)();

        if ($text === '/updatetoken') {
            $official = $this->registry->protectedBots();
            shuffle($official);
            $names = array_map(fn(BotRecord $bot): string => '@' . $bot->username, array_slice($official, 0, 5));
            $instruction = "Перевыпустите token в BotFather. Затем откройте одного из официальных ботов, выполните /addbot и отправьте ему новый token. Группы и привязки сохранятся.";
            if ($names !== []) $instruction .= "\n\n" . implode("\n", $names);
            $client->sendMessage($chat_id, $instruction);
            return true;
        }

        if (in_array($text, ['/addbot', '/delbot'], true)) {
            $action = $text === '/addbot' ? 'add' : 'delete';
            $this->pending->begin($this->current_bot->bot_key, $chat_id, $user_id, $action, $now + self::PENDING_SECONDS);
            $client->sendMessage($chat_id, $action === 'add' ? 'Пришлите token нового бота.' : 'Пришлите token этого бота для удаления.');
            return true;
        }

        $action = $this->pending->find($this->current_bot->bot_key, $chat_id, $user_id, $now);
        if ($action === null) return false;

        $message_id = (int) $message['message_id'];
        if (!$this->pending->tokenMessageWasDeleted($this->current_bot->bot_key, $chat_id, $user_id, $message_id)) {
            $client->deleteMessage($chat_id, $message_id);
            $this->pending->markTokenMessageDeleted($this->current_bot->bot_key, $chat_id, $user_id, $message_id);
        }
        $token_client = $this->clients->createFromToken($text);
        $identity = $token_client->getMe();
        $telegram_user_id = (int) ($identity['id'] ?? 0);
        $username = (string) ($identity['username'] ?? '');

        if ($action === 'add') {
            if ($telegram_user_id <= 0 || $username === '') {
                $client->sendMessage($chat_id, 'Этот бот не может быть зарегистрирован.');
                $this->pending->clear($this->current_bot->bot_key, $chat_id, $user_id);
                return true;
            }
            $existing = $this->registry->botByTelegramUserId($telegram_user_id);
            $bot_key = $existing?->bot_key ?? bin2hex(random_bytes(16));
            $webhook_secret = $existing?->webhook_secret ?? bin2hex(random_bytes(32));
            $this->registry->register(new BotRecord(
                $bot_key,
                $telegram_user_id,
                $username,
                $text,
                $webhook_secret,
                $existing?->is_default ?? false,
                $existing?->deletion_protected ?? false
            ));
            $token_client->setMyCommands(TelegramBotCommandCatalog::all());
            $token_client->setWebhook(rtrim($this->webhook_base_url, '/') . '/' . $bot_key, $webhook_secret, $this->webhook_ip_address);
            $client->sendMessage($chat_id, $existing === null ? 'Бот @' . $username . ' подключён.' : 'Token бота @' . $username . ' обновлён.');
            $this->pending->clear($this->current_bot->bot_key, $chat_id, $user_id);
            return true;
        }

        if ($telegram_user_id !== $this->current_bot->telegram_user_id) {
            $client->sendMessage($chat_id, 'Token принадлежит другому боту.');
            $this->pending->clear($this->current_bot->bot_key, $chat_id, $user_id);
            return true;
        }
        if ($this->current_bot->deletion_protected) {
            $client->sendMessage($chat_id, 'Этот бот защищён от удаления.');
            $this->pending->clear($this->current_bot->bot_key, $chat_id, $user_id);
            return true;
        }

        $official = $this->registry->protectedBots();
        shuffle($official);
        $names = array_map(fn(BotRecord $bot): string => '@' . $bot->username, array_slice($official, 0, 5));
        $farewell = 'Всем спасибо, я пошел. Продолжить вы можете с другими ботами:';
        if ($names !== []) $farewell .= "\n" . implode("\n", $names);
        foreach ($this->registry->activeGroupChatIds($this->current_bot->bot_key) as $group_chat_id) {
            $client->sendMessage($group_chat_id, $farewell);
            $client->leaveChat($group_chat_id);
        }
        $client->sendMessage($chat_id, 'Бот удалён.');
        $this->registry->deleteRoutesForBot($this->current_bot->bot_key);
        $token_client->deleteWebhook();
        $this->registry->deleteBot($this->current_bot->bot_key);
        $this->pending->clear($this->current_bot->bot_key, $chat_id, $user_id);
        return true;
    }
}
