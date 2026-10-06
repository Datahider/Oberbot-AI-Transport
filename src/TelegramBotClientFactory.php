<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class TelegramBotClientFactory implements BotClientFactoryInterface
{
    public function __construct(private string $base_url, private string $endpoint_prefix) {}

    public function create(BotRecord $bot): HandoverBotClientInterface
    {
        return new TelegramApiClient($bot->token, $this->base_url, $this->endpoint_prefix);
    }
}
