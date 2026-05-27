<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use CodexMultitenant\TransportTelegram\Data\IngressIgnoreScopeState;
use Throwable;

final class DbIgnoreStateStore implements IgnoreStateStoreInterface
{
    public function isIgnored(string $scope): bool
    {
        try {
            $row = new IngressIgnoreScopeState(['scope' => $scope]);
        } catch (Throwable) {
            return false;
        }

        return (bool) $row->ignored;
    }

    public function setIgnored(string $scope, bool $ignored): void
    {
        try {
            $row = new IngressIgnoreScopeState(['scope' => $scope]);
        } catch (Throwable) {
            $row = new IngressIgnoreScopeState();
            $row->scope = $scope;
        }

        $row->ignored = $ignored ? 1 : 0;
        $row->write();
    }
}
