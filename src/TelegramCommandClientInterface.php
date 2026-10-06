<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface TelegramCommandClientInterface
{
    /**
     * @param list<array{command: string, description: string}> $commands
     * @param array{type: string}|null $scope
     */
    public function setMyCommands(array $commands, ?array $scope = null): void;
}
