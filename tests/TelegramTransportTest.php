<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CodexMultitenant\TransportTelegram\OutboundEvent;
use CodexMultitenant\TransportTelegram\OutboundRunner;
use CodexMultitenant\TransportTelegram\RouterClientInterface;
use CodexMultitenant\TransportTelegram\IgnoreStateStoreInterface;
use CodexMultitenant\TransportTelegram\IngressRunner;
use CodexMultitenant\TransportTelegram\RuntimeLog;
use CodexMultitenant\TransportTelegram\StatusState;
use CodexMultitenant\TransportTelegram\StatusStateStoreInterface;
use CodexMultitenant\TransportTelegram\TelegramBotCommandCatalog;
use CodexMultitenant\TransportTelegram\TelegramClientInterface;
use CodexMultitenant\TransportTelegram\TelegramRateLimitException;
use CodexMultitenant\TransportTelegram\TelegramTextRenderer;
use CodexMultitenant\TransportTelegram\TelegramTransport;
use CodexMultitenant\TransportTelegram\TelegramTransportConfig;
use CodexMultitenant\TransportTelegram\TelegramUnavailableException;
use CodexMultitenant\TransportTelegram\TelegramUpdateNormalizer;
use CodexMultitenant\TransportTelegram\TenantMapInterface;
use CodexMultitenant\TransportTelegram\CursorStoreInterface;
use CodexMultitenant\TransportTelegram\Router\RouterUnavailableException;

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
        'file_exchange' => [
            'base_url' => 'https://files.ioannidis.ru',
            'token' => 'exchange-token',
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

function testConfigRequiresFileExchangeToken(): void
{
    $config_path = createTestConfig([
        'router' => [
            'base_url' => 'https://router.example',
            'transport_token' => 'router-token',
        ],
        'telegram' => [
            'bot_token' => 'telegram-token',
            'base_url' => 'https://api.telegram.org',
            'endpoint_prefix' => 'bot',
        ],
        'file_exchange' => [
            'base_url' => 'https://files.ioannidis.ru',
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
        fail('Expected missing file exchange token to fail');
    } catch (RuntimeException $e) {
        assertSame('Missing required config value: file_exchange.token', $e->getMessage(), 'missing file exchange token');
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

function testNormalizerBuildsInboundDocumentAttachment(): void
{
    $normalizer = new TelegramUpdateNormalizer();

    $message = $normalizer->normalize([
        'update_id' => 10,
        'message' => [
            'message_id' => 502,
            'caption' => 'doc caption',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
            'document' => [
                'file_id' => 'doc-file-id',
                'file_name' => 'report.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 12345,
            ],
        ],
    ]);

    assertSame('doc caption', $message->text, 'document caption');
    assertSame([[
        'telegram_type' => 'document',
        'attachment_type' => 'document',
        'file_id' => 'doc-file-id',
        'name' => 'report.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 12345,
    ]], $message->attachments, 'document attachment');
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

function testNormalizerRejectsVoiceOnlyUpdate(): void
{
    $normalizer = new TelegramUpdateNormalizer();

    $message = $normalizer->normalize([
        'update_id' => 11,
        'message' => [
            'message_id' => 503,
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
            'voice' => [
                'file_id' => 'voice-file-id',
                'file_size' => 123,
            ],
        ],
    ]);

    assertNull($message, 'voice update unsupported');
}

function testMediaMessageUploadsSupportedTelegramFilesAndForwardsAttachments(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $exchange = new FakeFileExchangeClient();
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore(),
        $exchange
    );

    $telegram->file_results = [
        'doc-file-id' => ['file_path' => 'documents/report.pdf'],
        'photo-large' => ['file_path' => 'photos/large.jpg'],
        'audio-file-id' => ['file_path' => 'audio/theme.mp3'],
        'video-file-id' => ['file_path' => 'video/demo.mp4'],
    ];
    $telegram->downloaded_files = [
        'documents/report.pdf' => 'PDF',
        'photos/large.jpg' => 'JPEG',
        'audio/theme.mp3' => 'MP3',
        'video/demo.mp4' => 'MP4',
    ];
    $exchange->upload_results = [
        ['file_id' => 'D1', 'url' => 'https://files.ioannidis.ru/D1', 'filename' => 'report.pdf', 'size' => 111],
        ['file_id' => 'P1', 'url' => 'https://files.ioannidis.ru/P1', 'filename' => 'photo.jpg', 'size' => 222],
        ['file_id' => 'A1', 'url' => 'https://files.ioannidis.ru/A1', 'filename' => 'theme.mp3', 'size' => 333],
        ['file_id' => 'V1', 'url' => 'https://files.ioannidis.ru/V1', 'filename' => 'demo.mp4', 'size' => 444],
    ];

    $result = $transport->handleIncomingUpdate([
        'update_id' => 30,
        'message' => [
            'message_id' => 930,
            'caption' => 'files attached',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
            'document' => [
                'file_id' => 'doc-file-id',
                'file_name' => 'report.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 12345,
            ],
            'photo' => [
                [
                    'file_id' => 'photo-small',
                    'file_size' => 10,
                    'width' => 32,
                    'height' => 32,
                ],
                [
                    'file_id' => 'photo-large',
                    'file_size' => 20,
                    'width' => 1280,
                    'height' => 720,
                ],
            ],
            'audio' => [
                'file_id' => 'audio-file-id',
                'file_name' => 'theme.mp3',
                'mime_type' => 'audio/mpeg',
                'file_size' => 67890,
            ],
            'video' => [
                'file_id' => 'video-file-id',
                'file_name' => 'demo.mp4',
                'mime_type' => 'video/mp4',
                'file_size' => 98765,
            ],
        ],
    ]);

    assertSame(true, $result['accepted'], 'media accepted');
    assertTrue(str_ends_with((string) ($telegram->sent_animations[0]['animation'] ?? ''), '/config/googler-loader.gif'), 'progress animation asset path');
    assertSame(true, $telegram->sent_animations[0]['file_exists'] ?? null, 'progress animation uploaded as file');
    assertTrue(($telegram->sent_animations[0]['content_length'] ?? 0) > 0, 'progress animation file content captured');
    assertSame('Обработка файла(ов)', $telegram->sent_animations[0]['caption'] ?? null, 'progress animation caption');
    assertSame(3000, $telegram->deleted_messages[0]['message_id'] ?? null, 'progress animation deleted');
    assertSame('files attached', $router->ingress_payloads[0]['text'] ?? null, 'media caption forwarded');
    assertSame([
        [
            'type' => 'document',
            'url' => 'https://files.ioannidis.ru/D1',
            'name' => 'report.pdf',
            'size_bytes' => 111,
        ],
        [
            'type' => 'image',
            'url' => 'https://files.ioannidis.ru/P1',
            'name' => 'photo.jpg',
            'size_bytes' => 222,
        ],
        [
            'type' => 'audio',
            'url' => 'https://files.ioannidis.ru/A1',
            'name' => 'theme.mp3',
            'size_bytes' => 333,
        ],
        [
            'type' => 'video',
            'url' => 'https://files.ioannidis.ru/V1',
            'name' => 'demo.mp4',
            'size_bytes' => 444,
        ],
    ], $router->ingress_payloads[0]['attachments'] ?? null, 'forwarded attachments');
    assertSame(['doc-file-id', 'photo-large', 'audio-file-id', 'video-file-id'], $telegram->get_file_calls, 'getFile calls');
    assertSame(['documents/report.pdf', 'photos/large.jpg', 'audio/theme.mp3', 'video/demo.mp4'], $telegram->download_file_calls, 'download calls');
    assertSame(['report.pdf', 'photo-large.jpg', 'theme.mp3', 'demo.mp4'], array_column($exchange->uploads, 'filename'), 'uploaded filenames');
}

function testOversizedMediaMessageRepliesWithDiagnosticAndSkipsUpload(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $exchange = new FakeFileExchangeClient();
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore(),
        $exchange
    );

    $result = $transport->handleIncomingUpdate([
        'update_id' => 31,
        'message' => [
            'message_id' => 931,
            'caption' => 'big file',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
            'document' => [
                'file_id' => 'big-doc-file-id',
                'file_name' => 'big.bin',
                'mime_type' => 'application/octet-stream',
                'file_size' => (20 * 1024 * 1024) + 1,
            ],
        ],
    ]);

    assertSame(false, $result['accepted'], 'oversized media rejected');
    assertSame('Файл больше 20 МБ. Отправьте файл меньше 20 МБ.', $result['messages'][0]['text'] ?? null, 'oversized media diagnostic');
    assertSame([], $router->ingress_payloads, 'oversized media not forwarded');
    assertSame([], $telegram->get_file_calls, 'oversized media skips getFile');
    assertSame([], $telegram->download_file_calls, 'oversized media skips download');
    assertSame([], $exchange->uploads, 'oversized media skips exchange upload');
}

function testStatusCommandDefaultsToEnabled(): void
{
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        new FakeTelegramClient(),
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
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
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
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
        $status,
        new InMemoryIgnoreStateStore()
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

function testLongCommentaryFallsBackToDocumentWithCaption(): void
{
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $transport->deliverOutboundEvent(new OutboundEvent(
        event_id: 141,
        opaque: '100',
        kind: 'commentary',
        text: str_repeat('a', 5000),
        attachments: [],
        meta: []
    ));

    assertSame([], $telegram->sent_messages, 'long commentary not sent as plain message');
    assertSame('Ответ получился слишком длинным, поэтому отправил его файлом.', $telegram->sent_documents[0]['caption'] ?? null, 'long commentary caption');
    assertSame(str_repeat('a', 5000), $telegram->sent_documents[0]['content'] ?? null, 'long commentary document content');
    assertSame('typing', $telegram->chat_actions[0]['action'] ?? null, 'typing after long commentary document');
}

function testRendererRemovesUnsupportedTableTags(): void
{
    $renderer = new TelegramTextRenderer();
    $method = new ReflectionMethod(TelegramTextRenderer::class, 'replaceUnsupported');
    $method->setAccessible(true);

    $rendered = $method->invoke(
        $renderer,
        '<table><tr><th>Name</th><th>Value</th></tr><tr><td>A</td><td>1</td></tr></table>'
    );

    assertTrue(!str_contains($rendered, '<table'), 'table tag removed');
    assertTrue(!str_contains($rendered, '<tr'), 'tr tag removed');
    assertTrue(!str_contains($rendered, '<td'), 'td tag removed');
    assertTrue(!str_contains($rendered, '<th'), 'th tag removed');
}

function testRendererEscapesRawAngleBracketsBeforeMarkdownConversion(): void
{
    $renderer = new TelegramTextRenderer();
    $method = new ReflectionMethod(TelegramTextRenderer::class, 'escapeRawAngleBrackets');
    $method->setAccessible(true);

    $rendered = $method->invoke($renderer, "literal <files>\n<5 authoritative artifacts>");

    assertTrue(str_contains($rendered, '&lt;files>'), 'files tag escaped');
    assertTrue(str_contains($rendered, '&lt;5 authoritative artifacts>'), 'pseudo tag escaped');
}

function testIngressRunnerRegistersBotCommandsBeforePolling(): void
{
    $telegram = new FakeTelegramClient();
    $telegram->throw_on_get_updates = true;
    $runner = new IngressRunner(
        buildConfig(),
        $telegram,
        new TelegramTransport(
            buildConfig(),
            new FakeRouterClient(),
            $telegram,
            new InMemoryTenantMap([]),
            new InMemoryStatusStateStore(),
            new InMemoryIgnoreStateStore()
        ),
        new InMemoryCursorStore()
    );

    try {
        $runner->run();
        fail('Expected polling stop');
    } catch (StopPollingSignal $e) {
        assertSame('stop polling', $e->getMessage(), 'polling stop');
    }

    assertSame(TelegramBotCommandCatalog::all(), $telegram->bot_commands, 'registered bot commands');
    assertSame(1, $telegram->get_updates_calls, 'polling attempted after commands registration');
}

function testIngressRunnerWaitsAndRetriesAfterTelegramUnavailable(): void
{
    $router = new FakeRouterClient();
    $telegram = new SequencedPollingTelegramClient([
        new TelegramUnavailableException('Telegram API request failed: Operation timed out'),
        [[
            'update_id' => 71,
            'message' => [
                'message_id' => 901,
                'text' => 'visible',
                'chat' => [
                    'id' => 100,
                    'type' => 'private',
                ],
            ],
        ]],
    ]);
    $cursor_store = new InMemoryCursorStore();
    $sleeps = [];

    $runner = new IngressRunner(
        buildConfig(),
        $telegram,
        new TelegramTransport(
            buildConfig(),
            $router,
            $telegram,
            new InMemoryTenantMap(['100' => 'user@server']),
            new InMemoryStatusStateStore(),
            new InMemoryIgnoreStateStore()
        ),
        $cursor_store,
        static function (int $milliseconds) use (&$sleeps): void {
            $sleeps[] = $milliseconds;
        }
    );

    try {
        $runner->run();
        fail('Expected polling stop');
    } catch (StopPollingSignal $e) {
        assertSame('stop polling', $e->getMessage(), 'polling stop');
    }

    assertSame([5000], $sleeps, 'telegram unavailable sleep');
    assertSame('visible', $router->ingress_payloads[0]['text'] ?? null, 'update forwarded after retry');
    assertSame(72, $cursor_store->telegram_offset, 'cursor after retry success');
}

function testIngressRunnerWaitsAndRetriesAfterTelegramRateLimit(): void
{
    $router = new FakeRouterClient();
    $telegram = new SequencedPollingTelegramClient([
        new TelegramRateLimitException('Telegram API request failed: Too Many Requests: retry after 7', 7),
        [[
            'update_id' => 71,
            'message' => [
                'message_id' => 901,
                'text' => 'visible',
                'chat' => [
                    'id' => 100,
                    'type' => 'private',
                ],
            ],
        ]],
    ]);
    $cursor_store = new InMemoryCursorStore();
    $sleeps = [];

    $runner = new IngressRunner(
        buildConfig(),
        $telegram,
        new TelegramTransport(
            buildConfig(),
            $router,
            $telegram,
            new InMemoryTenantMap(['100' => 'user@server']),
            new InMemoryStatusStateStore(),
            new InMemoryIgnoreStateStore()
        ),
        $cursor_store,
        static function (int $milliseconds) use (&$sleeps): void {
            $sleeps[] = $milliseconds;
        }
    );

    try {
        $runner->run();
        fail('Expected polling stop');
    } catch (StopPollingSignal $e) {
        assertSame('stop polling', $e->getMessage(), 'polling stop');
    }

    assertSame([7000], $sleeps, 'telegram rate limit sleep');
    assertSame('visible', $router->ingress_payloads[0]['text'] ?? null, 'update forwarded after rate limit retry');
    assertSame(72, $cursor_store->telegram_offset, 'cursor after rate limit retry');
}

function testIngressRunnerWaitsAndRetriesSameUpdateAfterRouterUnavailable(): void
{
    $router = new RetryingIngressRouterClient([
        new RouterUnavailableException('Router HTTP request failed: Resolving timed out after 10000 milliseconds'),
        ['accepted' => true, 'event_id' => 1],
    ]);
    $telegram = new SequencedPollingTelegramClient([
        [[
            'update_id' => 72,
            'message' => [
                'message_id' => 902,
                'text' => 'visible again',
                'chat' => [
                    'id' => 100,
                    'type' => 'private',
                ],
            ],
        ]],
    ]);
    $cursor_store = new InMemoryCursorStore();
    $sleeps = [];

    $runner = new IngressRunner(
        buildConfig(),
        $telegram,
        new TelegramTransport(
            buildConfig(),
            $router,
            $telegram,
            new InMemoryTenantMap(['100' => 'user@server']),
            new InMemoryStatusStateStore(),
            new InMemoryIgnoreStateStore()
        ),
        $cursor_store,
        static function (int $milliseconds) use (&$sleeps): void {
            $sleeps[] = $milliseconds;
        }
    );

    try {
        $runner->run();
        fail('Expected polling stop');
    } catch (StopPollingSignal $e) {
        assertSame('stop polling', $e->getMessage(), 'polling stop');
    }

    assertSame([5000], $sleeps, 'router unavailable sleep');
    assertSame(2, count($router->ingress_payloads), 'same update retried');
    assertSame('visible again', $router->ingress_payloads[0]['text'] ?? null, 'first retried text');
    assertSame('visible again', $router->ingress_payloads[1]['text'] ?? null, 'second retried text');
    assertSame(73, $cursor_store->telegram_offset, 'cursor after router retry success');
}

function testIngressRunnerLogsAndSkipsUnexpectedUpdateError(): void
{
    $router = new FakeRouterClient();
    $telegram = new FailOnceReplyTelegramClient([
        [[
            'update_id' => 80,
            'message' => [
                'message_id' => 903,
                'text' => '/status',
                'chat' => [
                    'id' => 100,
                    'type' => 'private',
                ],
            ],
        ], [
            'update_id' => 81,
            'message' => [
                'message_id' => 904,
                'text' => 'survived',
                'chat' => [
                    'id' => 100,
                    'type' => 'private',
                ],
            ],
        ]],
    ], new RuntimeException('Telegram API request failed: Bad Request: message thread not found'));
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );
    $cursor_store = new InMemoryCursorStore();
    $log_records = [];

    RuntimeLog::setObserver(static function (array $payload) use (&$log_records): void {
        $log_records[] = $payload;
    });

    try {
        $runner = new IngressRunner(
            buildConfig(),
            $telegram,
            $transport,
            $cursor_store,
            static function (int $milliseconds): void {
            }
        );

        $runner->run();
        fail('Expected polling stop');
    } catch (StopPollingSignal $e) {
        assertSame('stop polling', $e->getMessage(), 'polling stop');
    } finally {
        RuntimeLog::setObserver(null);
    }

    assertSame(['survived'], array_column($router->ingress_payloads, 'text'), 'next update still forwarded');
    assertSame(82, $cursor_store->telegram_offset, 'cursor advanced after skipped update');
    $error_logs = array_values(array_filter($log_records, static fn (array $payload): bool => ($payload['level'] ?? null) === 'ERROR'));
    assertSame('Ingress update ignored after unexpected error', $error_logs[0]['message'] ?? null, 'unexpected error message');
}

function testStatusEventCreatesInstructionAndStatusMessage(): void
{
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
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

function testIgnoreCommandSuppressesForumThreadIngressOnly(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['-100123' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $reply = $transport->handleIncomingUpdate([
        'update_id' => 16,
        'message' => [
            'message_id' => 910,
            'message_thread_id' => 77,
            'text' => '/ignore',
            'chat' => [
                'id' => -100123,
                'type' => 'supergroup',
            ],
        ],
    ]);

    assertSame(true, $reply['accepted'], 'ignore accepted');
    assertSame('Игнор: on', $reply['messages'][0]['text'] ?? null, 'ignore confirmation');
    assertSame([], $router->ingress_payloads, 'ignore command not forwarded');

    $suppressed = $transport->handleIncomingUpdate([
        'update_id' => 17,
        'message' => [
            'message_id' => 911,
            'message_thread_id' => 77,
            'text' => 'hidden',
            'chat' => [
                'id' => -100123,
                'type' => 'supergroup',
            ],
        ],
    ]);

    assertSame(false, $suppressed['accepted'], 'ignored message not accepted');
    assertSame([], $suppressed['messages'], 'ignored message no reply');
    assertSame([], $router->ingress_payloads, 'ignored thread not forwarded');

    $other_thread = $transport->handleIncomingUpdate([
        'update_id' => 18,
        'message' => [
            'message_id' => 912,
            'message_thread_id' => 78,
            'text' => 'visible',
            'chat' => [
                'id' => -100123,
                'type' => 'supergroup',
            ],
        ],
    ]);

    assertSame(true, $other_thread['accepted'], 'other thread accepted');
    assertSame('visible', $router->ingress_payloads[0]['text'] ?? null, 'other thread forwarded');
    assertSame('-100123:78', $router->ingress_payloads[0]['opaque'] ?? null, 'other thread opaque');
}

function testUnignoreCommandRestoresChatIngress(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $transport->handleIncomingUpdate([
        'update_id' => 19,
        'message' => [
            'message_id' => 913,
            'text' => '/ignore',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
        ],
    ]);

    $transport->handleIncomingUpdate([
        'update_id' => 20,
        'message' => [
            'message_id' => 914,
            'text' => 'hidden',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
        ],
    ]);

    assertSame([], $router->ingress_payloads, 'chat still ignored');

    $reply = $transport->handleIncomingUpdate([
        'update_id' => 21,
        'message' => [
            'message_id' => 915,
            'text' => '/unignore',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
        ],
    ]);

    assertSame('Игнор: off', $reply['messages'][0]['text'] ?? null, 'unignore confirmation');

    $restored = $transport->handleIncomingUpdate([
        'update_id' => 22,
        'message' => [
            'message_id' => 916,
            'text' => 'visible again',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
        ],
    ]);

    assertSame(true, $restored['accepted'], 'restored accepted');
    assertSame('visible again', $router->ingress_payloads[0]['text'] ?? null, 'chat forwarded after unignore');
}

function testNoteCommandStaysLocalAndSilent(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $result = $transport->handleIncomingUpdate([
        'update_id' => 23,
        'message' => [
            'message_id' => 917,
            'text' => '/note Good idea',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
        ],
    ]);

    assertSame(true, $result['accepted'], 'note accepted');
    assertSame([], $result['messages'], 'note silent');
    assertSame([], $router->ingress_payloads, 'note not forwarded');
    assertSame([], $telegram->sent_messages, 'note no telegram reply');
}

function testDoneCommandRejectsNonThreadChat(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $result = $transport->handleIncomingUpdate([
        'update_id' => 24,
        'message' => [
            'message_id' => 918,
            'text' => '/done',
            'chat' => [
                'id' => 100,
                'type' => 'private',
            ],
        ],
    ]);

    assertSame(true, $result['accepted'], 'done diagnostic accepted');
    assertSame('Команда /done работает только в forum topic.', $result['messages'][0]['text'] ?? null, 'done diagnostic');
    assertSame([], $router->ingress_payloads, 'done diagnostic not forwarded');
    assertSame([], $telegram->edited_topics, 'done diagnostic no topic edit');
    assertSame([], $telegram->closed_topics, 'done diagnostic no topic close');
}

function testDoneCommandEditsIconAndClosesForumTopic(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['-100123' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $result = $transport->handleIncomingUpdate([
        'update_id' => 25,
        'message' => [
            'message_id' => 919,
            'message_thread_id' => 77,
            'text' => '/done',
            'chat' => [
                'id' => -100123,
                'type' => 'supergroup',
            ],
        ],
    ]);

    assertSame(true, $result['accepted'], 'done accepted');
    assertSame('Тема закрыта.', $result['messages'][0]['text'] ?? null, 'done confirmation');
    assertSame([], $router->ingress_payloads, 'done not forwarded');
    assertSame([
        'chat_id' => '-100123',
        'message_thread_id' => 77,
        'name' => null,
        'icon_custom_emoji_id' => '5408906741125490282',
    ], $telegram->edited_topics[0] ?? null, 'done icon edit');
    assertSame([
        'chat_id' => '-100123',
        'message_thread_id' => 77,
    ], $telegram->closed_topics[0] ?? null, 'done close topic');
}

function testDoneCommandReportsTelegramPermissionFailureWithoutCrash(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $telegram->edit_forum_topic_exception = new RuntimeException('Forbidden: bot is not an admin');
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['-100123' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $result = $transport->handleIncomingUpdate([
        'update_id' => 26,
        'message' => [
            'message_id' => 920,
            'message_thread_id' => 77,
            'text' => '/done',
            'chat' => [
                'id' => -100123,
                'type' => 'supergroup',
            ],
        ],
    ]);

    assertSame(true, $result['accepted'], 'done permission failure accepted');
    assertSame('Команда /done недоступна: у бота нет прав администратора для управления темой.', $result['messages'][0]['text'] ?? null, 'done permission failure diagnostic');
    assertSame([], $router->ingress_payloads, 'done permission failure not forwarded');
    assertSame([], $telegram->closed_topics, 'done permission failure no topic close');
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
        $status_store,
        new InMemoryIgnoreStateStore()
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

function testLongFinalFallsBackToDocumentWithCaption(): void
{
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $transport->deliverOutboundEvent(new OutboundEvent(
        event_id: 61,
        opaque: '100',
        kind: 'final',
        text: str_repeat('b', 5000),
        attachments: [],
        meta: []
    ));

    assertSame([], $telegram->sent_messages, 'long final not sent as plain message');
    assertSame('Ответ получился слишком длинным, поэтому отправил его файлом.', $telegram->sent_documents[0]['caption'] ?? null, 'long final caption');
    assertSame(str_repeat('b', 5000), $telegram->sent_documents[0]['content'] ?? null, 'long final document content');
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
        $status_store,
        new InMemoryIgnoreStateStore()
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

function testOutboundRunnerWaitsAndRetriesAfterTelegramRateLimit(): void
{
    $telegram = new RateLimitedTelegramClient(10);
    $status_store = new InMemoryStatusStateStore();
    $status_store->save(new StatusState('100', true, null, null));
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap(['100' => 'user@server']),
        $status_store,
        new InMemoryIgnoreStateStore()
    );
    $cursor_store = new InMemoryCursorStore();
    $router = new SequencedOutboundRouterClient([
        [[
            'event_id' => 53,
            'opaque' => '100',
            'kind' => 'final',
            'text' => 'retry ok',
            'attachments' => [],
            'meta' => [],
        ]],
    ]);
    $sleeps = [];

    $runner = new OutboundRunner(
        $router,
        $transport,
        $cursor_store,
        static function (int $milliseconds) use (&$sleeps): void {
            $sleeps[] = $milliseconds;
        }
    );

    try {
        $runner->run();
        fail('Expected runner to stop after fake router exhaustion');
    } catch (RuntimeException $e) {
        assertSame('stop polling', $e->getMessage(), 'runner stop marker');
    }

    assertSame([10000], $sleeps, 'rate limit sleep');
    assertSame(53, $cursor_store->router_after_id, 'cursor after retry success');
    assertSame('✅ retry ok', $telegram->sent_messages[0]['text'] ?? null, 'delivered after retry');
    assertSame(2, $telegram->send_message_calls, 'retried same outbound delivery');
}

function testOutboundRunnerWaitsAndRetriesAfterRouterUnavailable(): void
{
    $telegram = new FakeTelegramClient();
    $cursor_store = new InMemoryCursorStore();
    $router = new RetryableOutboundRouterClient([
        new RouterUnavailableException('Router HTTP request failed: Resolving timed out after 10000 milliseconds'),
        [[
            'event_id' => 54,
            'opaque' => '100',
            'kind' => 'final',
            'text' => 'retry router',
            'attachments' => [],
            'meta' => [],
        ]],
    ]);
    $status_store = new InMemoryStatusStateStore();
    $status_store->save(new StatusState('100', true, null, null));
    $sleeps = [];

    $runner = new OutboundRunner(
        $router,
        new TelegramTransport(
            buildConfig(),
            new FakeRouterClient(),
            $telegram,
            new InMemoryTenantMap(['100' => 'user@server']),
            $status_store,
            new InMemoryIgnoreStateStore()
        ),
        $cursor_store,
        static function (int $milliseconds) use (&$sleeps): void {
            $sleeps[] = $milliseconds;
        }
    );

    try {
        $runner->run();
        fail('Expected runner to stop after fake router exhaustion');
    } catch (RuntimeException $e) {
        assertSame('stop polling', $e->getMessage(), 'runner stop marker');
    }

    assertSame([5000], $sleeps, 'router unavailable sleep');
    assertSame(54, $cursor_store->router_after_id, 'cursor after router retry success');
    assertSame('✅ retry router', $telegram->sent_messages[0]['text'] ?? null, 'delivered after router retry');
}

function buildConfig(): TelegramTransportConfig
{
    return new TelegramTransportConfig(
        router_base_url: 'https://router.example',
        router_token: 'router-token',
        telegram_bot_token: 'telegram-token',
        telegram_base_url: 'https://api.telegram.org',
        telegram_endpoint_prefix: 'bot',
        file_exchange_base_url: 'https://files.ioannidis.ru',
        file_exchange_token: 'exchange-token',
        transport_instance_id: 'telegram-main',
        outbound_poll_interval_ms: 250,
        telegram_poll_timeout_seconds: 20,
        unknown_chat_text: 'unknown chat',
        status_instruction_text: 'pin this',
        idle_status_text: 'idle',
        done_forum_topic_icon_custom_emoji_id: '5408906741125490282'
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

final class RetryableOutboundRouterClient implements RouterClientInterface
{
    /**
     * @param list<RouterUnavailableException|list<array<string, mixed>>> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function postIngress(array $payload): array
    {
        throw new RuntimeException('not used');
    }

    public function getOutboundEvents(int $after_id, int $wait, int $limit): array
    {
        if ($this->responses === []) {
            throw new RuntimeException('stop polling');
        }

        $response = array_shift($this->responses);
        if ($response instanceof RouterUnavailableException) {
            throw $response;
        }

        return $response;
    }

}

class FakeTelegramClient implements TelegramClientInterface
{
    /** @var list<array{command: string, description: string}> */
    public array $bot_commands = [];

    /** @var list<array<string, mixed>> */
    public array $sent_messages = [];

    /** @var list<array<string, mixed>> */
    public array $sent_documents = [];

    /** @var list<array<string, mixed>> */
    public array $edited_messages = [];

    /** @var list<array<string, mixed>> */
    public array $chat_actions = [];

    /** @var list<array<string, mixed>> */
    public array $sent_animations = [];

    /** @var list<array<string, mixed>> */
    public array $deleted_messages = [];

    /** @var list<array<string, mixed>> */
    public array $edited_topics = [];

    /** @var list<array<string, mixed>> */
    public array $closed_topics = [];

    public ?RuntimeException $edit_forum_topic_exception = null;
    public bool $throw_on_get_updates = false;
    public int $get_updates_calls = 0;
    /** @var array<string, array<string, mixed>> */
    public array $file_results = [];
    /** @var array<string, string> */
    public array $downloaded_files = [];
    /** @var list<string> */
    public array $get_file_calls = [];
    /** @var list<string> */
    public array $download_file_calls = [];

    public function setMyCommands(array $commands): void
    {
        $this->bot_commands = $commands;
    }

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

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(
        int|string $chat_id,
        string $file_path,
        ?string $caption = null,
        ?string $parse_mode = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array {
        $message_id = count($this->sent_documents) + 2000;
        $this->sent_documents[] = [
            'chat_id' => $chat_id,
            'file_path' => $file_path,
            'content' => is_file($file_path) ? (string) file_get_contents($file_path) : null,
            'caption' => $caption,
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

    /**
     * @return array<string, mixed>
     */
    public function sendAnimation(
        int|string $chat_id,
        string $animation,
        ?string $caption = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array {
        $message_id = count($this->sent_animations) + 3000;
        $this->sent_animations[] = [
            'chat_id' => $chat_id,
            'animation' => $animation,
            'file_exists' => is_file($animation),
            'content_length' => is_file($animation) ? strlen((string) file_get_contents($animation)) : 0,
            'caption' => $caption,
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

    public function deleteMessage(int|string $chat_id, int $message_id): void
    {
        $this->deleted_messages[] = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
        ];
    }

    public function editForumTopic(int|string $chat_id, int $message_thread_id, ?string $name = null, ?string $icon_custom_emoji_id = null): void
    {
        if ($this->edit_forum_topic_exception !== null) {
            throw $this->edit_forum_topic_exception;
        }

        $this->edited_topics[] = [
            'chat_id' => (string) $chat_id,
            'message_thread_id' => $message_thread_id,
            'name' => $name,
            'icon_custom_emoji_id' => $icon_custom_emoji_id,
        ];
    }

    public function closeForumTopic(int|string $chat_id, int $message_thread_id): void
    {
        $this->closed_topics[] = [
            'chat_id' => (string) $chat_id,
            'message_thread_id' => $message_thread_id,
        ];
    }

    public function getUpdates(?int $offset, int $timeout, int $limit = 100): array
    {
        $this->get_updates_calls++;
        if ($this->throw_on_get_updates) {
            throw new StopPollingSignal('stop polling');
        }

        return [];
    }

    public function getFile(string $file_id): array
    {
        $this->get_file_calls[] = $file_id;

        if (!isset($this->file_results[$file_id])) {
            throw new RuntimeException('missing file result for ' . $file_id);
        }

        return $this->file_results[$file_id];
    }

    public function downloadFile(string $file_path): string
    {
        $this->download_file_calls[] = $file_path;

        if (!array_key_exists($file_path, $this->downloaded_files)) {
            throw new RuntimeException('missing downloaded file for ' . $file_path);
        }

        return $this->downloaded_files[$file_path];
    }
}

class SequencedPollingTelegramClient extends FakeTelegramClient
{
    /**
     * @param list<RuntimeException|list<array<string, mixed>>> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function getUpdates(?int $offset, int $timeout, int $limit = 100): array
    {
        $this->get_updates_calls++;
        if ($this->responses === []) {
            throw new StopPollingSignal('stop polling');
        }

        $response = array_shift($this->responses);
        if ($response instanceof RuntimeException) {
            throw $response;
        }

        return $response;
    }
}

final class StopPollingSignal extends Error
{
}

final class FailOnceReplyTelegramClient extends SequencedPollingTelegramClient
{
    public function __construct(
        array $responses,
        private ?RuntimeException $exception
    ) {
        parent::__construct($responses);
    }

    public function sendMessage(int|string $chat_id, string $text, ?int $reply_to_message_id = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $message_thread_id = null): array
    {
        if ($this->exception !== null) {
            $exception = $this->exception;
            $this->exception = null;
            throw $exception;
        }

        return parent::sendMessage($chat_id, $text, $reply_to_message_id, $parse_mode, $disable_notification, $message_thread_id);
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

final class InMemoryIgnoreStateStore implements IgnoreStateStoreInterface
{
    /** @var array<string, bool> */
    private array $items = [];

    public function isIgnored(string $scope): bool
    {
        return $this->items[$scope] ?? false;
    }

    public function setIgnored(string $scope, bool $ignored): void
    {
        $this->items[$scope] = $ignored;
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

    public function sendDocument(int|string $chat_id, string $file_path, ?string $caption = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $message_thread_id = null): array
    {
        throw new RuntimeException('delivery failed');
    }
}

final class RetryingIngressRouterClient implements RouterClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $ingress_payloads = [];

    /** @var list<RouterUnavailableException|array<string, mixed>> */
    private array $responses;

    /**
     * @param list<RouterUnavailableException|array<string, mixed>> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function postIngress(array $payload): array
    {
        $this->ingress_payloads[] = $payload;

        if ($this->responses === []) {
            throw new RuntimeException('missing ingress response');
        }

        $response = array_shift($this->responses);
        if ($response instanceof RouterUnavailableException) {
            throw $response;
        }

        return $response;
    }

    public function getOutboundEvents(int $after_id, int $wait, int $limit): array
    {
        throw new RuntimeException('not used');
    }
}

final class RateLimitedTelegramClient extends FakeTelegramClient
{
    public int $send_message_calls = 0;

    public function __construct(private int $retry_after_seconds)
    {
    }

    public function sendMessage(int|string $chat_id, string $text, ?int $reply_to_message_id = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $message_thread_id = null): array
    {
        $this->send_message_calls++;
        if ($this->send_message_calls === 1) {
            throw new TelegramRateLimitException('Too Many Requests: retry after ' . $this->retry_after_seconds, $this->retry_after_seconds);
        }

        return parent::sendMessage($chat_id, $text, $reply_to_message_id, $parse_mode, $disable_notification, $message_thread_id);
    }
}

final class FakeFileExchangeClient implements \CodexMultitenant\TransportTelegram\FileExchangeClientInterface
{
    /** @var list<array{file_path:string, filename:string, mime:?string}> */
    public array $uploads = [];

    /** @var list<array<string, mixed>> */
    public array $upload_results = [];

    public function uploadFile(string $file_path, string $filename, ?string $mime = null): array
    {
        $this->uploads[] = [
            'file_path' => $file_path,
            'filename' => $filename,
            'mime' => $mime,
        ];

        if ($this->upload_results === []) {
            throw new RuntimeException('missing file exchange upload result');
        }

        return array_shift($this->upload_results);
    }
}

testConfigRequiresTelegramToken();
testConfigRequiresFileExchangeToken();
testNormalizerBuildsOpaqueWithThread();
testNormalizerBuildsInboundDocumentAttachment();
testNormalizerRejectsUnsupportedUpdate();
testNormalizerRejectsVoiceOnlyUpdate();
testMediaMessageUploadsSupportedTelegramFilesAndForwardsAttachments();
testOversizedMediaMessageRepliesWithDiagnosticAndSkipsUpload();
testStatusCommandDefaultsToEnabled();
testUnknownChatReplySkipsRouterIngress();
testCommentarySendsTypingAfterMessage();
testLongCommentaryFallsBackToDocumentWithCaption();
testRendererRemovesUnsupportedTableTags();
testRendererEscapesRawAngleBracketsBeforeMarkdownConversion();
testIngressRunnerRegistersBotCommandsBeforePolling();
testIngressRunnerWaitsAndRetriesAfterTelegramUnavailable();
testIngressRunnerWaitsAndRetriesAfterTelegramRateLimit();
testIngressRunnerWaitsAndRetriesSameUpdateAfterRouterUnavailable();
testIngressRunnerLogsAndSkipsUnexpectedUpdateError();
testStatusEventCreatesInstructionAndStatusMessage();
testIgnoreCommandSuppressesForumThreadIngressOnly();
testUnignoreCommandRestoresChatIngress();
testNoteCommandStaysLocalAndSilent();
testDoneCommandRejectsNonThreadChat();
testDoneCommandEditsIconAndClosesForumTopic();
testDoneCommandReportsTelegramPermissionFailureWithoutCrash();
testOutboundRunnerAdvancesCursorAfterSuccessfulDelivery();
testLongFinalFallsBackToDocumentWithCaption();
testOutboundRunnerKeepsCursorWhenDeliveryFails();
testOutboundRunnerWaitsAndRetriesAfterTelegramRateLimit();
testOutboundRunnerWaitsAndRetriesAfterRouterUnavailable();

fwrite(STDOUT, "TelegramTransport tests: OK\n");
