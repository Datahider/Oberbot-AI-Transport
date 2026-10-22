<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CodexMultitenant\TransportTelegram\ClosedTimeEventWriterInterface;
use CodexMultitenant\TransportTelegram\FileExchangeClientInterface;
use CodexMultitenant\TransportTelegram\OberbotAiConfig;
use CodexMultitenant\TransportTelegram\OberbotHandoffRecord;
use CodexMultitenant\TransportTelegram\OberbotHandoffStoreInterface;
use CodexMultitenant\TransportTelegram\OberbotTicketSubmitter;
use CodexMultitenant\TransportTelegram\OutboundEvent;
use CodexMultitenant\TransportTelegram\RouterClientInterface;
use CodexMultitenant\TransportTelegram\TelegramClientInterface;
use CodexMultitenant\TransportTelegram\TurnAccountingService;
use CodexMultitenant\TransportTelegram\TurnState;
use CodexMultitenant\TransportTelegram\TurnStateStoreInterface;

final class OberbotIntegrationMemoryStore implements OberbotHandoffStoreInterface, TurnStateStoreInterface
{
    /** @var array<int, OberbotHandoffRecord> */
    public array $handoffs = [];
    /** @var array<string, TurnState> */
    public array $turns = [];

    public function findByTicketId(int $ticket_id): ?OberbotHandoffRecord { return $this->handoffs[$ticket_id] ?? null; }
    public function findByOpaque(string $opaque): ?OberbotHandoffRecord
    {
        foreach ($this->handoffs as $record) if ($record->opaque() === $opaque) return $record;
        return null;
    }
    public function saveHandoff(OberbotHandoffRecord $record): void { $this->handoffs[$record->ticket_id] = $record; }
    public function loadTurn(string $opaque): ?TurnState { return $this->turns[$opaque] ?? null; }
    public function saveTurn(TurnState $state): void { $this->turns[$state->opaque] = $state; }
    public function deleteTurn(string $opaque): void { unset($this->turns[$opaque]); }
    public function timedOutBefore(\DateTimeImmutable $cutoff): array
    {
        return array_values(array_filter($this->turns, static fn (TurnState $state): bool => $state->last_heartbeat_at < $cutoff));
    }
}

final class OberbotIntegrationTelegram implements TelegramClientInterface
{
    public array $created_titles = [];
    public array $documents = [];
    public function createForumTopic(int|string $chat_id, string $name): array { $this->created_titles[] = $name; return ['message_thread_id' => 771]; }
    public function setMyCommands(array $commands, ?array $scope = null): void {}
    public function sendDocument(int|string $chat_id, string $file_path, ?string $caption = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $thread_id = null): array { $this->documents[] = [$chat_id, $file_path, $thread_id]; return ['message_id' => 1]; }
    public function getUpdates(?int $offset, int $timeout, int $limit = 100): array { return []; }
    public function getFile(string $file_id): array { return []; }
    public function downloadFile(string $file_path): string { return ''; }
    public function sendMessage(int|string $chat_id, string $text, ?int $reply_to_message_id = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $thread_id = null): array { return []; }
    public function sendAnimation(int|string $chat_id, string $file_path, ?string $caption = null, bool $disable_notification = false, ?int $thread_id = null): array { return []; }
    public function sendPhoto(int|string $chat_id, string $file_path, ?string $caption = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $thread_id = null): array { return []; }
    public function sendVoice(int|string $chat_id, string $file_path, ?string $caption = null, ?string $parse_mode = null, ?int $thread_id = null): array { return []; }
    public function deleteMessage(int|string $chat_id, int $message_id): void {}
    public function editMessageText(int|string $chat_id, int $message_id, string $text, ?string $parse_mode = null, ?int $thread_id = null): void {}
    public function sendChatAction(int|string $chat_id, string $action = 'typing', ?int $thread_id = null): void {}
    public function editForumTopic(int|string $chat_id, int $thread_id, ?string $name = null, ?string $icon_custom_emoji_id = null): void {}
    public function closeForumTopic(int|string $chat_id, int $thread_id): void {}
    public function getChatMember(int|string $chat_id, int $user_id): array { return []; }
}

final class OberbotIntegrationRouter implements RouterClientInterface
{
    public array $ingress = [];
    public function postIngress(array $payload): array { $this->ingress[] = $payload; return ['accepted' => true, 'event_id' => 5]; }
    public function createInstallPairingCode(string $binding, string $label): array { return []; }
    public function claimCoreBinding(string $binding, string $pairing_code, string $label): array { return []; }
    public function getOutboundEvents(int $after_id, int $wait, int $limit): array { return []; }
}

final class OberbotIntegrationFiles implements FileExchangeClientInterface
{
    public function uploadFile(string $file_path, string $filename, ?string $mime = null): array { return ['url' => 'https://files.ioannidis.ru/log1', 'filename' => $filename, 'size' => 10]; }
    public function downloadFile(string $file_id, string $filename): string { return ''; }
}

final class OberbotIntegrationTimeWriter implements ClosedTimeEventWriterInterface
{
    public array $events = [];
    public function add(int $ticket_id, string $source_chat_id, \DateTimeImmutable $started_at, \DateTimeImmutable $ended_at): void
    {
        $this->events[] = compact('ticket_id', 'source_chat_id', 'started_at', 'ended_at');
    }
}

$log_path = '/home/web/tmp/oberbot-ai-log-test.txt';
file_put_contents($log_path, "log\n");
$store = new OberbotIntegrationMemoryStore();
$telegram = new OberbotIntegrationTelegram();
$router = new OberbotIntegrationRouter();
$config = new OberbotAiConfig('-100999', 'AI agent', 12, 4);
$submitter = new OberbotTicketSubmitter($config, $telegram, $router, new OberbotIntegrationFiles(), $store);
$record = $submitter->submit([
    'ticket_id' => 42,
    'source_chat_id' => '-10037366278346',
    'source_thread_id' => 55,
    'ticket_title' => str_repeat('Тест ', 40),
    'log_path' => $log_path,
]);

assertSame(771, $record->hidden_thread_id, 'hidden topic id');
assertSame(128, mb_strlen($telegram->created_titles[0]), 'topic title limit');
assertSame('-100999:771', $record->opaque(), 'hidden opaque');
assertSame($log_path, $telegram->documents[0][1], 'visible log document');
assertSame('-100999', $router->ingress[0]['binding'], 'hidden binding');
assertTrue(str_contains($router->ingress[0]['text'], '~/clients/*-10037366278346/'), 'client directory prompt');
assertTrue(str_contains($router->ingress[0]['text'], 'Сначала прочитай AGENTS.md из этой папки.'), 'client AGENTS prompt');
assertTrue(str_contains($router->ingress[0]['text'], 'Если папка не существует или не содержит AGENTS.md — сразу остановись и сообщи об этом.'), 'missing client directory prompt');
assertSame('https://files.ioannidis.ru/log1', $router->ingress[0]['attachments'][0]['url'], 'log attachment URL');

$writer = new OberbotIntegrationTimeWriter();
$now = new DateTimeImmutable('2026-10-21 10:00:00');
$accounting = new TurnAccountingService($store, $store, $writer, $config);
$accounting->observe(new OutboundEvent(1, $record->opaque(), 'heartbeat', '', [], []), $now);
$accounting->observe(new OutboundEvent(2, $record->opaque(), 'heartbeat', '', [], []), $now->modify('+4 seconds'));
$accounting->observe(new OutboundEvent(3, $record->opaque(), 'final', 'done', [], []), $now->modify('+7 seconds'));
assertSame(1, count($writer->events), 'one closed final event');
assertSame($now->format(DATE_ATOM), $writer->events[0]['started_at']->format(DATE_ATOM), 'final start');
assertSame($now->modify('+7 seconds')->format(DATE_ATOM), $writer->events[0]['ended_at']->format(DATE_ATOM), 'final end');

$accounting->observe(new OutboundEvent(4, $record->opaque(), 'heartbeat', '', [], []), $now->modify('+20 seconds'));
$accounting->closeTimedOut($now->modify('+33 seconds'));
assertSame(2, count($writer->events), 'one closed timeout event');
assertSame($now->modify('+24 seconds')->format(DATE_ATOM), $writer->events[1]['ended_at']->format(DATE_ATOM), 'timeout covered end');

$db_store_source = (string) file_get_contents(__DIR__ . '/../src/DbOberbotIntegrationStore.php');
assertTrue(!str_contains($db_store_source, '$state->started_at->format'), 'turn store preserves DateTimeImmutable');
assertTrue(!str_contains($db_store_source, '(string) $row->started_at'), 'turn store reuses hydrated DateTimeImmutable');
$time_writer_source = (string) file_get_contents(__DIR__ . '/../src/TimeTrackerClosedEventWriter.php');
assertTrue(str_contains($time_writer_source, "'Codex AI turn', []"), 'time event passes explicit empty tags');

unlink($log_path);
