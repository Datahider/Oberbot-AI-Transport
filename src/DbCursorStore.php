<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Data\ProcessCursorState;
use Throwable;

final class DbCursorStore implements CursorStoreInterface
{
    public function loadRouterAfterId(): int
    {
        return $this->load('router_after_id');
    }

    public function saveRouterAfterId(int $after_id): void
    {
        $this->save('router_after_id', $after_id);
    }

    public function loadTelegramOffset(): int
    {
        return $this->load('telegram_offset');
    }

    public function saveTelegramOffset(int $offset): void
    {
        $this->save('telegram_offset', $offset);
    }

    private function load(string $key): int
    {
        try {
            $row = new ProcessCursorState(['cursor_key' => $key]);
        } catch (Throwable) {
            return 0;
        }

        return max(0, (int) $row->cursor_value);
    }

    private function save(string $key, int $value): void
    {
        try {
            $row = new ProcessCursorState(['cursor_key' => $key]);
        } catch (Throwable) {
            $row = new ProcessCursorState();
            $row->cursor_key = $key;
        }

        $row->cursor_value = max(0, $value);
        $row->write();
    }
}
