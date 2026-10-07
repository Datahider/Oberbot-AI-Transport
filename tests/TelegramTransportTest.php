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
use CodexMultitenant\TransportTelegram\GroupAccessStoreInterface;
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

function testConfigAllowsBotTokenOutsideRuntimeConfig(): void
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
        $config = TelegramTransportConfig::fromConfigPath($config_path);
        assertSame('', $config->telegram_bot_token, 'bot token outside runtime config');
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

function testNormalizerBuildsInboundVoiceAttachment(): void
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
                'mime_type' => 'audio/ogg',
            ],
        ],
    ]);

    if ($message === null) {
        fail('Expected voice update to be normalized');
    }

    assertSame('', $message->text, 'voice text');
    assertSame([[
        'telegram_type' => 'voice',
        'attachment_type' => 'voice',
        'file_id' => 'voice-file-id',
        'mime' => 'audio/ogg',
        'size_bytes' => 123,
    ]], $message->attachments, 'voice attachment');
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
        'voice-file-id' => ['file_path' => 'voice/message.oga'],
    ];
    $telegram->downloaded_files = [
        'documents/report.pdf' => 'PDF',
        'photos/large.jpg' => 'JPEG',
        'audio/theme.mp3' => 'MP3',
        'video/demo.mp4' => 'MP4',
        'voice/message.oga' => 'OGG',
    ];
    $exchange->upload_results = [
        ['file_id' => 'D1', 'url' => 'https://files.ioannidis.ru/D1', 'filename' => 'report.pdf', 'size' => 111],
        ['file_id' => 'P1', 'url' => 'https://files.ioannidis.ru/P1', 'filename' => 'photo.jpg', 'size' => 222],
        ['file_id' => 'A1', 'url' => 'https://files.ioannidis.ru/A1', 'filename' => 'theme.mp3', 'size' => 333],
        ['file_id' => 'V1', 'url' => 'https://files.ioannidis.ru/V1', 'filename' => 'demo.mp4', 'size' => 444],
        ['file_id' => 'VO1', 'url' => 'https://files.ioannidis.ru/VO1', 'filename' => 'voice-file-id.ogg', 'size' => 555],
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
            'voice' => [
                'file_id' => 'voice-file-id',
                'mime_type' => 'audio/ogg',
                'file_size' => 54321,
            ],
        ],
    ]);

    assertSame(true, $result['accepted'], 'media accepted');
    assertSame([], $telegram->sent_animations, 'progress animation disabled');
    assertSame([], $telegram->deleted_messages, 'progress animation delete skipped');
    assertSame('100', $router->ingress_payloads[0]['binding'] ?? null, 'media binding forwarded');
    assertSame(false, array_key_exists('tenant', $router->ingress_payloads[0]), 'media tenant omitted');
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
        [
            'type' => 'voice',
            'url' => 'https://files.ioannidis.ru/VO1',
            'name' => 'voice-file-id.ogg',
            'size_bytes' => 555,
        ],
    ], $router->ingress_payloads[0]['attachments'] ?? null, 'forwarded attachments');
    assertSame(['doc-file-id', 'photo-large', 'audio-file-id', 'video-file-id', 'voice-file-id'], $telegram->get_file_calls, 'getFile calls');
    assertSame(['documents/report.pdf', 'photos/large.jpg', 'audio/theme.mp3', 'video/demo.mp4', 'voice/message.oga'], $telegram->download_file_calls, 'download calls');
    assertSame(['report.pdf', 'photo-large.jpg', 'theme.mp3', 'demo.mp4', 'voice-file-id.ogg'], array_column($exchange->uploads, 'filename'), 'uploaded filenames');
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

function testUnmappedChatIsForwardedToRouterV2(): void
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

    assertSame(1, count($router->ingress_payloads), 'unmapped chat forwarded');
    assertSame('100', $router->ingress_payloads[0]['binding'] ?? null, 'unmapped chat binding');
    assertSame('100', $router->ingress_payloads[0]['opaque'] ?? null, 'unmapped chat opaque');
    assertSame(false, array_key_exists('tenant', $router->ingress_payloads[0]), 'tenant omitted from v2 ingress');
    assertSame([], $telegram->sent_messages, 'transport does not reject unmapped chat');
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

function testWarningUsesWarningPrefixAndMarkdown(): void
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
        event_id: 151,
        opaque: '100',
        kind: 'warning',
        text: 'Осталось `15%`',
        attachments: [],
        meta: []
    ));

    assertSame('⚠️ Осталось <code>15%</code>', $telegram->sent_messages[0]['text'] ?? null, 'warning text');
    assertSame('HTML', $telegram->sent_messages[0]['parse_mode'] ?? null, 'warning parse mode');
}

function testLongWarningFallsBackToDocumentWithWarningCaption(): void
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
        event_id: 152,
        opaque: '100',
        kind: 'warning',
        text: str_repeat('w', 5000),
        attachments: [],
        meta: []
    ));

    assertSame([], $telegram->sent_messages, 'long warning not sent as plain message');
    assertSame('Предупреждение получилось слишком длинным, поэтому отправил его файлом.', $telegram->sent_documents[0]['caption'] ?? null, 'long warning caption');
    assertSame(str_repeat('w', 5000), $telegram->sent_documents[0]['content'] ?? null, 'long warning document content');
}

function testSystemMessageHasGearPrefix(): void
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
        event_id: 153,
        opaque: '100',
        kind: 'system',
        text: 'Сессия `abc`',
        attachments: [],
        meta: []
    ));

    assertSame('⚙️ Сессия <code>abc</code>', $telegram->sent_messages[0]['text'] ?? null, 'system text');
}

function testTranscriptSendsExpandableEscapedQuote(): void
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
        event_id: 142,
        opaque: '100',
        kind: 'transcript',
        text: 'Привет <мир> & **markdown**',
        attachments: [],
        meta: []
    ));

    assertSame('<blockquote expandable>Привет &lt;мир&gt; &amp; **markdown**</blockquote>', $telegram->sent_messages[0]['text'] ?? null, 'transcript text');
    assertSame('HTML', $telegram->sent_messages[0]['parse_mode'] ?? null, 'transcript parse mode');
}

function testUnknownOutboundKindIsSilentlyIgnored(): void
{
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap([]),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $transport->deliverOutboundEvent(new OutboundEvent(
        event_id: 143,
        opaque: '',
        kind: 'future-kind',
        text: 'must not be delivered',
        attachments: [],
        meta: []
    ));

    assertSame([], $telegram->sent_messages, 'unknown kind messages');
    assertSame([], $telegram->chat_actions, 'unknown kind chat actions');
}

function testDocumentOutboundDownloadsAndSendsTelegramDocument(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $exchange = new FakeFileExchangeClient();
    $exchange->downloads['AbCd'] = 'document body';
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap([]),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore(),
        $exchange
    );

    $transport->deliverOutboundEvent(new OutboundEvent(
        event_id: 44,
        opaque: '-100123:77',
        kind: 'document',
        text: 'Report `one`',
        attachments: [[
            'file_id' => 'AbCd',
            'name' => 'report.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 13,
        ]],
        meta: []
    ));

    assertSame(['AbCd'], $exchange->download_calls, 'exchange download calls');
    assertSame('document body', $telegram->sent_documents[0]['content'] ?? null, 'document content');
    assertSame('Report <code>one</code>', $telegram->sent_documents[0]['caption'] ?? null, 'document caption');
    assertSame('HTML', $telegram->sent_documents[0]['parse_mode'] ?? null, 'document parse mode');
    assertSame(77, $telegram->sent_documents[0]['message_thread_id'] ?? null, 'document thread');
    assertSame(false, is_file((string) ($exchange->download_paths[0] ?? '')), 'temporary document removed');
}

function testImageOutboundDownloadsAndSendsTelegramPhoto(): void
{
    $telegram = new FakeTelegramClient();
    $exchange = new FakeFileExchangeClient();
    $exchange->downloads['Img1'] = 'image body';
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap([]),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore(),
        $exchange
    );

    $transport->deliverOutboundEvent(new OutboundEvent(
        event_id: 45,
        opaque: '-100123:78',
        kind: 'image',
        text: 'Pixel `two`',
        attachments: [[
            'file_id' => 'Img1',
            'name' => 'pixel.png',
            'mime' => 'image/png',
            'size_bytes' => 10,
        ]],
        meta: []
    ));

    assertSame(['Img1'], $exchange->download_calls, 'image exchange download calls');
    assertSame('image body', $telegram->sent_photos[0]['content'] ?? null, 'photo content');
    assertSame('Pixel <code>two</code>', $telegram->sent_photos[0]['caption'] ?? null, 'photo caption');
    assertSame('HTML', $telegram->sent_photos[0]['parse_mode'] ?? null, 'photo parse mode');
    assertSame(78, $telegram->sent_photos[0]['message_thread_id'] ?? null, 'photo thread');
    assertSame(false, is_file((string) ($exchange->download_paths[0] ?? '')), 'temporary image removed');
}

function testVoiceOutboundDownloadsAndSendsTelegramVoice(): void
{
    $telegram = new FakeTelegramClient();
    $exchange = new FakeFileExchangeClient();
    $exchange->downloads['Voice1'] = 'ogg voice body';
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap([]),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore(),
        $exchange
    );

    $transport->deliverOutboundEvent(new OutboundEvent(
        event_id: 46,
        opaque: '-100123:79',
        kind: 'voice',
        text: '`/voice cedar`',
        attachments: [[
            'file_id' => 'Voice1',
            'name' => 'response.ogg',
            'mime' => 'audio/ogg',
            'size_bytes' => 14,
        ]],
        meta: []
    ));

    assertSame(['Voice1'], $exchange->download_calls, 'voice exchange download calls');
    assertSame('ogg voice body', $telegram->sent_voices[0]['content'] ?? null, 'voice content');
    assertSame('<code>/voice cedar</code>', $telegram->sent_voices[0]['caption'] ?? null, 'voice caption');
    assertSame('HTML', $telegram->sent_voices[0]['parse_mode'] ?? null, 'voice caption parse mode');
    assertSame(79, $telegram->sent_voices[0]['message_thread_id'] ?? null, 'voice thread');
    assertSame(false, is_file((string) ($exchange->download_paths[0] ?? '')), 'temporary voice removed');
}

function testVoiceQuoteCommandsSupportGroupInheritanceAndTopicOverride(): void
{
    $telegram = new FakeTelegramClient();
    $status = new InMemoryStatusStateStore();
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap(['-100123' => 'user@server']),
        $status,
        new InMemoryIgnoreStateStore()
    );

    $silent = $transport->handleIncomingUpdate([
        'update_id' => 144,
        'message' => [
            'message_id' => 944,
            'text' => '/voicesilent',
            'from' => ['id' => 7],
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
        ],
    ]);
    assertSame(true, $silent['accepted'], 'voicesilent accepted');
    assertSame('Цитата голоса: off', $silent['messages'][0]['text'] ?? null, 'voicesilent reply');

    $telegram->sent_messages = [];
    $transport->deliverOutboundEvent(new OutboundEvent(145, '-100123:77', 'transcript', 'hidden', [], []));
    assertSame([], $telegram->sent_messages, 'topic inherits silent group');

    $quote = $transport->handleIncomingUpdate([
        'update_id' => 147,
        'message' => [
            'message_id' => 947,
            'message_thread_id' => 77,
            'text' => '/voicequote',
            'from' => ['id' => 7],
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
        ],
    ]);
    assertSame(true, $quote['accepted'], 'voicequote accepted');
    assertSame('Цитата голоса: on', $quote['messages'][0]['text'] ?? null, 'voicequote reply');

    $telegram->sent_messages = [];
    $transport->deliverOutboundEvent(new OutboundEvent(148, '-100123:77', 'transcript', 'shown again', [], []));
    assertSame('<blockquote expandable>shown again</blockquote>', $telegram->sent_messages[0]['text'] ?? null, 'topic override visible');

    $global = $transport->handleIncomingUpdate([
        'update_id' => 149,
        'message' => [
            'message_id' => 949,
            'message_thread_id' => 77,
            'text' => '/voiceglobal',
            'from' => ['id' => 7],
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
        ],
    ]);
    assertSame('Цитата голоса: global', $global['messages'][0]['text'] ?? null, 'voiceglobal reply');

    $telegram->sent_messages = [];
    $transport->deliverOutboundEvent(new OutboundEvent(150, '-100123:77', 'transcript', 'hidden again', [], []));
    assertSame([], $telegram->sent_messages, 'topic inherits group again');
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

function testRendererEscapesAngleBracketsExactlyOnce(): void
{
    $renderer = new TelegramTextRenderer();

    assertSame(
        'literal &lt;files&gt; and &lt;5 authoritative artifacts&gt;',
        $renderer->renderFinal('literal <files> and <5 authoritative artifacts>'),
        'plain pseudo tags escaped once'
    );
    assertSame(
        '<code>&lt;files&gt; and &lt;5 authoritative artifacts&gt;</code>',
        $renderer->renderFinal('`<files> and <5 authoritative artifacts>`'),
        'inline code pseudo tags escaped once'
    );
    assertSame(
        "<pre><code>&lt;files&gt;\n&lt;5 authoritative artifacts&gt;</code></pre>",
        $renderer->renderFinal("```text\n<files>\n<5 authoritative artifacts>\n```"),
        'fenced code pseudo tags escaped once'
    );
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

    assertSame(TelegramBotCommandCatalog::privateChats(), $telegram->command_sets['all_private_chats'] ?? null, 'private bot commands');
    assertSame(TelegramBotCommandCatalog::groupAdministrators(), $telegram->command_sets['all_chat_administrators'] ?? null, 'group admin commands');
    $limits_commands = array_values(array_filter(
        $telegram->command_sets['all_private_chats'] ?? [],
        static fn (array $command): bool => ($command['command'] ?? null) === 'limits'
    ));
    assertSame([
        [
            'command' => 'limits',
            'description' => 'Показать текущие лимиты Codex',
        ],
    ], $limits_commands, 'limits command registered');
    $voice_commands = array_values(array_filter(
        $telegram->bot_commands,
        static fn (array $command): bool => in_array(($command['command'] ?? null), ['voices', 'voice'], true)
    ));
    assertSame([
        ['command' => 'voices', 'description' => 'Показать доступные голоса'],
        ['command' => 'voice', 'description' => 'Показать или выбрать голос'],
    ], $voice_commands, 'voice commands registered');
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
            'from' => ['id' => 7],
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
            'from' => ['id' => 42],
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
            'from' => ['id' => 7],
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

function testActiveGroupScopeDeletesNonAdministratorMessage(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $telegram->chat_member_results['-100123:42'] = ['status' => 'member'];
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['-100123' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $result = $transport->handleIncomingUpdate([
        'update_id' => 101,
        'message' => [
            'message_id' => 2001,
            'message_thread_id' => 77,
            'text' => 'subscriber message',
            'from' => ['id' => 42],
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
        ],
    ]);

    assertSame(false, $result['accepted'], 'member message rejected');
    assertSame([['chat_id' => '-100123', 'message_id' => 2001]], $telegram->deleted_messages, 'member message deleted');
    assertSame([], $router->ingress_payloads, 'member message not forwarded');
}

function testActiveGroupScopeForwardsAdministratorMessage(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $telegram->chat_member_results['-100123:7'] = ['status' => 'administrator'];
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['-100123' => 'user@server']),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore()
    );

    $result = $transport->handleIncomingUpdate([
        'update_id' => 102,
        'message' => [
            'message_id' => 2002,
            'message_thread_id' => 77,
            'text' => 'admin message',
            'from' => ['id' => 7],
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
        ],
    ]);

    assertSame(true, $result['accepted'], 'administrator message accepted');
    assertSame([], $telegram->deleted_messages, 'administrator message retained');
    assertSame('admin message', $router->ingress_payloads[0]['text'] ?? null, 'administrator message forwarded');
}

function testGroupCreatorCanAllowMemberMessages(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $telegram->chat_member_results['-100123:7'] = ['status' => 'creator'];
    $telegram->chat_member_results['-100123:42'] = ['status' => 'member'];
    $access = new InMemoryGroupAccessStore();
    $transport = new TelegramTransport(buildConfig(), $router, $telegram, new InMemoryTenantMap([]), new InMemoryStatusStateStore(), new InMemoryIgnoreStateStore(), null, $access);

    $changed = $transport->handleIncomingUpdate(['message' => ['message_id' => 2100, 'text' => '/access members', 'from' => ['id' => 7], 'chat' => ['id' => -100123, 'type' => 'supergroup']]]);
    $member = $transport->handleIncomingUpdate(['message' => ['message_id' => 2101, 'text' => 'member input', 'from' => ['id' => 42], 'chat' => ['id' => -100123, 'type' => 'supergroup']]]);

    assertSame('members', $access->modeForGroup('-100123'), 'creator changed access');
    assertSame('Доступ: members', $changed['messages'][0]['text'] ?? null, 'access confirmation');
    assertSame(true, $member['accepted'], 'member accepted');
    assertSame('member input', $router->ingress_payloads[0]['text'] ?? null, 'member forwarded');
}

function testGroupAdministratorCannotChangeWriteAccess(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $telegram->chat_member_results['-100123:7'] = ['status' => 'administrator'];
    $access = new InMemoryGroupAccessStore();
    $transport = new TelegramTransport(buildConfig(), $router, $telegram, new InMemoryTenantMap([]), new InMemoryStatusStateStore(), new InMemoryIgnoreStateStore(), null, $access);

    $result = $transport->handleIncomingUpdate(['message' => ['message_id' => 2102, 'text' => '/access members', 'from' => ['id' => 7], 'chat' => ['id' => -100123, 'type' => 'supergroup']]]);

    assertSame('admins', $access->modeForGroup('-100123'), 'administrator cannot change access');
    assertSame('Изменить доступ может только владелец группы.', $result['messages'][0]['text'] ?? null, 'owner required');
    assertSame([], $router->ingress_payloads, 'access command local');
}

function testMemberCanViewMembersWriteAccess(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $telegram->chat_member_results['-100123:42'] = ['status' => 'member'];
    $access = new InMemoryGroupAccessStore(['-100123' => 'members']);
    $transport = new TelegramTransport(buildConfig(), $router, $telegram, new InMemoryTenantMap([]), new InMemoryStatusStateStore(), new InMemoryIgnoreStateStore(), null, $access);

    $result = $transport->handleIncomingUpdate(['message' => ['message_id' => 2103, 'text' => '/access', 'from' => ['id' => 42], 'chat' => ['id' => -100123, 'type' => 'supergroup']]]);

    assertSame('Доступ: members', $result['messages'][0]['text'] ?? null, 'member views access');
    assertSame([], $router->ingress_payloads, 'view command local');
}

function testMembersWriteAccessDoesNotGrantAdministrativeCommands(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $telegram->chat_member_results['-100123:42'] = ['status' => 'member'];
    $ignore = new InMemoryIgnoreStateStore();
    $access = new InMemoryGroupAccessStore(['-100123' => 'members']);
    $transport = new TelegramTransport(buildConfig(), $router, $telegram, new InMemoryTenantMap([]), new InMemoryStatusStateStore(), $ignore, null, $access);

    $result = $transport->handleIncomingUpdate(['message' => ['message_id' => 2104, 'message_thread_id' => 77, 'text' => '/ignore', 'from' => ['id' => 42], 'chat' => ['id' => -100123, 'type' => 'supergroup']]]);

    assertSame(false, $ignore->isIgnored('-100123:77'), 'member cannot ignore topic');
    assertSame('Команда доступна только администраторам группы.', $result['messages'][0]['text'] ?? null, 'administrator required');
    assertSame([], $router->ingress_payloads, 'administrative command local');
}

function testActiveGroupScopeForwardsAnonymousAdministratorMessage(): void
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
        'update_id' => 103,
        'message' => [
            'message_id' => 2003,
            'message_thread_id' => 77,
            'text' => 'anonymous admin message',
            'sender_chat' => ['id' => -100123],
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
        ],
    ]);

    assertSame(true, $result['accepted'], 'anonymous administrator message accepted');
    assertSame([], $telegram->deleted_messages, 'anonymous administrator message retained');
    assertSame('anonymous admin message', $router->ingress_payloads[0]['text'] ?? null, 'anonymous administrator message forwarded');
}

function testIgnoredGroupScopeLeavesMemberMessagesAndCommandsUntouched(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $telegram->chat_member_results['-100123:42'] = ['status' => 'member'];
    $ignore_store = new InMemoryIgnoreStateStore();
    $ignore_store->setIgnored('-100123:77', true);
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['-100123' => 'user@server']),
        new InMemoryStatusStateStore(),
        $ignore_store
    );

    foreach (['discussion', '/done', '/unignore'] as $offset => $text) {
        $result = $transport->handleIncomingUpdate([
            'update_id' => 110 + $offset,
            'message' => [
                'message_id' => 2010 + $offset,
                'message_thread_id' => 77,
                'text' => $text,
                'from' => ['id' => 42],
                'chat' => ['id' => -100123, 'type' => 'supergroup'],
            ],
        ]);
        assertSame(false, $result['accepted'], 'ignored member input untouched');
    }

    assertSame([], $telegram->deleted_messages, 'ignored member messages retained');
    assertSame([], $telegram->closed_topics, 'ignored member command not handled');
    assertSame(true, $ignore_store->isIgnored('-100123:77'), 'member cannot unignore scope');
    assertSame([], $router->ingress_payloads, 'ignored member input not forwarded');
}

function testIgnoredGroupScopeAllowsAdministratorToUnignore(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $telegram->chat_member_results['-100123:7'] = ['status' => 'creator'];
    $ignore_store = new InMemoryIgnoreStateStore();
    $ignore_store->setIgnored('-100123:77', true);
    $transport = new TelegramTransport(
        buildConfig(),
        $router,
        $telegram,
        new InMemoryTenantMap(['-100123' => 'user@server']),
        new InMemoryStatusStateStore(),
        $ignore_store
    );

    $result = $transport->handleIncomingUpdate([
        'update_id' => 120,
        'message' => [
            'message_id' => 2020,
            'message_thread_id' => 77,
            'text' => '/unignore',
            'from' => ['id' => 7],
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
        ],
    ]);

    assertSame(true, $result['accepted'], 'administrator unignore accepted');
    assertSame(false, $ignore_store->isIgnored('-100123:77'), 'administrator unignored scope');
    assertSame('Игнор: off', $result['messages'][0]['text'] ?? null, 'administrator receives confirmation');
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
            'from' => ['id' => 7],
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
            'from' => ['id' => 7],
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

function testPairCommandDeletesSecretAndClaimsPrivateChat(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(buildConfig(), $router, $telegram, new InMemoryTenantMap([]), new InMemoryStatusStateStore(), new InMemoryIgnoreStateStore());

    $result = $transport->handleIncomingUpdate(['message' => [
        'message_id' => 2201,
        'text' => '/pair 7kqw-9mtr-x5np-3yza',
        'from' => ['id' => 42, 'first_name' => 'Ivan'],
        'chat' => ['id' => 42, 'type' => 'private'],
    ]]);

    assertSame([['chat_id' => '42', 'message_id' => 2201]], $telegram->deleted_messages, 'pair secret deleted');
    assertSame([[
        'binding' => '42',
        'pairing_code' => '7kqw-9mtr-x5np-3yza',
        'label' => 'Telegram private chat',
    ]], $router->claim_payloads, 'private chat claimed');
    assertSame('Core привязан.', $result['messages'][0]['text'] ?? null, 'pair success reply');
    assertSame(null, $telegram->sent_messages[0]['reply_to_message_id'] ?? null, 'pair reply does not target deleted message');
    assertSame([], $router->ingress_payloads, 'pair command not forwarded');
}

function testPairCommandRejectsGroupWithoutCallingRouter(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $transport = new TelegramTransport(buildConfig(), $router, $telegram, new InMemoryTenantMap([]), new InMemoryStatusStateStore(), new InMemoryIgnoreStateStore());

    $result = $transport->handleIncomingUpdate(['message' => [
        'message_id' => 2202,
        'text' => '/pair 7KQW-9MTR-X5NP-3YZA',
        'from' => ['id' => 42],
        'chat' => ['id' => -100123, 'type' => 'supergroup'],
    ]]);

    assertSame([], $router->claim_payloads, 'group pair does not call router');
    assertSame('Команда /pair работает только в личном чате.', $result['messages'][0]['text'] ?? null, 'group pair reply');
}

function testPairCommandContinuesWhenRetriedMessageWasAlreadyDeleted(): void
{
    $router = new FakeRouterClient();
    $telegram = new FakeTelegramClient();
    $telegram->delete_message_exception = new RuntimeException('Telegram API request failed: Bad Request: message to delete not found');
    $transport = new TelegramTransport(buildConfig(), $router, $telegram, new InMemoryTenantMap([]), new InMemoryStatusStateStore(), new InMemoryIgnoreStateStore());

    $result = $transport->handleIncomingUpdate(['message' => [
        'message_id' => 2203,
        'text' => '/pair PR123',
        'from' => ['id' => 42],
        'chat' => ['id' => 42, 'type' => 'private'],
    ]]);

    assertSame(1, count($router->claim_payloads), 'retried pair reaches router');
    assertSame('Core привязан.', $result['messages'][0]['text'] ?? null, 'retried pair receives reply');
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
    assertSame('done', $telegram->sent_messages[0]['text'] ?? null, 'delivered final text');
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

function testOutboundRunnerReportsPermanentDeliveryFailureAndAdvancesCursor(): void
{
    $telegram = new PhotoRejectingTelegramClient();
    $exchange = new FakeFileExchangeClient();
    $exchange->downloads['Img1'] = 'invalid image body';
    $transport = new TelegramTransport(
        buildConfig(),
        new FakeRouterClient(),
        $telegram,
        new InMemoryTenantMap([]),
        new InMemoryStatusStateStore(),
        new InMemoryIgnoreStateStore(),
        $exchange
    );
    $cursor_store = new InMemoryCursorStore();
    $router = new SequencedOutboundRouterClient([
        [[
            'event_id' => 52,
            'opaque' => '-100123:77',
            'kind' => 'image',
            'text' => 'broken image',
            'attachments' => [[
                'file_id' => 'Img1',
                'name' => 'broken.png',
                'mime' => 'image/png',
                'size_bytes' => 18,
            ]],
            'meta' => [],
        ]],
    ]);

    $runner = new OutboundRunner($router, $transport, $cursor_store);

    try {
        $runner->run();
        fail('Expected runner to stop after fake router exhaustion');
    } catch (RuntimeException $e) {
        assertSame('stop polling', $e->getMessage(), 'runner stop marker');
    }

    assertSame(52, $cursor_store->router_after_id, 'cursor after permanent delivery failure');
    assertSame('-100123', $telegram->sent_messages[0]['chat_id'] ?? null, 'diagnostic chat');
    assertSame(77, $telegram->sent_messages[0]['message_thread_id'] ?? null, 'diagnostic topic');
    assertSame(
        "Не удалось доставить событие #52 (image): Telegram API request failed: Bad Request: PHOTO_INVALID_DIMENSIONS",
        $telegram->sent_messages[0]['text'] ?? null,
        'delivery failure diagnostic'
    );
}

function testOutboundRunnerAdvancesCursorWhenFailureDiagnosticAlsoFails(): void
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
            'event_id' => 55,
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
        fail('Expected runner to stop after fake router exhaustion');
    } catch (RuntimeException $e) {
        assertSame('stop polling', $e->getMessage(), 'runner stop marker');
    }

    assertSame(55, $cursor_store->router_after_id, 'cursor after failed diagnostic');
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
    assertSame('retry ok', $telegram->sent_messages[0]['text'] ?? null, 'delivered after retry');
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
    assertSame('retry router', $telegram->sent_messages[0]['text'] ?? null, 'delivered after router retry');
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
    public array $claim_payloads = [];

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

    public function claimCoreBinding(string $binding, string $pairing_code, string $label): array
    {
        $this->claim_payloads[] = compact('binding', 'pairing_code', 'label');
        return ['accepted' => true];
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

    public function claimCoreBinding(string $binding, string $pairing_code, string $label): array { throw new RuntimeException('not used'); }
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

    public function claimCoreBinding(string $binding, string $pairing_code, string $label): array { throw new RuntimeException('not used'); }

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
    public array $sent_photos = [];

    /** @var list<array<string, mixed>> */
    public array $sent_voices = [];

    /** @var list<array<string, mixed>> */
    public array $edited_messages = [];

    /** @var list<array<string, mixed>> */
    public array $chat_actions = [];

    /** @var list<array<string, mixed>> */
    public array $sent_animations = [];

    /** @var list<array<string, mixed>> */
    public array $deleted_messages = [];
    public ?RuntimeException $delete_message_exception = null;

    /** @var array<string, array<string, mixed>> */
    public array $chat_member_results = [];

    /** @var list<array<string, mixed>> */
    public array $edited_topics = [];

    /** @var list<array<string, mixed>> */
    public array $closed_topics = [];

    public ?RuntimeException $edit_forum_topic_exception = null;
    public bool $throw_on_get_updates = false;
    public int $get_updates_calls = 0;
    public array $command_sets = [];
    /** @var array<string, array<string, mixed>> */
    public array $file_results = [];
    /** @var array<string, string> */
    public array $downloaded_files = [];
    /** @var list<string> */
    public array $get_file_calls = [];
    /** @var list<string> */
    public array $download_file_calls = [];

    public function setMyCommands(array $commands, ?array $scope = null): void
    {
        $this->bot_commands = $commands;
        $this->command_sets[(string) ($scope['type'] ?? 'default')] = $commands;
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

    public function sendPhoto(
        int|string $chat_id,
        string $file_path,
        ?string $caption = null,
        ?string $parse_mode = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array {
        $this->sent_photos[] = [
            'chat_id' => $chat_id,
            'file_path' => $file_path,
            'content' => is_file($file_path) ? (string) file_get_contents($file_path) : null,
            'caption' => $caption,
            'parse_mode' => $parse_mode,
            'disable_notification' => $disable_notification,
            'message_thread_id' => $message_thread_id,
        ];

        return ['ok' => true, 'result' => ['message_id' => 2500]];
    }

    public function sendVoice(
        int|string $chat_id,
        string $file_path,
        ?string $caption = null,
        ?string $parse_mode = null,
        ?int $message_thread_id = null
    ): array {
        $this->sent_voices[] = [
            'chat_id' => $chat_id,
            'file_path' => $file_path,
            'content' => is_file($file_path) ? (string) file_get_contents($file_path) : null,
            'caption' => $caption,
            'parse_mode' => $parse_mode,
            'message_thread_id' => $message_thread_id,
        ];

        return ['ok' => true, 'result' => ['message_id' => 2600]];
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
        if ($this->delete_message_exception !== null) {
            throw $this->delete_message_exception;
        }
        $this->deleted_messages[] = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
        ];
    }

    public function getChatMember(int|string $chat_id, int $user_id): array
    {
        return $this->chat_member_results[(string) $chat_id . ':' . $user_id] ?? ['status' => 'administrator'];
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

final class InMemoryGroupAccessStore implements GroupAccessStoreInterface
{
    public function __construct(private array $modes = []) {}
    public function modeForGroup(string $chat_id): string { return $this->modes[$chat_id] ?? 'admins'; }
    public function setModeForGroup(string $chat_id, string $mode): void { $this->modes[$chat_id] = $mode; }
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

final class PhotoRejectingTelegramClient extends FakeTelegramClient
{
    public function sendPhoto(
        int|string $chat_id,
        string $file_path,
        ?string $caption = null,
        ?string $parse_mode = null,
        bool $disable_notification = false,
        ?int $message_thread_id = null
    ): array {
        throw new RuntimeException('Telegram API request failed: Bad Request: PHOTO_INVALID_DIMENSIONS');
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

    public function claimCoreBinding(string $binding, string $pairing_code, string $label): array { throw new RuntimeException('not used'); }
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

    /** @var array<string, string> */
    public array $downloads = [];

    /** @var list<string> */
    public array $download_calls = [];

    /** @var list<string> */
    public array $download_paths = [];

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

    public function downloadFile(string $file_id, string $filename): string
    {
        $this->download_calls[] = $file_id;
        if (!array_key_exists($file_id, $this->downloads)) {
            throw new RuntimeException('missing file exchange download');
        }

        $path = '/home/web/tmp/telegram-outbound-' . bin2hex(random_bytes(4)) . '-' . basename($filename);
        file_put_contents($path, $this->downloads[$file_id]);
        $this->download_paths[] = $path;

        return $path;
    }
}

testConfigAllowsBotTokenOutsideRuntimeConfig();
testConfigRequiresFileExchangeToken();
testNormalizerBuildsOpaqueWithThread();
testNormalizerBuildsInboundDocumentAttachment();
testNormalizerRejectsUnsupportedUpdate();
testNormalizerBuildsInboundVoiceAttachment();
testMediaMessageUploadsSupportedTelegramFilesAndForwardsAttachments();
testOversizedMediaMessageRepliesWithDiagnosticAndSkipsUpload();
testStatusCommandDefaultsToEnabled();
testUnmappedChatIsForwardedToRouterV2();
testCommentarySendsTypingAfterMessage();
testLongCommentaryFallsBackToDocumentWithCaption();
testWarningUsesWarningPrefixAndMarkdown();
testLongWarningFallsBackToDocumentWithWarningCaption();
testSystemMessageHasGearPrefix();
testTranscriptSendsExpandableEscapedQuote();
testUnknownOutboundKindIsSilentlyIgnored();
testDocumentOutboundDownloadsAndSendsTelegramDocument();
testImageOutboundDownloadsAndSendsTelegramPhoto();
testVoiceOutboundDownloadsAndSendsTelegramVoice();
testVoiceQuoteCommandsSupportGroupInheritanceAndTopicOverride();
testRendererRemovesUnsupportedTableTags();
testRendererEscapesAngleBracketsExactlyOnce();
testIngressRunnerRegistersBotCommandsBeforePolling();
testIngressRunnerWaitsAndRetriesAfterTelegramUnavailable();
testIngressRunnerWaitsAndRetriesAfterTelegramRateLimit();
testIngressRunnerWaitsAndRetriesSameUpdateAfterRouterUnavailable();
testIngressRunnerLogsAndSkipsUnexpectedUpdateError();
testStatusEventCreatesInstructionAndStatusMessage();
testIgnoreCommandSuppressesForumThreadIngressOnly();
testActiveGroupScopeDeletesNonAdministratorMessage();
testActiveGroupScopeForwardsAdministratorMessage();
testGroupCreatorCanAllowMemberMessages();
testGroupAdministratorCannotChangeWriteAccess();
testMemberCanViewMembersWriteAccess();
testMembersWriteAccessDoesNotGrantAdministrativeCommands();
testActiveGroupScopeForwardsAnonymousAdministratorMessage();
testIgnoredGroupScopeLeavesMemberMessagesAndCommandsUntouched();
testIgnoredGroupScopeAllowsAdministratorToUnignore();
testUnignoreCommandRestoresChatIngress();
testNoteCommandStaysLocalAndSilent();
testDoneCommandRejectsNonThreadChat();
testDoneCommandEditsIconAndClosesForumTopic();
testDoneCommandReportsTelegramPermissionFailureWithoutCrash();
testPairCommandDeletesSecretAndClaimsPrivateChat();
testPairCommandRejectsGroupWithoutCallingRouter();
testPairCommandContinuesWhenRetriedMessageWasAlreadyDeleted();
testOutboundRunnerAdvancesCursorAfterSuccessfulDelivery();
testLongFinalFallsBackToDocumentWithCaption();
testOutboundRunnerReportsPermanentDeliveryFailureAndAdvancesCursor();
testOutboundRunnerAdvancesCursorWhenFailureDiagnosticAlsoFails();
testOutboundRunnerWaitsAndRetriesAfterTelegramRateLimit();
testOutboundRunnerWaitsAndRetriesAfterRouterUnavailable();

fwrite(STDOUT, "TelegramTransport tests: OK\n");
