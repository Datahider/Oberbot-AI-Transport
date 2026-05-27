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

        $text = $message['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
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
            text: trim($text),
            message_id: $message_value,
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
}
