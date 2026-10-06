<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class TelegramBotClientFactory implements BotManagementClientFactoryInterface
{
    public function __construct(private string $base_url, private string $endpoint_prefix) {}

    public function create(BotRecord $bot): BotManagementClientInterface
    {
        return new TelegramApiClient($bot->token, $this->base_url, $this->endpoint_prefix);
    }

    public function createFromToken(string $token): BotManagementClientInterface
    {
        return new TelegramApiClient($token, $this->base_url, $this->endpoint_prefix);
    }
}
