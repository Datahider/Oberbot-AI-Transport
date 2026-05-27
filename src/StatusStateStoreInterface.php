<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface StatusStateStoreInterface
{
    public function load(string $opaque): StatusState;

    public function save(StatusState $state): void;
}
