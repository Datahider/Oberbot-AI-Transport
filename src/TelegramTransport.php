<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use RuntimeException;
use Throwable;

final class TelegramTransport implements IncomingUpdateHandlerInterface, OutboundDeliveryInterface
{
    private const TELEGRAM_TEXT_LIMIT = 4096;
    private const MAX_INBOUND_ATTACHMENT_SIZE_BYTES = 20 * 1024 * 1024;
    private const LONG_MESSAGE_CAPTION = 'Ответ получился слишком длинным, поэтому отправил его файлом.';
    private const LONG_WARNING_CAPTION = 'Предупреждение получилось слишком длинным, поэтому отправил его файлом.';
    private const LONG_SYSTEM_CAPTION = 'Системное сообщение получилось слишком длинным, поэтому отправил его файлом.';
    private const OVERSIZED_ATTACHMENT_TEXT = 'Файл больше 20 МБ. Отправьте файл меньше 20 МБ.';
    private const FILE_PROCESSING_TEXT = 'Обработка файла(ов)';

    private readonly TelegramUpdateNormalizer $normalizer;
    private readonly TelegramTextRenderer $renderer;

    public function __construct(
        private TelegramTransportConfig $config,
        private RouterClientInterface $router,
        private TelegramClientInterface $telegram,
        private TenantMapInterface $tenant_map,
        private StatusStateStoreInterface $status_store,
        private IgnoreStateStoreInterface $ignore_store,
        private ?FileExchangeClientInterface $file_exchange = null
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

        if ($this->isGroupMessage($message)) {
            $ignore_scope = $this->ingressScopeFor($message);
            if ($this->ignore_store->isIgnored($ignore_scope)) {
                if ($this->isUnignoreCommand($message->text) && $this->isGroupAdministrator($message)) {
                    $this->ignore_store->setIgnored($ignore_scope, false);
                    RuntimeLog::info('Disabled inbound ignore scope', [
                        'chat_id' => $message->chat_id,
                        'opaque' => $message->opaque,
                        'scope' => $ignore_scope,
                    ]);

                    return [
                        'accepted' => true,
                        'messages' => [$this->sendTextReply($message, 'Игнор: off', null, true)],
                    ];
                }

                RuntimeLog::info('Left ignored group message untouched', [
                    'chat_id' => $message->chat_id,
                    'opaque' => $message->opaque,
                    'scope' => $ignore_scope,
                ]);
                return [
                    'accepted' => false,
                    'messages' => [],
                ];
            }

            if (!$this->isGroupAdministrator($message)) {
                if ($message->message_id === null || $message->message_id <= 0) {
                    throw new RuntimeException('Telegram group message id is required for access filtering');
                }
                $this->telegram->deleteMessage($message->chat_id, $message->message_id);
                RuntimeLog::info('Deleted non-administrator message from active group scope', [
                    'chat_id' => $message->chat_id,
                    'opaque' => $message->opaque,
                    'message_id' => $message->message_id,
                ]);
                return [
                    'accepted' => false,
                    'messages' => [],
                ];
            }
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

        $voice_quote_command = $this->parseVoiceQuoteCommand($message->text);
        if ($voice_quote_command !== null) {
            $voice_quote_enabled = match ($voice_quote_command) {
                'quote' => true,
                'silent' => false,
                'global' => null,
            };
            $state = $this->status_store->load($message->opaque)->withVoiceQuoteEnabled($voice_quote_enabled);
            $this->status_store->save($state);

            return [
                'accepted' => true,
                'messages' => [$this->sendTextReply(
                    $message,
                    'Цитата голоса: ' . match ($voice_quote_command) {
                        'quote' => 'on',
                        'silent' => 'off',
                        'global' => 'global',
                    },
                    null,
                    true
                )],
            ];
        }

        if ($this->isIgnoreCommand($message->text)) {
            $scope = $this->ingressScopeFor($message);
            $this->ignore_store->setIgnored($scope, true);
            RuntimeLog::info('Enabled inbound ignore scope', [
                'chat_id' => $message->chat_id,
                'opaque' => $message->opaque,
                'scope' => $scope,
            ]);

            return [
                'accepted' => true,
                'messages' => [$this->sendTextReply($message, 'Игнор: on', null, true)],
            ];
        }

        if ($this->isUnignoreCommand($message->text)) {
            $scope = $this->ingressScopeFor($message);
            $this->ignore_store->setIgnored($scope, false);
            RuntimeLog::info('Disabled inbound ignore scope', [
                'chat_id' => $message->chat_id,
                'opaque' => $message->opaque,
                'scope' => $scope,
            ]);

            return [
                'accepted' => true,
                'messages' => [$this->sendTextReply($message, 'Игнор: off', null, true)],
            ];
        }

        if ($this->isNoteCommand($message->text)) {
            RuntimeLog::info('Consumed local note command', [
                'chat_id' => $message->chat_id,
                'opaque' => $message->opaque,
            ]);

            return [
                'accepted' => true,
                'messages' => [],
            ];
        }

        if ($this->isDoneCommand($message->text)) {
            return $this->handleDoneCommand($message);
        }

        $oversized_attachment = $this->findOversizedAttachment($message->attachments);
        if ($oversized_attachment !== null) {
            RuntimeLog::info('Rejected oversized inbound attachment', [
                'chat_id' => $message->chat_id,
                'opaque' => $message->opaque,
                'attachment_type' => (string) ($oversized_attachment['attachment_type'] ?? ''),
                'size_bytes' => $oversized_attachment['size_bytes'] ?? null,
                'max_size_bytes' => self::MAX_INBOUND_ATTACHMENT_SIZE_BYTES,
            ]);

            return [
                'accepted' => false,
                'messages' => [
                    $this->sendTextReply($message, self::OVERSIZED_ATTACHMENT_TEXT, null, true),
                ],
            ];
        }

        $ignore_scope = $this->ingressScopeFor($message);
        if ($this->ignore_store->isIgnored($ignore_scope)) {
            RuntimeLog::info('Ignored inbound message by local scope rule', [
                'chat_id' => $message->chat_id,
                'opaque' => $message->opaque,
                'scope' => $ignore_scope,
            ]);

            return [
                'accepted' => false,
                'messages' => [],
            ];
        }

        // Disabled for now: for small files Telegram renders the animation too late
        // to be useful, so the transport skips sending temporary progress feedback.
        $progress_message_id = null;
        $response = $this->router->postIngress([
            'binding' => $message->chat_id,
            'opaque' => $message->opaque,
            'kind' => 'message',
            'text' => $message->text,
            'attachments' => $this->uploadAttachments($message->attachments),
            'meta' => $message->meta,
        ]);
        $this->deleteAttachmentProgressIfNeeded($message, $progress_message_id);
        RuntimeLog::info('Forwarded inbound message to Router', [
            'chat_id' => $message->chat_id,
            'opaque' => $message->opaque,
            'binding' => $message->chat_id,
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
        if (!in_array($event->kind, ['final', 'commentary', 'warning', 'system', 'transcript', 'status', 'heartbeat', 'document', 'image', 'voice'], true)) {
            return;
        }

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

        if ($event->kind === 'document') {
            $this->deliverDocument($event, $chat_id, $thread_id);
            return;
        }

        if ($event->kind === 'image') {
            $this->deliverImage($event, $chat_id, $thread_id);
            return;
        }

        if ($event->kind === 'voice') {
            $this->deliverVoice($event, $chat_id, $thread_id);
            return;
        }

        if ($event->kind === 'transcript') {
            if (!$this->isVoiceQuoteEnabled($event->opaque)) {
                return;
            }
            $text = $this->renderer->renderTranscript($event->text);
            if ($text !== '') {
                $this->sendRenderedOutbound($chat_id, $thread_id, $text, $event->text, false);
            }
            return;
        }

        if ($event->kind === 'commentary') {
            $text = $this->renderer->renderCommentary($event->text);
            if ($text !== '') {
                $result = $this->sendRenderedOutbound($chat_id, $thread_id, $text, $event->text, true);
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

        if ($event->kind === 'warning') {
            $text = '⚠️ ' . ltrim($this->renderer->renderFinal($event->text));
            if (trim($text) === '⚠️') {
                return;
            }

            $this->sendRenderedOutbound(
                $chat_id,
                $thread_id,
                $text,
                $event->text,
                false,
                self::LONG_WARNING_CAPTION
            );
            return;
        }

        if ($event->kind === 'system') {
            $text = '⚙️ ' . ltrim($this->renderer->renderFinal($event->text));
            if (trim($text) === '⚙️') {
                return;
            }

            $this->sendRenderedOutbound(
                $chat_id,
                $thread_id,
                $text,
                $event->text,
                false,
                self::LONG_SYSTEM_CAPTION
            );
            return;
        }

        $text = $this->renderer->renderFinal($event->text);
        if ($text === '') {
            return;
        }

        $result = $this->sendRenderedOutbound($chat_id, $thread_id, $text, $event->text, false);
        RuntimeLog::info('Telegram final message sent', [
            'event_id' => $event->event_id,
            'chat_id' => $chat_id,
            'thread_id' => $thread_id,
            'message_id' => $result['result']['message_id'] ?? $result['message_id'] ?? null,
        ]);
    }

    public function reportOutboundDeliveryFailure(OutboundEvent $event, \Throwable $error): void
    {
        [$chat_id, $thread_id] = $this->resolveOpaque($event->opaque);
        $text = sprintf(
            'Не удалось доставить событие #%d (%s): %s',
            $event->event_id,
            $event->kind,
            $error->getMessage()
        );

        $this->telegram->sendMessage($chat_id, $text, null, null, false, $thread_id);
    }

    private function isGroupMessage(TelegramInboundMessage $message): bool
    {
        return in_array($message->chat_type, ['group', 'supergroup'], true);
    }

    private function isGroupAdministrator(TelegramInboundMessage $message): bool
    {
        if ($message->sender_chat_id === $message->chat_id) {
            return true;
        }
        if ($message->sender_user_id === null) {
            return false;
        }

        $member = $this->telegram->getChatMember($message->chat_id, $message->sender_user_id);

        return in_array((string) ($member['status'] ?? ''), ['creator', 'administrator'], true);
    }

    private function deliverDocument(OutboundEvent $event, string $chat_id, ?int $thread_id): void
    {
        if ($this->file_exchange === null) {
            throw new RuntimeException('File exchange client is required for document delivery');
        }
        if (count($event->attachments) !== 1 || !is_array($event->attachments[0])) {
            throw new RuntimeException('Document outbound requires exactly one attachment');
        }

        $attachment = $event->attachments[0];
        $file_id = trim((string) ($attachment['file_id'] ?? ''));
        $filename = trim((string) ($attachment['name'] ?? ''));
        if ($file_id === '' || $filename === '') {
            throw new RuntimeException('Document attachment requires file_id and name');
        }

        $temp_path = $this->file_exchange->downloadFile($file_id, $filename);
        try {
            $caption = $this->renderer->renderFinal($event->text);
            $this->telegram->sendDocument(
                $chat_id,
                $temp_path,
                $caption !== '' ? $caption : null,
                $caption !== '' ? 'HTML' : null,
                false,
                $thread_id
            );
        } finally {
            if (is_file($temp_path) && !unlink($temp_path)) {
                throw new RuntimeException('Cannot remove temporary outbound document');
            }
        }
    }

    private function deliverImage(OutboundEvent $event, string $chat_id, ?int $thread_id): void
    {
        if ($this->file_exchange === null) {
            throw new RuntimeException('File exchange client is required for image delivery');
        }
        if (count($event->attachments) !== 1 || !is_array($event->attachments[0])) {
            throw new RuntimeException('Image outbound requires exactly one attachment');
        }

        $attachment = $event->attachments[0];
        $file_id = trim((string) ($attachment['file_id'] ?? ''));
        $filename = trim((string) ($attachment['name'] ?? ''));
        $mime = trim((string) ($attachment['mime'] ?? ''));
        if ($file_id === '' || $filename === '' || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Image attachment requires valid file_id, name and mime');
        }

        $temp_path = $this->file_exchange->downloadFile($file_id, $filename);
        try {
            $caption = $this->renderer->renderFinal($event->text);
            $this->telegram->sendPhoto(
                $chat_id,
                $temp_path,
                $caption !== '' ? $caption : null,
                $caption !== '' ? 'HTML' : null,
                false,
                $thread_id
            );
        } finally {
            if (is_file($temp_path) && !unlink($temp_path)) {
                throw new RuntimeException('Cannot remove temporary outbound image');
            }
        }
    }

    private function deliverVoice(OutboundEvent $event, string $chat_id, ?int $thread_id): void
    {
        if ($this->file_exchange === null) {
            throw new RuntimeException('File exchange client is required for voice delivery');
        }
        if (count($event->attachments) !== 1 || !is_array($event->attachments[0])) {
            throw new RuntimeException('Voice outbound requires exactly one attachment');
        }

        $attachment = $event->attachments[0];
        $file_id = trim((string) ($attachment['file_id'] ?? ''));
        $filename = trim((string) ($attachment['name'] ?? ''));
        $mime = trim((string) ($attachment['mime'] ?? ''));
        if ($file_id === '' || $filename === '' || $mime !== 'audio/ogg') {
            throw new RuntimeException('Voice attachment requires valid file_id, name and audio/ogg mime');
        }

        $temp_path = $this->file_exchange->downloadFile($file_id, $filename);
        try {
            $caption = $this->renderer->renderFinal($event->text);
            $this->telegram->sendVoice(
                $chat_id,
                $temp_path,
                $caption !== '' ? $caption : null,
                $caption !== '' ? 'HTML' : null,
                $thread_id
            );
        } finally {
            if (is_file($temp_path) && !unlink($temp_path)) {
                throw new RuntimeException('Cannot remove temporary outbound voice');
            }
        }
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

    private function parseVoiceQuoteCommand(string $text): ?string
    {
        if (preg_match('/^\/voicequote(?:@\S+)?\s*$/ui', trim($text)) === 1) {
            return 'quote';
        }

        if (preg_match('/^\/voicesilent(?:@\S+)?\s*$/ui', trim($text)) === 1) {
            return 'silent';
        }

        if (preg_match('/^\/voiceglobal(?:@\S+)?\s*$/ui', trim($text)) === 1) {
            return 'global';
        }

        return null;
    }

    private function isVoiceQuoteEnabled(string $opaque): bool
    {
        $local = $this->status_store->load($opaque)->voice_quote_enabled;
        if ($local !== null) {
            return $local;
        }

        if (str_contains($opaque, ':')) {
            [$chat_id] = explode(':', $opaque, 2);
            $group = $this->status_store->load($chat_id)->voice_quote_enabled;
            if ($group !== null) {
                return $group;
            }
        }

        return true;
    }

    private function isIgnoreCommand(string $text): bool
    {
        return preg_match('/^\/ignore(?:@\S+)?\s*$/ui', trim($text)) === 1;
    }

    private function isUnignoreCommand(string $text): bool
    {
        return preg_match('/^\/unignore(?:@\S+)?\s*$/ui', trim($text)) === 1;
    }

    private function isNoteCommand(string $text): bool
    {
        return preg_match('/^\/note(?:@\S+)?(?:\s+.*)?$/ui', trim($text)) === 1;
    }

    private function isDoneCommand(string $text): bool
    {
        return preg_match('/^\/done(?:@\S+)?\s*$/ui', trim($text)) === 1;
    }

    /**
     * @return array{accepted: bool, messages: list<array<string, mixed>>}
     */
    private function handleDoneCommand(TelegramInboundMessage $message): array
    {
        if ($message->thread_id === null || $message->thread_id <= 0) {
            RuntimeLog::info('Rejected local done command outside forum topic', [
                'chat_id' => $message->chat_id,
                'opaque' => $message->opaque,
            ]);

            return [
                'accepted' => true,
                'messages' => [
                    $this->sendTextReply($message, 'Команда /done работает только в forum topic.', null, true),
                ],
            ];
        }

        $icon_custom_emoji_id = trim($this->config->done_forum_topic_icon_custom_emoji_id);
        if ($icon_custom_emoji_id === '') {
            RuntimeLog::error('Done command is not configured: missing forum topic icon id', [
                'chat_id' => $message->chat_id,
                'opaque' => $message->opaque,
            ]);

            return [
                'accepted' => true,
                'messages' => [
                    $this->sendTextReply(
                        $message,
                        'Команда /done не настроена: отсутствует done_forum_topic_icon_custom_emoji_id.',
                        null,
                        true
                    ),
                ],
            ];
        }

        try {
            $this->telegram->editForumTopic($message->chat_id, $message->thread_id, null, $icon_custom_emoji_id);
            $this->telegram->closeForumTopic($message->chat_id, $message->thread_id);
        } catch (\Throwable $e) {
            RuntimeLog::error('Done command failed to manage forum topic', [
                'chat_id' => $message->chat_id,
                'opaque' => $message->opaque,
                'thread_id' => $message->thread_id,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            return [
                'accepted' => true,
                'messages' => [
                    $this->sendTextReply(
                        $message,
                        'Команда /done недоступна: у бота нет прав администратора для управления темой.',
                        null,
                        true
                    ),
                ],
            ];
        }

        RuntimeLog::info('Forum topic marked done and closed', [
            'chat_id' => $message->chat_id,
            'opaque' => $message->opaque,
            'thread_id' => $message->thread_id,
            'icon_custom_emoji_id' => $icon_custom_emoji_id,
        ]);

        return [
            'accepted' => true,
            'messages' => [
                $this->sendTextReply($message, 'Тема закрыта.', null, true),
            ],
        ];
    }

    private function ingressScopeFor(TelegramInboundMessage $message): string
    {
        if ($message->thread_id !== null && $message->thread_id > 0) {
            return $message->opaque;
        }

        return $message->chat_id;
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

    /**
     * @return array<string, mixed>
     */
    private function sendRenderedOutbound(
        string $chat_id,
        ?int $thread_id,
        string $rendered_text,
        string $document_text,
        bool $disable_notification,
        string $long_message_caption = self::LONG_MESSAGE_CAPTION
    ): array {
        if (mb_strlen($rendered_text) <= self::TELEGRAM_TEXT_LIMIT) {
            return $this->telegram->sendMessage($chat_id, $rendered_text, null, 'HTML', $disable_notification, $thread_id);
        }

        $file_path = $this->writeLongMessageFile($document_text);

        try {
            return $this->telegram->sendDocument(
                $chat_id,
                $file_path,
                $long_message_caption,
                null,
                $disable_notification,
                $thread_id
            );
        } finally {
            if (is_file($file_path)) {
                @unlink($file_path);
            }
        }
    }

    private function writeLongMessageFile(string $text): string
    {
        $path = rtrim(sys_get_temp_dir(), '/') . '/telegram-outbound-' . bin2hex(random_bytes(8)) . '.txt';
        if (file_put_contents($path, $text) === false) {
            throw new RuntimeException('Cannot write long outbound message file');
        }

        return $path;
    }

    /**
     * @param list<array<string, mixed>> $attachments
     * @return list<array<string, mixed>>
     */
    private function uploadAttachments(array $attachments): array
    {
        if ($attachments === []) {
            return [];
        }

        if ($this->file_exchange === null) {
            throw new RuntimeException('File exchange client is not configured');
        }

        $normalized = [];

        foreach ($attachments as $attachment) {
            $normalized[] = $this->uploadSingleAttachment($attachment);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $attachment
     * @return array<string, mixed>
     */
    private function uploadSingleAttachment(array $attachment): array
    {
        $file_id = trim((string) ($attachment['file_id'] ?? ''));
        if ($file_id === '') {
            throw new RuntimeException('Telegram inbound attachment is missing file_id');
        }

        $file = $this->telegram->getFile($file_id);
        $file_path = trim((string) ($file['file_path'] ?? ''));
        if ($file_path === '') {
            throw new RuntimeException('Telegram getFile response is missing file_path');
        }

        $filename = $this->resolveAttachmentFilename($attachment, $file_id);
        $mime = isset($attachment['mime']) && is_string($attachment['mime']) && trim($attachment['mime']) !== ''
            ? trim((string) $attachment['mime'])
            : null;

        $temp_path = $this->writeInboundTempFile($filename, $this->telegram->downloadFile($file_path));

        try {
            $uploaded = $this->file_exchange->uploadFile($temp_path, $filename, $mime);
        } finally {
            if (is_file($temp_path)) {
                @unlink($temp_path);
            }
        }

        $url = trim((string) ($uploaded['url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('File exchange upload response is missing url');
        }

        $uploaded_name = trim((string) ($uploaded['filename'] ?? ''));
        $uploaded_size = isset($uploaded['size']) && is_numeric($uploaded['size'])
            ? (int) $uploaded['size']
            : null;
        $fallback_size = isset($attachment['size_bytes']) && is_numeric($attachment['size_bytes'])
            ? (int) $attachment['size_bytes']
            : null;
        $normalized = array_filter([
            'type' => trim((string) ($attachment['attachment_type'] ?? '')),
            'url' => $url,
            'name' => $uploaded_name !== '' ? $uploaded_name : $filename,
            'size_bytes' => $uploaded_size ?? $fallback_size,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        RuntimeLog::info('Inbound attachment uploaded to file exchange', $normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $attachment
     */
    private function resolveAttachmentFilename(array $attachment, string $file_id): string
    {
        $name = trim((string) ($attachment['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $telegram_type = trim((string) ($attachment['telegram_type'] ?? 'file'));

        return match ($telegram_type) {
            'photo' => $file_id . '.jpg',
            'audio' => $file_id . '.audio',
            'video' => $file_id . '.video',
            'voice' => $file_id . '.ogg',
            default => $file_id . '.bin',
        };
    }

    private function writeInboundTempFile(string $filename, string $contents): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.' . $extension : '.bin';
        $path = rtrim(sys_get_temp_dir(), '/') . '/telegram-inbound-' . bin2hex(random_bytes(8)) . $suffix;
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Cannot write inbound attachment temp file');
        }

        return $path;
    }

    /**
     * @param list<array<string, mixed>> $attachments
     * @return array<string, mixed>|null
     */
    private function findOversizedAttachment(array $attachments): ?array
    {
        foreach ($attachments as $attachment) {
            $size_bytes = $attachment['size_bytes'] ?? null;
            if (is_numeric($size_bytes) && (int) $size_bytes > self::MAX_INBOUND_ATTACHMENT_SIZE_BYTES) {
                return $attachment;
            }
        }

        return null;
    }

    private function sendAttachmentProgressIfNeeded(TelegramInboundMessage $message): ?int
    {
        if ($message->attachments === []) {
            return null;
        }

        $result = $this->telegram->sendAnimation(
            $message->chat_id,
            $this->progressAnimationPath(),
            self::FILE_PROCESSING_TEXT,
            true,
            $message->thread_id
        );

        $message_id = $result['result']['message_id'] ?? $result['message_id'] ?? null;

        return is_numeric($message_id) ? (int) $message_id : null;
    }

    private function deleteAttachmentProgressIfNeeded(TelegramInboundMessage $message, ?int $progress_message_id): void
    {
        if ($progress_message_id === null || $progress_message_id <= 0) {
            return;
        }

        $this->telegram->deleteMessage($message->chat_id, $progress_message_id);
    }

    private function progressAnimationPath(): string
    {
        $path = dirname(__DIR__) . '/config/googler-loader.gif';
        if (!is_file($path)) {
            throw new RuntimeException('Progress animation asset is missing');
        }

        return $path;
    }
}
