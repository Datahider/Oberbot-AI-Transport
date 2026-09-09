<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Data\OpaqueStatusState;
use Throwable;

final class DbStatusStateStore implements StatusStateStoreInterface
{
    public function load(string $opaque): StatusState
    {
        try {
            $row = new OpaqueStatusState(['opaque' => $opaque]);
        } catch (Throwable) {
            return new StatusState($opaque, true, null, null);
        }

        $message_id = $row->message_id;

        return new StatusState(
            opaque: $opaque,
            enabled: (bool) $row->enabled,
            message_id: $message_id !== null ? (int) $message_id : null,
            last_text: $row->last_text !== null ? (string) $row->last_text : null,
            voice_quote_enabled: (bool) $row->voice_quote_enabled
        );
    }

    public function save(StatusState $state): void
    {
        try {
            $row = new OpaqueStatusState(['opaque' => $state->opaque]);
        } catch (Throwable) {
            $row = new OpaqueStatusState();
            $row->opaque = $state->opaque;
        }

        $row->enabled = $state->enabled ? 1 : 0;
        $row->message_id = $state->message_id;
        $row->last_text = $state->last_text;
        $row->voice_quote_enabled = $state->voice_quote_enabled ? 1 : 0;
        $row->write();
    }
}
