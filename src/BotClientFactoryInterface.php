<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

interface BotClientFactoryInterface
{
    public function create(BotRecord $bot): HandoverBotClientInterface;
}
