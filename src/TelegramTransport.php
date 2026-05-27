<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use RuntimeException;
use Throwable;

final class TelegramTransport
{
    private readonly TelegramUpdateNormalizer $normalizer;
    private readonly TelegramTextRenderer $renderer;

    public function __construct(
        private TelegramTransportConfig $config,
        private RouterClientInterface $router,
        private TelegramClientInterface $telegram,
        private TenantMapInterface $tenant_map,
        private StatusStateStoreInterface $status_store
    ) {
        $this->normalizer = new TelegramUpdateNormalizer();
        $this->renderer = new TelegramTextRenderer();
    }

    /**
     * @param array<string, mixed> $update
     * @return array{accepted: bool, messages: list<array<string, mixed>>}
     */
    public function handleIncomingUpdate(array $update): array
    {
        $message = $this->normalizer->normalize($update);
        if ($message === null) {
            RuntimeLog::info('Ignored unsupported Telegram update', [
                'update_id' => $update['update_id'] ?? null,
            ]);
            return [
                'accepted' => false,
                'messages' => [],
            ];
        }

        $status_command = $this->parseStatusCommand($message->text);
        if ($status_command !== null) {
            RuntimeLog::info('Handling local status command', [
                'chat_id' => $message->chat_id,
                'opaque' => $message->opaque,
                'command_mode' => $status_command,
            ]);
            return [
                'accepted' => true,
                'messages' => [$this->handleStatusCommand($message, $status_command)],
            ];
        }

        $tenant = $this->tenant_map->tenantForChat($message->chat_id);
        if ($tenant === null) {
            RuntimeLog::info('Rejected inbound message without tenant binding', [
                'chat_id' => $message->chat_id,
                'opaque' => $message->opaque,
            ]);
            return [
                'accepted' => false,
                'messages' => [
                    $this->sendTextReply(
                        $message,
                        $this->config->unknown_chat_text,
                        null,
                        true
                    ),
                ],
            ];
        }

        $response = $this->router->postIngress([
            'tenant' => $tenant,
            'opaque' => $message->opaque,
            'kind' => 'message',
            'text' => $message->text,
            'attachments' => [],
            'meta' => $message->meta,
        ]);
        RuntimeLog::info('Forwarded inbound message to Router', [
            'chat_id' => $message->chat_id,
            'opaque' => $message->opaque,
            'tenant' => $tenant,
            'accepted' => $response['accepted'] ?? null,
            'event_id' => $response['event_id'] ?? null,
        ]);

        $messages = [];
        $action = is_array($response['action'] ?? null) ? $response['action'] : [];
        if (($response['accepted'] ?? null) === false && ($action['type'] ?? null) === 'reply_text') {
            $text = trim((string) ($action['text'] ?? ''));
            if ($text !== '') {
                $messages[] = $this->sendTextReply($message, $text, null, true);
            }
        }

        return [
            'accepted' => (bool) ($response['accepted'] ?? false),
            'messages' => $messages,
        ];
    }

    public function deliverOutboundEvent(OutboundEvent $event): void
    {
        [$chat_id, $thread_id] = $this->resolveOpaque($event->opaque);
        RuntimeLog::info('Dispatching outbound event to Telegram', [
            'event_id' => $event->event_id,
            'kind' => $event->kind,
            'chat_id' => $chat_id,
            'thread_id' => $thread_id,
            'opaque' => $event->opaque,
        ]);

        if ($event->kind === 'heartbeat') {
            $this->telegram->sendChatAction($chat_id, 'typing', $thread_id);
            RuntimeLog::info('Telegram chat action sent', [
                'event_id' => $event->event_id,
                'chat_id' => $chat_id,
                'thread_id' => $thread_id,
                'action' => 'typing',
            ]);
            return;
        }

        if ($event->kind === 'status') {
            $this->deliverStatus($event, $chat_id, $thread_id);
            return;
        }

        if ($event->kind === 'commentary') {
            $text = $this->renderer->renderCommentary($event->text);
            if ($text !== '') {
                $result = $this->telegram->sendMessage($chat_id, $text, null, 'HTML', true, $thread_id);
                RuntimeLog::info('Telegram commentary sent', [
                    'event_id' => $event->event_id,
                    'chat_id' => $chat_id,
                    'thread_id' => $thread_id,
                    'message_id' => $result['result']['message_id'] ?? $result['message_id'] ?? null,
                ]);
                $this->telegram->sendChatAction($chat_id, 'typing', $thread_id);
                RuntimeLog::info('Telegram chat action sent', [
                    'event_id' => $event->event_id,
                    'chat_id' => $chat_id,
                    'thread_id' => $thread_id,
                    'action' => 'typing',
                ]);
            }
            return;
        }

        $text = '✅ ' . ltrim($this->renderer->renderFinal($event->text));
        if (trim($text) === '✅') {
            return;
        }

        $result = $this->telegram->sendMessage($chat_id, $text, null, 'HTML', false, $thread_id);
        RuntimeLog::info('Telegram final message sent', [
            'event_id' => $event->event_id,
            'chat_id' => $chat_id,
            'thread_id' => $thread_id,
            'message_id' => $result['result']['message_id'] ?? $result['message_id'] ?? null,
        ]);
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    public function resolveOpaque(string $opaque): array
    {
        $opaque = trim($opaque);
        if ($opaque === '') {
            throw new RuntimeException('Outbound opaque cannot be empty');
        }

        if (!str_contains($opaque, ':')) {
            return [$opaque, null];
        }

        [$chat_id, $thread] = explode(':', $opaque, 2);

        return [$chat_id, is_numeric($thread) ? (int) $thread : null];
    }

    private function deliverStatus(OutboundEvent $event, string $chat_id, ?int $thread_id): void
    {
        $state = $this->status_store->load($event->opaque);
        if (!$state->enabled) {
            return;
        }

        $rendered = $this->renderer->renderFinal($event->text);
        if ($rendered === '') {
            $rendered = htmlspecialchars($this->config->idle_status_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($state->message_id !== null && $state->message_id > 0) {
            try {
                $this->telegram->editMessageText($chat_id, $state->message_id, $rendered, 'HTML', $thread_id);
                $this->status_store->save($state->withMessage($state->message_id, $event->text));
                RuntimeLog::info('Telegram status message updated', [
                    'event_id' => $event->event_id,
                    'chat_id' => $chat_id,
                    'thread_id' => $thread_id,
                    'message_id' => $state->message_id,
                ]);
                return;
            } catch (Throwable) {
                RuntimeLog::error('Telegram status update failed, recreating', [
                    'event_id' => $event->event_id,
                    'chat_id' => $chat_id,
                    'thread_id' => $thread_id,
                    'message_id' => $state->message_id,
                ]);
            }
        }

        $this->telegram->sendMessage($chat_id, $this->config->status_instruction_text, null, null, true, $thread_id);
        $message = $this->telegram->sendMessage($chat_id, $rendered, null, 'HTML', true, $thread_id);
        $created_id = $message['result']['message_id'] ?? $message['message_id'] ?? null;
        $this->status_store->save($state->withMessage(is_numeric($created_id) ? (int) $created_id : null, $event->text));
        RuntimeLog::info('Telegram status message created', [
            'event_id' => $event->event_id,
            'chat_id' => $chat_id,
            'thread_id' => $thread_id,
            'message_id' => is_numeric($created_id) ? (int) $created_id : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleStatusCommand(TelegramInboundMessage $message, ?string $mode): array
    {
        $state = $this->status_store->load($message->opaque);

        if ($mode === 'off') {
            $state = $state->withEnabled(false);
            $this->status_store->save($state);

            return $this->sendTextReply($message, 'Статус: off', null, true);
        }

        if ($mode === 'on') {
            $state = $state->withEnabled(true);
            $this->status_store->save($state);
            $this->ensureStatusMessage($message, $state);

            return $this->sendTextReply($message, 'Статус: on', null, true);
        }

        return $this->sendTextReply($message, 'Статус: ' . ($state->enabled ? 'on' : 'off'), null, true);
    }

    private function ensureStatusMessage(TelegramInboundMessage $message, StatusState $state): void
    {
        if (!$state->enabled) {
            return;
        }

        $text = $state->last_text !== null && trim($state->last_text) !== ''
            ? $this->renderer->renderFinal($state->last_text)
            : htmlspecialchars($this->config->idle_status_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($state->message_id !== null && $state->message_id > 0) {
            try {
                $this->telegram->editMessageText($message->chat_id, $state->message_id, $text, 'HTML', $message->thread_id);
                return;
            } catch (Throwable) {
            }
        }

        $this->telegram->sendMessage($message->chat_id, $this->config->status_instruction_text, null, null, true, $message->thread_id);
        $created = $this->telegram->sendMessage($message->chat_id, $text, null, 'HTML', true, $message->thread_id);
        $created_id = $created['result']['message_id'] ?? $created['message_id'] ?? null;
        $this->status_store->save($state->withMessage(is_numeric($created_id) ? (int) $created_id : null, $state->last_text));
    }

    private function parseStatusCommand(string $text): ?string
    {
        if (!preg_match('/^\/status(?:@\S+)?(?:\s+(on|off))?\s*$/ui', trim($text), $matches)) {
            return null;
        }

        return isset($matches[1]) ? strtolower((string) $matches[1]) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function sendTextReply(
        TelegramInboundMessage $message,
        string $text,
        ?string $parse_mode,
        bool $disable_notification
    ): array {
        $result = $this->telegram->sendMessage(
            $message->chat_id,
            $text,
            $message->message_id,
            $parse_mode,
            $disable_notification,
            $message->thread_id
        );

        $result['text'] = $text;

        return $result;
    }
}
