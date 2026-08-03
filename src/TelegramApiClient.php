<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use RuntimeException;

final class TelegramApiClient implements TelegramClientInterface
{
    private const TELEGRAM_TEXT_LIMIT = 4096;

    private string $base_url;

    public function __construct(
        string $bot_token,
        string $base_url = 'https://api.telegram.org',
        string $endpoint_prefix = 'bot'
    ) {
        $token = trim($bot_token);
        if ($token === '') {
            throw new RuntimeException('Telegram bot token is required');
        }

        $base_url = rtrim(trim($base_url), '/');
        if ($base_url === '') {
            throw new RuntimeException('Telegram API base URL is required');
        }

        $endpoint_prefix = trim($endpoint_prefix, '/');
        $path = $base_url . '/';
        if ($endpoint_prefix !== '') {
            $path .= $endpoint_prefix;
        }

        $this->base_url = $path . $token . '/';
    }

    public function setMyCommands(array $commands): void
    {
        $normalized_commands = [];

        foreach ($commands as $command) {
            $name = trim((string) ($command['command'] ?? ''));
            $description = trim((string) ($command['description'] ?? ''));
            if ($name === '' || $description === '') {
                throw new RuntimeException('Telegram bot command payload is invalid');
            }

            $normalized_commands[] = [
                'command' => $name,
                'description' => $description,
            ];
        }

        $this->request('setMyCommands', [
            'commands' => $normalized_commands,
        ]);
    }

    public function sendMessage(
        int|string $chat_id,
        string $text,
        ?int $reply_to_message_id = null,
        ?string $parse_mode = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Telegram outbound message text cannot be empty');
        }

        $payload = [
            'chat_id' => $chat_id,
            'text' => $text,
            'disable_notification' => $disable_notification,
        ];

        if ($parse_mode !== null && trim($parse_mode) !== '') {
            $payload['parse_mode'] = trim($parse_mode);
        }

        if ($message_thread_id !== null && $message_thread_id > 0) {
            $payload['message_thread_id'] = $message_thread_id;
        }

        if ($reply_to_message_id !== null && $reply_to_message_id > 0) {
            $payload['reply_parameters'] = [
                'message_id' => $reply_to_message_id,
            ];
        }

        return $this->request('sendMessage', $payload);
    }

    public function sendDocument(
        int|string $chat_id,
        string $file_path,
        ?string $caption = null,
        ?string $parse_mode = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array {
        $file_path = trim($file_path);
        if ($file_path === '' || !is_file($file_path)) {
            throw new RuntimeException('Telegram outbound document file is missing');
        }

        $payload = [
            'chat_id' => $chat_id,
            'caption' => $caption !== null ? trim($caption) : null,
            'disable_notification' => $disable_notification,
        ];

        if ($parse_mode !== null && trim($parse_mode) !== '') {
            $payload['parse_mode'] = trim($parse_mode);
        }

        if ($message_thread_id !== null && $message_thread_id > 0) {
            $payload['message_thread_id'] = $message_thread_id;
        }

        return $this->multipartRequest('sendDocument', $payload, [
            'document' => new \CURLFile($file_path, 'text/plain', basename($file_path)),
        ]);
    }

    public function editMessageText(
        int|string $chat_id,
        int $message_id,
        string $text,
        ?string $parse_mode = null,
        ?int $message_thread_id = null
    ): void {
        $payload = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text,
        ];

        if ($parse_mode !== null && trim($parse_mode) !== '') {
            $payload['parse_mode'] = trim($parse_mode);
        }

        if ($message_thread_id !== null && $message_thread_id > 0) {
            $payload['message_thread_id'] = $message_thread_id;
        }

        $this->request('editMessageText', $payload);
    }

    public function sendChatAction(
        int|string $chat_id,
        string $action = 'typing',
        ?int $message_thread_id = null
    ): void {
        $payload = [
            'chat_id' => $chat_id,
            'action' => trim($action) !== '' ? trim($action) : 'typing',
        ];

        if ($message_thread_id !== null && $message_thread_id > 0) {
            $payload['message_thread_id'] = $message_thread_id;
        }

        $this->request('sendChatAction', $payload);
    }

    public function sendAnimation(
        int|string $chat_id,
        string $animation,
        ?string $caption = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array {
        $animation = trim($animation);
        if ($animation === '') {
            throw new RuntimeException('Telegram animation cannot be empty');
        }

        $payload = [
            'chat_id' => $chat_id,
            'animation' => $animation,
            'disable_notification' => $disable_notification,
        ];

        if ($caption !== null && trim($caption) !== '') {
            $payload['caption'] = trim($caption);
        }

        if ($message_thread_id !== null && $message_thread_id > 0) {
            $payload['message_thread_id'] = $message_thread_id;
        }

        return $this->request('sendAnimation', $payload);
    }

    public function deleteMessage(int|string $chat_id, int $message_id): void
    {
        $this->request('deleteMessage', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
        ]);
    }

    public function editForumTopic(
        int|string $chat_id,
        int $message_thread_id,
        ?string $name = null,
        ?string $icon_custom_emoji_id = null
    ): void {
        $payload = [
            'chat_id' => $chat_id,
            'message_thread_id' => $message_thread_id,
        ];

        if ($name !== null && trim($name) !== '') {
            $payload['name'] = trim($name);
        }

        if ($icon_custom_emoji_id !== null && trim($icon_custom_emoji_id) !== '') {
            $payload['icon_custom_emoji_id'] = trim($icon_custom_emoji_id);
        }

        $this->request('editForumTopic', $payload);
    }

    public function closeForumTopic(int|string $chat_id, int $message_thread_id): void
    {
        $this->request('closeForumTopic', [
            'chat_id' => $chat_id,
            'message_thread_id' => $message_thread_id,
        ]);
    }

    public function getUpdates(?int $offset, int $timeout, int $limit = 100): array
    {
        $payload = [
            'timeout' => max(0, min(50, $timeout)),
            'limit' => max(1, min(100, $limit)),
            'allowed_updates' => ['message'],
        ];

        if ($offset !== null && $offset > 0) {
            $payload['offset'] = $offset;
        }

        $result = $this->request('getUpdates', $payload);
        $updates = $result['result'] ?? [];

        return is_array($updates) ? array_values(array_filter($updates, 'is_array')) : [];
    }

    public function getFile(string $file_id): array
    {
        $file_id = trim($file_id);
        if ($file_id === '') {
            throw new RuntimeException('Telegram file id is required');
        }

        $result = $this->request('getFile', [
            'file_id' => $file_id,
        ]);

        $file = $result['result'] ?? null;
        if (!is_array($file)) {
            throw new TelegramUnavailableException('Telegram API returned invalid getFile payload');
        }

        return $file;
    }

    public function downloadFile(string $file_path): string
    {
        $file_path = ltrim(trim($file_path), '/');
        if ($file_path === '') {
            throw new RuntimeException('Telegram file path is required');
        }

        $url = preg_replace('#/(bot[^/]+/)$#', '/file/$1', $this->base_url);
        if (!is_string($url) || $url === '') {
            throw new RuntimeException('Cannot build Telegram file download URL');
        }

        $ch = curl_init($url . $file_path);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize Telegram file download');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TelegramUnavailableException('Telegram file download failed: ' . $error);
        }

        $status_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status_code < 200 || $status_code >= 300) {
            throw new TelegramUnavailableException('Telegram file download failed with HTTP ' . $status_code);
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, array $payload = []): array
    {
        $ch = curl_init($this->base_url . $method);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize Telegram API request');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Cannot encode Telegram API payload');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => max(10, (($payload['timeout'] ?? 0) + 10)),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TelegramUnavailableException('Telegram API request failed: ' . $error);
        }

        $status_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new TelegramUnavailableException('Telegram API returned invalid JSON');
        }

        if ($status_code >= 400) {
            $description = (string) ($response['description'] ?? 'HTTP ' . $status_code);
            $retry_after = (int) ($response['parameters']['retry_after'] ?? 0);
            if ($status_code === 429 && $retry_after > 0) {
                throw new TelegramRateLimitException('Telegram API request failed: ' . $description, $retry_after);
            }
            if ($status_code >= 500) {
                throw new TelegramUnavailableException('Telegram API request failed: ' . $description);
            }
            throw new RuntimeException('Telegram API request failed: ' . $description);
        }

        if (($response['ok'] ?? false) !== true) {
            $description = (string) ($response['description'] ?? 'request failed');
            $retry_after = (int) ($response['parameters']['retry_after'] ?? 0);
            if ($retry_after > 0) {
                throw new TelegramRateLimitException('Telegram API request failed: ' . $description, $retry_after);
            }
            throw new RuntimeException('Telegram API request failed: ' . $description);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private function multipartRequest(string $method, array $payload, array $files): array
    {
        $ch = curl_init($this->base_url . $method);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize Telegram API request');
        }

        foreach ($files as $key => $value) {
            $payload[$key] = $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TelegramUnavailableException('Telegram API request failed: ' . $error);
        }

        $status_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new TelegramUnavailableException('Telegram API returned invalid JSON');
        }

        if ($status_code >= 400) {
            $description = (string) ($response['description'] ?? 'HTTP ' . $status_code);
            $retry_after = (int) ($response['parameters']['retry_after'] ?? 0);
            if ($status_code === 429 && $retry_after > 0) {
                throw new TelegramRateLimitException('Telegram API request failed: ' . $description, $retry_after);
            }
            if ($status_code >= 500) {
                throw new TelegramUnavailableException('Telegram API request failed: ' . $description);
            }
            throw new RuntimeException('Telegram API request failed: ' . $description);
        }

        if (($response['ok'] ?? false) !== true) {
            $description = (string) ($response['description'] ?? 'request failed');
            $retry_after = (int) ($response['parameters']['retry_after'] ?? 0);
            if ($retry_after > 0) {
                throw new TelegramRateLimitException('Telegram API request failed: ' . $description, $retry_after);
            }
            throw new RuntimeException('Telegram API request failed: ' . $description);
        }

        return $response;
    }
}
