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
                'command' => 'addbot',
                'description' => 'Подключить Telegram-бота',
            ],
            [
                'command' => 'delbot',
                'description' => 'Удалить этого Telegram-бота',
            ],
            [
                'command' => 'updatetoken',
                'description' => 'Обновить token Telegram-бота',
            ],
            [
                'command' => 'status',
                'description' => 'Показать или переключить статус',
            ],
            [
                'command' => 'ignore',
                'description' => 'Игнорировать этот чат или топик',
            ],
            [
                'command' => 'unignore',
                'description' => 'Снять игнор с этого чата или топика',
            ],
            [
                'command' => 'voicequote',
                'description' => 'Показывать расшифровку голоса',
            ],
            [
                'command' => 'voicesilent',
                'description' => 'Скрывать расшифровку голоса',
            ],
            [
                'command' => 'voiceglobal',
                'description' => 'Наследовать настройку группы',
            ],
            [
                'command' => 'voices',
                'description' => 'Показать доступные голоса',
            ],
            [
                'command' => 'voice',
                'description' => 'Показать или выбрать голос',
            ],
            [
                'command' => 'note',
                'description' => 'Сохранить заметку без отправки дальше',
            ],
            [
                'command' => 'done',
                'description' => 'Закрыть текущий топик форума',
            ],
            [
                'command' => 'stop',
                'description' => 'Прервать текущий ход агента',
            ],
            [
                'command' => 'reset',
                'description' => 'Сбросить codex session для текущего диалога',
            ],
            [
                'command' => 'session',
                'description' => 'Проверить или задать codex session для текущего диалога',
            ],
            [
                'command' => 'limits',
                'description' => 'Показать текущие лимиты Codex',
            ],
        ];
    }
}
