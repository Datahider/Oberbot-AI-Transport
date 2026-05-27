<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

final class TelegramBotCommandCatalog
{
    /**
     * @return list<array{command: string, description: string}>
     */
    public static function all(): array
    {
        return [
            [
                'command' => 'status',
                'description' => 'show or toggle status message',
            ],
            [
                'command' => 'ignore',
                'description' => 'suppress ingress for this chat/topic',
            ],
            [
                'command' => 'unignore',
                'description' => 'resume ingress for this chat/topic',
            ],
            [
                'command' => 'note',
                'description' => 'store note locally without forwarding',
            ],
            [
                'command' => 'done',
                'description' => 'close current forum topic',
            ],
        ];
    }
}
