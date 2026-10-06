<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Router\RouterUnavailableException;
use JsonException;
use RuntimeException;
use Throwable;

final class WebhookEndpoint
{
    public function __construct(
        private string $secret_token,
        private IncomingUpdateHandlerInterface $handler
    ) {
        if ($this->secret_token === '') {
            throw new RuntimeException('Telegram webhook secret token is required');
        }
    }

    /**
     * @param array<string, string> $headers
     */
    public function handle(string $method, array $headers, string $body): WebhookResponse
    {
        if (strtoupper($method) !== 'POST') {
            return new WebhookResponse(405);
        }

        $normalized_headers = array_change_key_case($headers, CASE_LOWER);
        $provided_secret = (string) ($normalized_headers['x-telegram-bot-api-secret-token'] ?? '');
        if (!hash_equals($this->secret_token, $provided_secret)) {
            return new WebhookResponse(401);
        }

        try {
            $update = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new WebhookResponse(400);
        }

        if (!is_array($update) || array_is_list($update)) {
            return new WebhookResponse(400);
        }

        try {
            $this->handler->handleIncomingUpdate($update);
        } catch (RouterUnavailableException | TelegramUnavailableException | TelegramRateLimitException $e) {
            RuntimeLog::error('Telegram webhook update temporarily unavailable', [
                'update_id' => $update['update_id'] ?? null,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            return new WebhookResponse(503);
        } catch (Throwable $e) {
            RuntimeLog::error('Telegram webhook update failed', [
                'update_id' => $update['update_id'] ?? null,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            return new WebhookResponse(500);
        }

        RuntimeLog::info('Telegram webhook update handled', [
            'update_id' => $update['update_id'] ?? null,
        ]);
        return new WebhookResponse(204);
    }
}
