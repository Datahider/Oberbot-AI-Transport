<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class TelegramUpdateNormalizer
{
    /**
     * @param array<string, mixed> $update
     */
    public function normalize(array $update): ?TelegramInboundMessage
    {
        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $text = $this->extractText($message);
        $attachments = $this->extractAttachments($message);
        if ($text === '' && $attachments === []) {
            return null;
        }

        $chat = $message['chat'] ?? null;
        if (!is_array($chat)) {
            return null;
        }

        $chat_id = $chat['id'] ?? null;
        if (!is_int($chat_id) && !is_string($chat_id)) {
            return null;
        }

        $thread_id = $message['message_thread_id'] ?? null;
        if (!is_int($thread_id) && !is_string($thread_id) && $thread_id !== null) {
            $thread_id = null;
        }

        $message_id = $message['message_id'] ?? null;
        if (!is_int($message_id) && !is_string($message_id) && $message_id !== null) {
            $message_id = null;
        }

        $chat_key = (string) $chat_id;
        $thread_value = is_numeric($thread_id) ? (int) $thread_id : null;
        $message_value = is_numeric($message_id) ? (int) $message_id : null;

        return new TelegramInboundMessage(
            opaque: $this->opaqueFor($chat_key, $thread_value),
            chat_id: $chat_key,
            thread_id: $thread_value,
            text: $text,
            message_id: $message_value,
            attachments: $attachments,
            meta: array_filter([
                'transport' => 'telegram',
                'chat_id' => is_numeric($chat_key) ? (int) $chat_key : $chat_key,
                'thread_id' => $thread_value,
                'message_id' => $message_value,
                'chat_type' => is_string($chat['type'] ?? null) ? trim((string) $chat['type']) : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')
        );
    }

    public function opaqueFor(string $chat_id, ?int $thread_id): string
    {
        if ($thread_id === null || $thread_id <= 0) {
            return $chat_id;
        }

        return $chat_id . ':' . $thread_id;
    }

    /**
     * @param array<string, mixed> $message
     * @return list<array<string, mixed>>
     */
    private function extractAttachments(array $message): array
    {
        $attachments = [];

        $document = $this->normalizeFileAttachment($message['document'] ?? null, 'document', 'document');
        if ($document !== null) {
            $attachments[] = $document;
        }

        $photo = $this->normalizePhotoAttachment($message['photo'] ?? null);
        if ($photo !== null) {
            $attachments[] = $photo;
        }

        $audio = $this->normalizeFileAttachment($message['audio'] ?? null, 'audio', 'audio');
        if ($audio !== null) {
            $attachments[] = $audio;
        }

        $video = $this->normalizeFileAttachment($message['video'] ?? null, 'video', 'video');
        if ($video !== null) {
            $attachments[] = $video;
        }

        $voice = $this->normalizeFileAttachment($message['voice'] ?? null, 'voice', 'voice');
        if ($voice !== null) {
            $attachments[] = $voice;
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function extractText(array $message): string
    {
        $text = $message['text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        $caption = $message['caption'] ?? null;
        if (is_string($caption) && trim($caption) !== '') {
            return trim($caption);
        }

        return '';
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>|null
     */
    private function normalizeFileAttachment(mixed $payload, string $telegram_type, string $attachment_type): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $file_id = trim((string) ($payload['file_id'] ?? ''));
        if ($file_id === '') {
            return null;
        }

        $name = trim((string) ($payload['file_name'] ?? ''));
        $mime = trim((string) ($payload['mime_type'] ?? ''));
        $size_bytes = isset($payload['file_size']) && is_numeric($payload['file_size'])
            ? (int) $payload['file_size']
            : null;

        return array_filter([
            'telegram_type' => $telegram_type,
            'attachment_type' => $attachment_type,
            'file_id' => $file_id,
            'name' => $name !== '' ? $name : null,
            'mime' => $mime !== '' ? $mime : null,
            'size_bytes' => $size_bytes,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>|null
     */
    private function normalizePhotoAttachment(mixed $payload): ?array
    {
        if (!is_array($payload) || $payload === []) {
            return null;
        }

        $variants = array_values(array_filter($payload, 'is_array'));
        if ($variants === []) {
            return null;
        }

        usort($variants, static function (array $left, array $right): int {
            return ((int) ($right['file_size'] ?? 0)) <=> ((int) ($left['file_size'] ?? 0));
        });

        return $this->normalizeFileAttachment($variants[0], 'photo', 'image');
    }
}
