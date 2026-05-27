<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CodexMultitenant\TransportTelegram\OutboundEvent;
use CodexMultitenant\TransportTelegram\OutboundRunner;
use CodexMultitenant\TransportTelegram\RouterClientInterface;
use CodexMultitenant\TransportTelegram\StatusState;
use CodexMultitenant\TransportTelegram\StatusStateStoreInterface;
use CodexMultitenant\TransportTelegram\TelegramClientInterface;
use CodexMultitenant\TransportTelegram\TelegramTransport;
use CodexMultitenant\TransportTelegram\TelegramTransportConfig;
use CodexMultitenant\TransportTelegram\TelegramUpdateNormalizer;
use CodexMultitenant\TransportTelegram\TenantMapInterface;
use CodexMultitenant\TransportTelegram\CursorStoreInterface;

function testConfigRequiresTelegramToken(): void
{
    $config_path = createTestConfig([
        'router' => [
            'base_url' => 'https://router.example',
            'transport_token' => 'router-token',
        ],
        'telegram' => [
            'base_url' => 'https://api.telegram.org',
            'endpoint_prefix' => 'bot',
        ],
        'transport' => [
            'transport_instance_id' => 'telegram-main',
            'outbound_poll_interval_ms' => 250,
            'telegram_poll_timeout_seconds' => 20,
            'unknown_chat_text' => 'unknown',
            'status_instruction_text' => 'pin this',
            'idle_status_text' => 'idle',
        ],
        'db' => [
            'host' => '127.0.0.1',
            'user' => 'user',
            'pass' => 'pass',
            'name' => 'db',
            'prefix' => 'tt_',
        ],
    ]);

    try {
        TelegramTransportConfig::fromConfigPath($config_path);
        fail('Expected missing telegram token to fail');
    } catch (RuntimeException $e) {
        assertSame('Missing required config value: telegram.bot_token', $e->getMessage(), 'missing telegram token');
    } finally {
        removeTestConfig($config_path);
    }
}

function testNormalizerBuildsOpaqueWithThread(): void
{
    $normalizer = new TelegramUpdateNormalizer();

    $message = $normalizer->normalize([
        'update_id' => 10,
        'message' => [
            'message_id' => 501,
            'message_thread_id' => 77,
            'text' => ' hello ',
            'chat' => [
                'id' => -100123,
                'type' => 'supergroup',
            ],
        ],
    ]);

    assertSame('-100123:77', $message->opaque, 'opaque with thread');
    assertSame('-100123', $message->chat_id, 'chat id');
    assertSame(77, $message->thread_id, 'thread id');
    assertSame('hello', $message->text, 'trimmed text');
}

function testNormalizerRejectsUnsupportedUpdate(): void
{
    $normalizer = new TelegramUpdateNormalizer();

    $message = $normalizer->normalize([
        'update_id' => 11,
        'edited_message' => [
            'text' => 'ignored',
        ],
    ]);

    assertNull($message, 'unsupported update');
}

function testStatusCommandDefaultsToEnabled(): void
{
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        new FakeTelegramClient(),
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore()
    );

    $reply = $transport->handleIncomingUpdate([
        'update_id' => 12,
        'message' => [
            'message_id' => 900,
            'text' => '/status',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
        ],
    ]);

    assertSame('Статус: on', $reply['messages'][0]['text'] ?? null, 'status text');
}

function testUnknownChatReplySkipsRouterIngress(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();

    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap([]),
        new InMemoryStatusStateStore()
    );

    $transport->handleIncomingUpdate([
        'update_id' => 13,
        'message' => [
            'message_id' => 901,
            'text' => 'hello',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
        ],
    ]);

    assertSame([], $router->ingress_payloads, 'router ingress skipped');
    assertSame('unknown chat', $telegram->sent_messages[0]['text'] ?? null, 'unknown chat text');
}

function testCommentarySendsTypingAfterMessage(): void
{
    $telegram = new FakeTelegramClient();
    $status = new InMemoryStatusStateStore();
    $status->save(new StatusState('100', true, null, null));

    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        $status
    );

    $transport->deliverOutboundEvent(new OutboundEvent(
        event_id: 14,
        opaque: '100',
        kind: 'commentary',
        text: 'Watch `logs`',
        attachments: [],
        meta: []
    ));

    assertSame('<i>Watch <code>logs</code></i>', $telegram->sent_messages[0]['text'] ?? null, 'commentary text');
    assertSame('typing', $telegram->chat_actions[0]['action'] ?? null, 'typing after commentary');
}

function testStatusEventCreatesInstructionAndStatusMessage(): void
{
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore()
    );

    $transport->deliverOutboundEvent(new OutboundEvent(
        event_id: 15,
        opaque: '100',
        kind: 'status',
        text: 'Busy: 42',
        attachments: [],
        meta: []
    ));

    assertSame('pin this', $telegram->sent_messages[0]['text'] ?? null, 'instruction text');
    assertSame('Busy: 42', $telegram->sent_messages[1]['text'] ?? null, 'status message');
}

function testOutboundRunnerAdvancesCursorAfterSuccessfulDelivery(): void
{
    $telegram = new FakeTelegramClient();
    $cursor_store = new InMemoryCursorStore();
    $router = new SequencedOutboundRouterClient([
        [[
            'event_id' => 51,
            'opaque' => '100',
            'kind' => 'final',
            'text' => 'done',
            'attachments' => [],
            'meta' => [],
        ]],
    ]);
    $status_store = new InMemoryStatusStateStore();
    $status_store->save(new StatusState('100', true, null, null));
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        $status_store
    );

    $runner = new OutboundRunner($router, $transport, $cursor_store);

    try {
        $runner->run();
        fail('Expected runner to stop after fake router exhaustion');
    } catch (RuntimeException $e) {
        assertSame('stop polling', $e->getMessage(), 'runner stop marker');
    }

    assertSame(51, $cursor_store->router_after_id, 'cursor after successful delivery');
    assertSame('✅ done', $telegram->sent_messages[0]['text'] ?? null, 'delivered final text');
}

function testOutboundRunnerKeepsCursorWhenDeliveryFails(): void
{
    $status_store = new InMemoryStatusStateStore();
    $status_store->save(new StatusState('100', true, null, null));
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        new ThrowingTelegramClient(),
        new InMemoryTenantMap(['100' => 'user@server']),
        $status_store
    );
    $cursor_store = new InMemoryCursorStore();
    $router = new SequencedOutboundRouterClient([
        [[
            'event_id' => 52,
            'opaque' => '100',
            'kind' => 'final',
            'text' => 'broken',
            'attachments' => [],
            'meta' => [],
        ]],
    ]);

    $runner = new OutboundRunner($router, $transport, $cursor_store);

    try {
        $runner->run();
        fail('Expected delivery failure to bubble');
    } catch (RuntimeException $e) {
        assertSame('delivery failed', $e->getMessage(), 'delivery failure marker');
    }

    assertSame(0, $cursor_store->router_after_id, 'cursor after failed delivery');
}

function buildConfig(): TelegramTransportConfig
{
    return new TelegramTransportConfig(
        router_base_url: 'https://router.example',
        router_token: 'router-token',
        telegram_bot_token: 'telegram-token',
        telegram_base_url: 'https://api.telegram.org',
        telegram_endpoint_prefix: 'bot',
        transport_instance_id: 'telegram-main',
        outbound_poll_interval_ms: 250,
        telegram_poll_timeout_seconds: 20,
        unknown_chat_text: 'unknown chat',
        status_instruction_text: 'pin this',
        idle_status_text: 'idle'
    );
}

final class FakeRouterClient implements RouterClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $ingress_payloads = [];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postIngress(array $payload): array
    {
        $this->ingress_payloads[] = $payload;

        return ['accepted' => true, 'event_id' => 1];
    }

    public function getOutboundEvents(int $after_id, int $wait, int $limit): array
    {
        return [];
    }
}

final class SequencedOutboundRouterClient implements RouterClientInterface
{
    /**
     * @param list<list<array<string, mixed>>> $batches
     */
    public function __construct(private array $batches)
    {
    }

    public function postIngress(array $payload): array
    {
        throw new RuntimeException('not used');
    }

    public function getOutboundEvents(int $after_id, int $wait, int $limit): array
    {
        if ($this->batches === []) {
            throw new RuntimeException('stop polling');
        }

        return array_shift($this->batches);
    }
}

class FakeTelegramClient implements TelegramClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $sent_messages = [];

    /** @var list<array<string, mixed>> */
    public array $edited_messages = [];

    /** @var list<array<string, mixed>> */
    public array $chat_actions = [];

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(int|string $chat_id, string $text, ?int $reply_to_message_id = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $message_thread_id = null): array
    {
        $message_id = count($this->sent_messages) + 1000;
        $this->sent_messages[] = [
            'chat_id' => $chat_id,
            'text' => $text,
            'reply_to_message_id' => $reply_to_message_id,
            'parse_mode' => $parse_mode,
            'disable_notification' => $disable_notification,
            'message_thread_id' => $message_thread_id,
            'message_id' => $message_id,
        ];

        return [
            'ok' => true,
            'result' => [
                'message_id' => $message_id,
            ],
        ];
    }

    public function editMessageText(int|string $chat_id, int $message_id, string $text, ?string $parse_mode = null, ?int $message_thread_id = null): void
    {
        $this->edited_messages[] = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text,
            'parse_mode' => $parse_mode,
            'message_thread_id' => $message_thread_id,
        ];
    }

    public function sendChatAction(int|string $chat_id, string $action = 'typing', ?int $message_thread_id = null): void
    {
        $this->chat_actions[] = [
            'chat_id' => $chat_id,
            'action' => $action,
            'message_thread_id' => $message_thread_id,
        ];
    }

    public function getUpdates(?int $offset, int $timeout, int $limit = 100): array
    {
        return [];
    }
}

final class InMemoryTenantMap implements TenantMapInterface
{
    /**
     * @param array<string, string> $map
     */
    public function __construct(private array $map)
    {
    }

    public function tenantForChat(int|string $chat_id): ?string
    {
        return $this->map[(string) $chat_id] ?? null;
    }
}

final class InMemoryStatusStateStore implements StatusStateStoreInterface
{
    /** @var array<string, StatusState> */
    private array $items = [];

    public function load(string $opaque): StatusState
    {
        return $this->items[$opaque] ?? new StatusState($opaque, true, null, null);
    }

    public function save(StatusState $state): void
    {
        $this->items[$state->opaque] = $state;
    }
}

final class InMemoryCursorStore implements CursorStoreInterface
{
    public int $router_after_id = 0;
    public int $telegram_offset = 0;

    public function loadRouterAfterId(): int
    {
        return $this->router_after_id;
    }

    public function saveRouterAfterId(int $after_id): void
    {
        $this->router_after_id = $after_id;
    }

    public function loadTelegramOffset(): int
    {
        return $this->telegram_offset;
    }

    public function saveTelegramOffset(int $offset): void
    {
        $this->telegram_offset = $offset;
    }
}

final class ThrowingTelegramClient extends FakeTelegramClient
{
    public function sendMessage(int|string $chat_id, string $text, ?int $reply_to_message_id = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $message_thread_id = null): array
    {
        throw new RuntimeException('delivery failed');
    }
}

testConfigRequiresTelegramToken();
testNormalizerBuildsOpaqueWithThread();
testNormalizerRejectsUnsupportedUpdate();
testStatusCommandDefaultsToEnabled();
testUnknownChatReplySkipsRouterIngress();
testCommentarySendsTypingAfterMessage();
testStatusEventCreatesInstructionAndStatusMessage();
testOutboundRunnerAdvancesCursorAfterSuccessfulDelivery();
testOutboundRunnerKeepsCursorWhenDeliveryFails();

fwrite(STDOUT, "TelegramTransport tests: OK\n");
