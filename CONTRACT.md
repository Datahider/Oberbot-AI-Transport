# Telegram Transport Contract

## Service Role
- `Transport-Telegram` — transport gateway для Telegram Bot API поверх `Router`.
- `Transport-Telegram` общается только с:
  - Telegram Bot API
  - `Router` transport endpoints
- `Transport-Telegram` не читает локальные очереди `Core` и не использует core endpoint'ы.

## Router Boundary
- Канонический контракт `Router` задаётся `../Router/CONTRACT.md`.
- `Transport-Telegram` использует только:
  - `POST /api/v1/transport/ingress`
  - `GET /api/v1/transport/outbound`
- Любая HTTP/auth/JSON ошибка от `Router` считается operational failure и должна приводить к исключению без fallback.

## Telegram Boundary
- Канонический operational mode: Telegram long polling через `getUpdates`.
- Поддержанные inbound media types:
  - `document`
  - `photo`
  - `audio`
  - `video`
  - `voice`
- Неподдерживаемые inbound media types:
  - `video_note`
  - любые остальные update payload'ы вне `message`
- Если update не содержит ни поддержанного текста, ни поддержанного media, transport игнорирует его без fallback-ответов пользователю.
- Для supported media transport обязан:
  - получить `file_path` через Telegram Bot API `getFile`
  - скачать бинарник по Telegram file endpoint
  - загрузить файл в `files.ioannidis.ru`
  - передать в `Router` только нормализованный текст и `attachments`, без локальных путей и без Telegram bot token leakage

## Runtime Config Contract
- Канонический entrypoint: `bin/telegram-transport.php`
- Канонический runtime config path: `etc/config.php`
- `etc/config.php` должен возвращать массив с секциями:
  - `router.base_url`
  - `router.transport_token`
  - `telegram.bot_token`
  - `telegram.base_url`
  - `telegram.endpoint_prefix`
  - `file_exchange.base_url`
  - `file_exchange.token`
  - `transport.transport_instance_id`
  - `transport.outbound_poll_interval_ms`
  - `transport.telegram_poll_timeout_seconds`
  - `transport.unknown_chat_text`
  - `transport.status_instruction_text`
  - `transport.idle_status_text`
  - `transport.done_forum_topic_icon_custom_emoji_id`
  - `db.host`
  - `db.user`
  - `db.pass`
  - `db.name`
  - `db.prefix`

## Database Contract
- Transport хранит собственную MariaDB-схему через `losthost/db`.
- На старте transport обязан вызвать `initDataStructure()` для всех своих таблиц.
- Минимальные таблицы:
  - привязка `chat_id -> tenant`
  - status state по `opaque`
  - long polling state процесса

## Session Identity Contract
- `opaque` передаётся в `Router` как opaque string без transport-side интерпретации со стороны `Router`.
- `Transport-Telegram` сам формирует `opaque` из Telegram target:
  - если `thread_id` отсутствует: `<chat_id>`
  - если `thread_id` присутствует: `<chat_id>:<thread_id>`
- Для разных thread одного `chat_id` используются разные `opaque`, но один и тот же tenant.

## Ingress Contract
- Каждое поддержанное входящее `message` нормализуется в ingress event:

```json
{
  "tenant": "user@server",
  "opaque": "-100123:77",
  "kind": "message",
  "text": "user input",
  "attachments": [
    {
      "type": "document",
      "url": "https://files.ioannidis.ru/AbCd",
      "name": "report.pdf",
      "size_bytes": 12345
    }
  ],
  "meta": {
    "transport": "telegram",
    "chat_id": -100123,
    "thread_id": 77,
    "message_id": 501
  }
}
```

### Ingress Rules
- `tenant` определяется только по `chat_id` через локальную БД.
- Если для `chat_id` нет tenant mapping, transport отвечает текстом из `transport.unknown_chat_text` и не вызывает `Router`.
- `kind` на первом этапе всегда `message`.
- `text` может быть пустым, если message содержит supported media без подписи.
- Для message с text-only `attachments` равен пустому массиву.
- Для message с supported media `attachments` содержит минимум по одному объекту на каждый принятый файл.
- Telegram `audio` передаётся как `attachments[].type = audio`, Telegram `voice` — как `attachments[].type = voice`.
- Для `photo` transport обязан брать largest-size variant.
- Для `video_note` transport не вызывает ни `getFile`, ни upload в file exchange, ни `Router`.
- Если любой supported inbound file больше `20 * 1024 * 1024` bytes, transport обязан:
  - немедленно ответить пользователю диагностикой
  - не вызывать `Router`
  - не вызывать `getFile`
  - не скачивать бинарник
  - не вызывать upload в file exchange
- Если message содержит supported media и не отклонён по размеру, transport обязан сразу отправить временное Telegram animation-message:
  - source asset path: `config/googler-loader.gif`
  - transport отправляет этот GIF в Telegram как uploaded animation file
  - caption: `Обработка файла(ов)`
- После завершения upload всех attachment в file exchange transport обязан удалить это временное animation-message.
- Если `Router` возвращает `accepted=false` и `action.type=reply_text`, transport отправляет `action.text` в Telegram как обычное сообщение.

### Attachment Shape
- Каждый attachment — JSON object с полями:
  - `type`
  - `url`
  - `name`
  - `size_bytes`
- Допустимые значения `type`:
  - `document`
  - `image`
  - `audio`
  - `video`
- `url` — URL, который вернул `files.ioannidis.ru`.
- `name` — исходное имя файла из Telegram, если доступно; иначе transport генерирует стабильное имя по media type и `file_id`.
- `size_bytes` — размер файла в байтах из Telegram/file exchange, если доступен.
- Локальные filesystem path в `attachments` запрещены.
- Telegram internal `file_id` и `file_path` в `attachments` наружу не публикуются.

### File Exchange Boundary
- Для supported inbound media transport обязан загружать бинарник в file exchange через:
  - `POST {file_exchange.base_url}/up`
- Аутентификация:
  - `Authorization: Bearer <file_exchange.token>`
- Request:
  - `multipart/form-data`
  - field name `file`
- Response success:
  - `200 OK`
  - JSON object с полями:
    - `file_id`
    - `url`
    - `filename`
    - `size`
- Ошибка upload считается operational failure и должна приводить к исключению без fallback.

## Local Commands
- `Transport-Telegram` обрабатывает локально только `/status`.
- Поддержанные формы:
  - `/status`
  - `/status on`
  - `/status off`
- `/status` без аргумента показывает текущее состояние status-message и краткую справку.
- `Transport-Telegram` также обрабатывает локально команды suppress/annotation:
  - `/ignore`
  - `/unignore`
  - `/note <text>`
  - `/done`
- `/ignore` включает ingress-ignore для текущего Telegram target:
  - если сообщение пришло из forum topic, ignore scope равен точному `opaque` (`<chat_id>:<thread_id>`)
  - если сообщение пришло не из forum topic, ignore scope равен всему `chat_id`
- После `/ignore` transport отвечает подтверждением в тот же chat/topic.
- После успешного `/ignore` transport больше не пересылает в `Router` никакие последующие сообщения из этого scope.
- `/unignore` выключает ingress-ignore для того же scope, который определился бы для `/ignore` в текущем сообщении.
- После `/unignore` transport отвечает подтверждением в тот же chat/topic.
- `/note <text>` не делает никаких transport-side действий, не отвечает пользователю и не пересылается в `Router`.
- `/note` без непустого текста также не пересылается в `Router`.
- `/done` работает только в forum topic.
- `/done` вне forum topic отвечает диагностикой и не пересылается в `Router`.
- `/done` в forum topic:
  - меняет icon темы на `transport.done_forum_topic_icon_custom_emoji_id`
  - затем закрывает тему
  - затем отвечает подтверждением в тот же topic
- Если `transport.done_forum_topic_icon_custom_emoji_id` не настроен, `/done` отвечает диагностикой и не выполняет partial-action.
- Неизвестные slash-команды не являются transport-local command и должны уходить в `Router` как обычный текст.

## Telegram Bot Commands Menu Contract
- При каждом старте ingress-процесса transport обязан вызывать Telegram Bot API `setMyCommands` до первого `getUpdates`.
- Transport обязан публиковать ровно эти команды в bot menu:
  - `/status` — `Показать или переключить статус`
  - `/ignore` — `Игнорировать этот чат или топик`
  - `/unignore` — `Снять игнор с этого чата или топика`
  - `/note` — `Сохранить заметку без отправки дальше`
  - `/done` — `Закрыть текущий топик форума`
  - `/stop` — `Прервать текущий ход агента`
  - `/reset` — `Сбросить codex session для текущего диалога`
  - `/session` — `Проверить или задать codex session для текущего диалога`
- `/stop`, `/reset`, `/session` не являются transport-local command и публикуются только для bot menu.
- Ошибка `setMyCommands` считается operational failure и должна прерывать запуск без fallback.

## Ignore State Contract
- Ingress-ignore state хранится в БД транспорта.
- State key:
  - для forum topic: точный `opaque`
  - для обычного чата: строковый `chat_id`
- Ignore state влияет только на inbound forwarding в `Router`.
- Ignore state не влияет на outbound delivery и не отключает локальные transport-команды.

## Status Message Contract
- Для каждого `opaque` status-message включён по умолчанию.
- Status state хранится в БД транспорта.
- `kind=status` из outbound:
  - если status-message существует, transport редактирует его
  - если status-message отсутствует или удалён, transport сначала отправляет `transport.status_instruction_text`, затем создаёт новое service-message
- `/status off` выключает status-message для текущего `opaque`.
- `/status on` включает status-message для текущего `opaque` и немедленно обеспечивает наличие актуального service-message.

## Outbound Contract
- Transport читает outbound только через `GET /api/v1/transport/outbound`.
- Cursor `after_id` хранится persistently в БД транспорта.
- Для каждого outbound event transport дополнительно фильтрует по точному `opaque`.

### Outbound Rendering
- Transport обрабатывает только явно перечисленные ниже `kind`.
- Любой неизвестный `kind` молча игнорируется: Telegram API не вызывается, fallback на `final` запрещён, delivery не считается ошибочным.
- `kind=final`:
  - markdown от core конвертируется в Telegram HTML через `fastvolt/markdown`
  - затем применяется существующая процедура очистки несовместимых тегов
  - transport добавляет префикс `✅ `
  - если итоговый текст не помещается в Telegram message limit, transport вместо сообщения отправляет файл с полным текстом и caption `Ответ получился слишком длинным, поэтому отправил его файлом.`
- `kind=commentary`:
  - markdown конвертируется в Telegram HTML через тот же renderer
  - весь результат оборачивается в `<i>...</i>`
  - сразу после отправки transport обязан выполнить `sendChatAction(typing)`
  - если итоговый текст не помещается в Telegram message limit, transport вместо сообщения отправляет файл с полным текстом и caption `Ответ получился слишком длинным, поэтому отправил его файлом.`
- `kind=status`:
  - обновляет service-message по контракту выше
- `kind=heartbeat`:
  - не отправляет текст
  - вызывает только `sendChatAction(typing)`

## Telegram HTML Contract
- Parse mode для `final`, `commentary`, `status` — `HTML`.
- Собственные markdown/html-converter'ы писать запрещено.
- Для markdown->HTML используется `fastvolt/markdown`.
- Процедура очистки несовместимых тегов переносится из старого reference-transport без отсебятины.

## Process Loop
- Один процесс transport instance:
  - делает Telegram long polling
  - отправляет ingress в `Router`
  - делает long polling `Router` outbound
  - отправляет outbound в нужные Telegram chats/topics
- Один transport process обслуживает все чаты данного `transport_instance_id`.

## Example Config

```php
<?php

return [
    'router' => [
        'base_url' => 'https://router.example',
        'transport_token' => 'router-transport-token',
    ],
    'telegram' => [
        'bot_token' => 'telegram-bot-token',
        'base_url' => 'https://api.telegram.org',
        'endpoint_prefix' => 'bot',
    ],
    'file_exchange' => [
        'base_url' => 'https://files.ioannidis.ru',
        'token' => 'file-exchange-token',
    ],
    'transport' => [
        'transport_instance_id' => 'telegram-main',
        'outbound_poll_interval_ms' => 250,
        'telegram_poll_timeout_seconds' => 20,
        'unknown_chat_text' => 'Этот чат не привязан к tenant.',
        'status_instruction_text' => 'Закрепите следующее сообщение для отображения текущего статуса.',
        'idle_status_text' => 'Свободен',
        'done_forum_topic_icon_custom_emoji_id' => '5408906741125490282',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'user' => 'db-user',
        'pass' => 'db-pass',
        'name' => 'telegram_transport',
        'prefix' => 'ct_',
    ],
];
```
