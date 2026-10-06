<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface BotManagementClientFactoryInterface extends BotClientFactoryInterface
{
    public function create(BotRecord $bot): BotManagementClientInterface;
    public function createFromToken(string $token): BotManagementClientInterface;
}
