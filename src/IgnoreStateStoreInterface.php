<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface IgnoreStateStoreInterface
{
    public function isIgnored(string $scope): bool;

    public function setIgnored(string $scope, bool $ignored): void;
}
