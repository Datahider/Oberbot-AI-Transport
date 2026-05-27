<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface CursorStoreInterface
{
    public function loadRouterAfterId(): int;

    public function saveRouterAfterId(int $after_id): void;

    public function loadTelegramOffset(): int;

    public function saveTelegramOffset(int $offset): void;
}
