<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Data\OberbotAiHandoff;
use CodexMultitenant\TransportTelegram\Data\OberbotAiTurn;
use losthost\DB\DBList;
use Throwable;

final class DbOberbotIntegrationStore implements OberbotHandoffStoreInterface, TurnStateStoreInterface
{
    public function findByTicketId(int $ticket_id): ?OberbotHandoffRecord
    {
        try { return $this->handoff(new OberbotAiHandoff(['ticket_id' => $ticket_id])); }
        catch (Throwable $error) { if ($error->getCode() !== -10002) throw $error; return null; }
    }

    public function findByOpaque(string $opaque): ?OberbotHandoffRecord
    {
        if (!str_contains($opaque, ':')) return null;
        [$chat_id, $thread_id] = explode(':', $opaque, 2);
        if (!ctype_digit($thread_id)) return null;
        try { return $this->handoff(new OberbotAiHandoff(['hidden_chat_id' => $chat_id, 'hidden_thread_id' => (int) $thread_id])); }
        catch (Throwable $error) { if ($error->getCode() !== -10002) throw $error; return null; }
    }

    public function saveHandoff(OberbotHandoffRecord $record): void
    {
        try { $row = new OberbotAiHandoff(['ticket_id' => $record->ticket_id]); }
        catch (Throwable) { $row = new OberbotAiHandoff(); $row->ticket_id = $record->ticket_id; }
        $row->source_chat_id = $record->source_chat_id;
        $row->source_thread_id = $record->source_thread_id;
        $row->hidden_chat_id = $record->hidden_chat_id;
        $row->hidden_thread_id = $record->hidden_thread_id;
        $row->log_sent = $record->log_sent;
        $row->ingress_sent = $record->ingress_sent;
        $row->write();
    }

    public function loadTurn(string $opaque): ?TurnState
    {
        try { return $this->turn(new OberbotAiTurn(['opaque' => $opaque])); }
        catch (Throwable $error) { if ($error->getCode() !== -10002) throw $error; return null; }
    }

    public function saveTurn(TurnState $state): void
    {
        try { $row = new OberbotAiTurn(['opaque' => $state->opaque]); }
        catch (Throwable) { $row = new OberbotAiTurn(); $row->opaque = $state->opaque; }
        $row->ticket_id = $state->ticket_id;
        $row->source_chat_id = $state->source_chat_id;
        $row->started_at = $state->started_at;
        $row->last_heartbeat_at = $state->last_heartbeat_at;
        $row->write();
    }

    public function deleteTurn(string $opaque): void
    {
        try { (new OberbotAiTurn(['opaque' => $opaque]))->delete(); }
        catch (Throwable $error) { if ($error->getCode() !== -10002) throw $error; }
    }

    public function timedOutBefore(\DateTimeImmutable $cutoff): array
    {
        $result = [];
        $list = new DBList(OberbotAiTurn::class, 'last_heartbeat_at < ?', [$cutoff->format('Y-m-d H:i:s')]);
        while (($row = $list->next()) instanceof OberbotAiTurn) $result[] = $this->turn($row);
        return $result;
    }

    private function handoff(OberbotAiHandoff $row): OberbotHandoffRecord
    {
        return new OberbotHandoffRecord((int) $row->ticket_id, (string) $row->source_chat_id, (int) $row->source_thread_id, (string) $row->hidden_chat_id, $row->hidden_thread_id === null ? null : (int) $row->hidden_thread_id, (bool) $row->log_sent, (bool) $row->ingress_sent);
    }

    private function turn(OberbotAiTurn $row): TurnState
    {
        return new TurnState((string) $row->opaque, (int) $row->ticket_id, (string) $row->source_chat_id, $row->started_at, $row->last_heartbeat_at);
    }
}
