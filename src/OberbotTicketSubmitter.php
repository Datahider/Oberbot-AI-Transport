<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use RuntimeException;

final class OberbotTicketSubmitter
{
    private const PROVIDERS = ['codex', 'claude'];

    public function __construct(
        private OberbotAiConfig $config,
        private TelegramClientInterface $telegram,
        private RouterClientInterface $router,
        private FileExchangeClientInterface $files,
        private OberbotHandoffStoreInterface $store
    ) {}

    /** @param array<string, mixed> $job */
    public function submit(array $job): OberbotHandoffRecord
    {
        $ticket_id = (int) ($job['ticket_id'] ?? 0);
        $source_chat_id = trim((string) ($job['source_chat_id'] ?? ''));
        $source_thread_id = (int) ($job['source_thread_id'] ?? 0);
        $title = trim((string) ($job['ticket_title'] ?? ''));
        $log_path = trim((string) ($job['log_path'] ?? ''));
        if ($ticket_id < 1 || $source_chat_id === '' || $source_thread_id < 1 || $title === '' || !is_file($log_path)) {
            throw new RuntimeException('Invalid Oberbot handoff job');
        }
        $has_provider = array_key_exists('provider', $job);
        if ($has_provider && !in_array($job['provider'], self::PROVIDERS, true)) {
            throw new RuntimeException('Invalid Oberbot handoff job');
        }

        $record = $this->store->findByTicketId($ticket_id)
            ?? new OberbotHandoffRecord($ticket_id, $source_chat_id, $source_thread_id, $this->config->hidden_chat_id);

        if ($record->hidden_thread_id === null) {
            $created = $this->telegram->createForumTopic($this->config->hidden_chat_id, $this->topicTitle($ticket_id, $title));
            $thread_id = (int) ($created['message_thread_id'] ?? 0);
            if ($thread_id < 1) throw new RuntimeException('Telegram createForumTopic response is missing message_thread_id');
            $record = $record->withHiddenThread($thread_id);
            $this->store->saveHandoff($record);
        }

        if (!$record->log_sent) {
            $this->telegram->sendDocument($record->hidden_chat_id, $log_path, null, null, true, $record->hidden_thread_id);
            $record = $record->withLogSent();
            $this->store->saveHandoff($record);
        }

        if (!$record->ingress_sent) {
            $uploaded = $this->files->uploadFile($log_path, basename($log_path), 'text/plain');
            $url = trim((string) ($uploaded['url'] ?? ''));
            if ($url === '') throw new RuntimeException('File exchange response is missing log URL');
            $meta = ['source' => 'oberbot', 'ticket_id' => $ticket_id, 'source_chat_id' => $source_chat_id];
            if ($has_provider) $meta['provider'] = $job['provider'];
            $response = $this->router->postIngress([
                'binding' => $record->hidden_chat_id,
                'opaque' => $record->opaque(),
                'kind' => 'message',
                'text' => "Изучи новую заявку клиента и начни работу.\n\n"
                    . 'Данные по доступам и компании клиента находятся в папке ~/clients/*' . $source_chat_id . "/\n\n"
                    . 'Сначала прочитай AGENTS.md из этой папки. '
                    . 'Если папка не существует или не содержит AGENTS.md — сразу остановись и сообщи об этом.',
                'attachments' => [[
                    'type' => 'document',
                    'url' => $url,
                    'name' => basename($log_path),
                    'size_bytes' => filesize($log_path),
                ]],
                'meta' => $meta,
            ]);
            if (($response['accepted'] ?? false) !== true) throw new RuntimeException('Router rejected Oberbot initial ingress');
            $record = $record->withIngressSent();
            $this->store->saveHandoff($record);
        }

        return $record;
    }

    private function topicTitle(int $ticket_id, string $title): string
    {
        return mb_substr('#' . $ticket_id . ' — ' . $title, 0, 128);
    }
}
