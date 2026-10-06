<?php

declare(strict_types=1);

use CodexMultitenant\TransportTelegram\IncomingUpdateHandlerInterface;
use CodexMultitenant\TransportTelegram\Router\RouterUnavailableException;
use CodexMultitenant\TransportTelegram\TelegramUnavailableException;
use CodexMultitenant\TransportTelegram\WebhookEndpoint;

require_once __DIR__ . '/bootstrap.php';

final class FakeIncomingUpdateHandler implements IncomingUpdateHandlerInterface
{
    /** @var list<array<string, mixed>> */
    public array $updates = [];
    public ?Throwable $failure = null;

    public function handleIncomingUpdate(array $update): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->updates[] = $update;

        return ['accepted' => true, 'messages' => []];
    }
}

function createWebhookEndpoint(FakeIncomingUpdateHandler $handler): WebhookEndpoint
{
    return new WebhookEndpoint('test-secret', $handler);
}

function testWebhookRejectsWrongMethod(): void
{
    $handler = new FakeIncomingUpdateHandler();
    $response = createWebhookEndpoint($handler)->handle('GET', [], '');

    assertSame(405, $response->status_code, 'webhook method status');
    assertSame([], $handler->updates, 'webhook method handler calls');
}

function testWebhookRejectsMissingOrWrongSecret(): void
{
    $handler = new FakeIncomingUpdateHandler();
    $endpoint = createWebhookEndpoint($handler);

    assertSame(401, $endpoint->handle('POST', [], '{}')->status_code, 'missing secret status');
    assertSame(401, $endpoint->handle('POST', ['x-telegram-bot-api-secret-token' => 'wrong'], '{}')->status_code, 'wrong secret status');
    assertSame([], $handler->updates, 'secret handler calls');
}

function testWebhookRejectsInvalidJson(): void
{
    $handler = new FakeIncomingUpdateHandler();
    $headers = ['x-telegram-bot-api-secret-token' => 'test-secret'];
    $endpoint = createWebhookEndpoint($handler);

    assertSame(400, $endpoint->handle('POST', $headers, '')->status_code, 'empty json status');
    assertSame(400, $endpoint->handle('POST', $headers, '[]')->status_code, 'list json status');
    assertSame(400, $endpoint->handle('POST', $headers, '{')->status_code, 'invalid json status');
    assertSame([], $handler->updates, 'invalid json handler calls');
}

function testWebhookHandlesTelegramUpdate(): void
{
    $handler = new FakeIncomingUpdateHandler();
    $response = createWebhookEndpoint($handler)->handle(
        'POST',
        ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'],
        '{"update_id":123,"message":{"text":"hello"}}'
    );

    assertSame(204, $response->status_code, 'success status');
    assertSame([['update_id' => 123, 'message' => ['text' => 'hello']]], $handler->updates, 'handled update');
}

function testWebhookReturnsRetryableStatusForTemporaryFailures(): void
{
    foreach ([
        new RouterUnavailableException('router unavailable'),
        new TelegramUnavailableException('telegram unavailable'),
    ] as $failure) {
        $handler = new FakeIncomingUpdateHandler();
        $handler->failure = $failure;
        $response = createWebhookEndpoint($handler)->handle(
            'POST',
            ['x-telegram-bot-api-secret-token' => 'test-secret'],
            '{"update_id":124}'
        );

        assertSame(503, $response->status_code, 'temporary failure status');
    }
}

function testWebhookReturnsServerErrorForUnexpectedFailure(): void
{
    $handler = new FakeIncomingUpdateHandler();
    $handler->failure = new RuntimeException('unexpected');
    $response = createWebhookEndpoint($handler)->handle(
        'POST',
        ['x-telegram-bot-api-secret-token' => 'test-secret'],
        '{"update_id":125}'
    );

    assertSame(500, $response->status_code, 'unexpected failure status');
}

testWebhookRejectsWrongMethod();
testWebhookRejectsMissingOrWrongSecret();
testWebhookRejectsInvalidJson();
testWebhookHandlesTelegramUpdate();
testWebhookReturnsRetryableStatusForTemporaryFailures();
testWebhookReturnsServerErrorForUnexpectedFailure();
