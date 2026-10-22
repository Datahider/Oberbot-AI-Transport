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
  - `POST /api/v2/transport/ingress`
  - `POST /api/v2/transport/control`
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
- `transport.telegram_polling_enabled`
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
  - status state по `opaque`
  - long polling state процесса
- Таблица прежних привязок `chat_id -> tenant` сохраняется только как источник
  миграции и не используется runtime после переключения на Router v2.

## Session Identity Contract
- `opaque` передаётся в `Router` как opaque string без transport-side интерпретации со стороны `Router`.
- `Transport-Telegram` сам формирует `opaque` из Telegram target:
  - если `thread_id` отсутствует: `<chat_id>`
  - если `thread_id` присутствует: `<chat_id>:<thread_id>`
- Для разных thread одного `chat_id` используются разные `opaque`, но один и
  тот же `binding`, равный строковому `chat_id`.

## Ingress Contract
- Каждое поддержанное входящее `message` нормализуется в ingress event:

```json
{
  "binding": "-100123",
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
- Transport отправляет ingress в `POST /api/v2/transport/ingress`.
- `binding` равен полному строковому `chat_id` без `thread_id`.
- `opaque` сохраняет прежний формат и включает `thread_id` для forum topic.
- Transport не определяет и не передаёт `tenant`.
- Каждое поддержанное и не отклонённое локальными transport-правилами message
  передаётся Router независимо от наличия binding.
- Для group/supergroup ingress-ignore scope также задаёт режим доступа:
  - в ignored scope transport не удаляет сообщения участников и не передаёт их в `Router`;
  - в ignored scope локально обрабатывается только `/unignore` от creator/administrator группы;
  - в active scope сообщения creator/administrator обрабатываются штатно;
  - в active scope сообщения остальных отправителей удаляются и не передаются в `Router`;
  - сообщение, отправленное анонимным administrator от имени той же group/supergroup, считается administrator message;
  - private chat этой фильтрации не подлежит.
- Для удаления чужих сообщений bot должен быть administrator с правом `can_delete_messages`.
- Решение о наличии binding и дальнейшей маршрутизации принимает Router.
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
- `/install` и обычный `/start` без payload в private chat выпускают через Router одноразовый pairing ticket на 3 часа для установки нового Core; в группах команды отклоняются локально.
- Ответ `/install` и обычного `/start` содержит готовую shell-команду `curl -fsSL https://codexgate.ru/install-core.php.gz | gunzip | sudo php -- --pair <code>` и пояснение, что новый код можно получить через `/start`; `/install` остаётся техническим алиасом и в ответе не упоминается; команда отправляется как HTML `<pre><code>` блок для копирования одним нажатием.
- Инструкция установки отправляется отдельным Telegram message, а не reply на message с командой.
- `/pair <code>` обрабатывается только в private chat и первично привязывает этот chat к Core.
- Telegram deep link имеет формат `https://t.me/<bot_username>?start=pair_<code>`; полученная в private chat команда `/start pair_<code>` эквивалентна `/pair <code>`.
- Любой другой `/start` payload не считается transport-local pairing command.
- Transport удаляет Telegram message с кодом до вызова Router и не записывает код в лог.
- Повтор webhook после уже успешного удаления message должен считать Telegram-ошибку `message to delete not found` успешным идемпотентным удалением; остальные ошибки удаления не подавляются.
- Transport передаёт `binding` как строковый Telegram `chat_id`, `label` — имя Telegram-пользователя или `Telegram private chat`.
- Пустая `/pair`, `/pair` в группе и отказ Router обрабатываются локально и не передаются как ingress.
- При успехе transport отвечает `Core привязан.`; при отказе — `Код неверен, просрочен или уже использован.` либо `Этот чат уже привязан к другому Core.`.
- В group/supergroup slash-команда, явно адресованная другому боту через `@username`, игнорируется и не передаётся Router; голые команды и команды, адресованные текущему боту, обрабатываются как прежде.
- `/done` в group/supergroup зарезервирована для других ботов: transport игнорирует её и не передаёт Router.
- `Transport-Telegram` обрабатывает локально только `/status`.
- Поддержанные формы:
  - `/status`
  - `/status on`
  - `/status off`
- `/status` без аргумента показывает текущее состояние status-message и краткую справку.
- `Transport-Telegram` также обрабатывает локально команды suppress/annotation:
  - `/ignore`
  - `/unignore`
  - `/voicequote`
  - `/voicesilent`
  - `/voiceglobal`
  - `/note <text>`
  - `/close`
- `/ignore` включает ingress-ignore для текущего Telegram target:
  - если сообщение пришло из forum topic, ignore scope равен точному `opaque` (`<chat_id>:<thread_id>`)
  - если сообщение пришло не из forum topic, ignore scope равен всему `chat_id`
- После `/ignore` transport отвечает подтверждением в тот же chat/topic.
- После успешного `/ignore` transport больше не пересылает в `Router` никакие последующие сообщения из этого scope.
- `/unignore` выключает ingress-ignore для того же scope, который определился бы для `/ignore` в текущем сообщении.
- В ignored group/supergroup scope `/unignore` принимается только от creator/administrator; та же команда от обычного участника остаётся в чате без каких-либо действий transport.
- После `/unignore` transport отвечает подтверждением в тот же chat/topic.
- `/voicequote` включает показ outbound `kind=transcript`: в общем чате — для всей группы, внутри forum topic — как локальное переопределение этого topic.
- `/voicesilent` аналогично отключает показ транскрипции.
- `/voiceglobal` внутри forum topic удаляет локальное переопределение и возвращает наследование настройки общего чата группы; в общем или private chat возвращает системное значение по умолчанию.
- `/voices`, `/voice` и `/voice <name>` не обрабатываются локально: transport
  передаёт их в Router как обычные ingress message, а Core обрабатывает их
  как transport commands.
- Все три команды отвечают подтверждением, влияют только на Telegram-представление транскрипции и никогда не передаются в `Router`.
- `/note <text>` не делает никаких transport-side действий, не отвечает пользователю и не пересылается в `Router`.
- `/note` без непустого текста также не пересылается в `Router`.
- `/close` работает только в forum topic.
- `/close` вне forum topic отвечает диагностикой и не пересылается в `Router`.
- `/close` в forum topic:
  - меняет icon темы на `transport.done_forum_topic_icon_custom_emoji_id`
  - затем закрывает тему
  - затем отвечает подтверждением в тот же topic
- Если `transport.done_forum_topic_icon_custom_emoji_id` не настроен, `/close` отвечает диагностикой и не выполняет partial-action.
- Неизвестные slash-команды не являются transport-local command и должны уходить в `Router` как обычный текст.

## Telegram Bot Commands Menu Contract
- При каждом старте ingress-процесса transport обязан вызывать Telegram Bot API `setMyCommands` до первого `getUpdates`.
- Transport обязан публиковать ровно эти команды в bot menu:
  - `/status` — `Показать или переключить статус`
  - `/ignore` — `Игнорировать этот чат или топик`
  - `/unignore` — `Снять игнор с этого чата или топика`
  - `/voicequote` — `Показывать расшифровку голоса`
  - `/voicesilent` — `Скрывать расшифровку голоса`
  - `/voiceglobal` — `Наследовать настройку группы`
  - `/voices` — `Показать доступные голоса`
  - `/voice` — `Показать или выбрать голос`
  - `/note` — `Сохранить заметку без отправки дальше`
  - `/close` — `Закрыть текущий топик форума`
  - `/stop` — `Прервать текущий ход агента`
  - `/reset` — `Сбросить codex session для текущего диалога`
  - `/session` — `Проверить или задать codex session для текущего диалога`
  - `/limits` — `Показать текущие лимиты Codex`
  - `/model` — `Показать или выбрать модель Codex`
  - `/provider` — `Показать или выбрать провайдера: codex или claude`
- `/stop`, `/reset`, `/session`, `/limits`, `/model`, `/provider` не являются transport-local command и публикуются только для bot menu.
- При каждом старте основного Transport-сервиса меню переустанавливается для
  всех enabled-ботов до запуска inbound/outbound workers.
- Ошибка `setMyCommands` одного бота логируется с `bot_key` и `username`, но не прерывает
  синхронизацию остальных ботов и запуск Transport.
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
- Если доставка поддержанного outbound event завершилась невременной ошибкой, transport:
  - не завершает outbound worker;
  - отправляет в тот же Telegram chat/topic диагностическое текстовое сообщение с `event_id`, `kind` и текстом ошибки;
  - после попытки отправить диагностику считает исходное событие обработанным и сохраняет его `event_id` как cursor, чтобы ошибочное событие не блокировало очередь;
  - если диагностическое сообщение также не удалось отправить, пишет эту ошибку в runtime log, сохраняет cursor и продолжает обработку следующих событий.
- Временные ошибки `TelegramRateLimitException`, `TelegramUnavailableException` и `RouterUnavailableException` сохраняют retry-поведение и не продвигают cursor до успешной доставки.

### Outbound Rendering
- Transport обрабатывает только явно перечисленные ниже `kind`.
- Любой неизвестный `kind` молча игнорируется: Telegram API не вызывается, fallback на `final` запрещён, delivery не считается ошибочным.
- `kind=tool` является служебным событием и не отправляется в Telegram.
- Transport поддерживает только `kind=tool` с `meta.type=list` и непустым строковым `meta.request_id`.
- На такой запрос Transport публикует через `POST /api/v2/transport/ingress` ответ для того же transport target:
  - `binding` равен `chat_id`, полученному штатным `resolveOpaque`;
  - `opaque` переносится без изменений;
  - `kind=tool`, `text=""`, `attachments=[]`;
  - `meta.type=list`, исходный `meta.request_id` без изменений и `meta.tools=[]`.
- `meta.type=call|result|error` и любой другой `meta.type` не поддерживаются.
- Неизвестный `meta.type`, отсутствующий или пустой `meta.request_id` и некорректный tool payload являются явной ошибкой обработки. Они не вызывают Telegram API, не создают ingress-ответ и не прерывают обработку последующих outbound events. Диагностическое Telegram-сообщение для такой ошибки не отправляется.
- `kind=final`:
  - markdown от core конвертируется в Telegram HTML через `fastvolt/markdown`
  - затем применяется существующая процедура очистки несовместимых тегов
  - transport передаёт содержимое без собственного префикса
  - если итоговый текст не помещается в Telegram message limit, transport вместо сообщения отправляет файл с полным текстом и caption `Ответ получился слишком длинным, поэтому отправил его файлом.`
- `kind=commentary`:
  - markdown конвертируется в Telegram HTML через тот же renderer
  - весь результат оборачивается в `<i>...</i>`
  - сразу после отправки transport обязан выполнить `sendChatAction(typing)`
  - если итоговый текст не помещается в Telegram message limit, transport вместо сообщения отправляет файл с полным текстом и caption `Ответ получился слишком длинным, поэтому отправил его файлом.`
- `kind=warning`:
  - markdown от core конвертируется в Telegram HTML через тот же renderer;
  - transport добавляет префикс `⚠️ `;
  - если итоговый текст не помещается в Telegram message limit, transport вместо сообщения отправляет файл с полным текстом и caption `Предупреждение получилось слишком длинным, поэтому отправил его файлом.`;
- `kind=system`:
  - markdown от core конвертируется в Telegram HTML через тот же renderer;
  - transport добавляет префикс `⚙️ `;
  - если итоговый текст не помещается в Telegram message limit, transport вместо сообщения отправляет файл с полным текстом и caption `Системное сообщение получилось слишком длинным, поэтому отправил его файлом.`;
- `kind=transcript`:
  - исходный текст экранируется как HTML и не интерпретируется как markdown
  - результат оборачивается в `<blockquote expandable>...</blockquote>` без префикса
  - если итоговый текст не помещается в Telegram message limit, transport вместо сообщения отправляет файл с полным текстом и caption `Ответ получился слишком длинным, поэтому отправил его файлом.`
  - системное значение по умолчанию — отображать
  - forum topic сначала использует своё локальное переопределение, затем настройку общего чата группы, затем системное значение
- `kind=status`:
  - обновляет service-message по контракту выше
- `kind=heartbeat`:
  - не отправляет текст
  - вызывает только `sendChatAction(typing)`
- `kind=document`:
  - содержит ровно один attachment с непустыми `file_id` и `name`
  - transport скачивает `GET {file_exchange.base_url}/{file_id}` с `Authorization: Bearer <file_exchange.token>` во временный файл
  - transport передаёт временный файл в Telegram `sendDocument`
  - непустой `text` рендерится через `TelegramTextRenderer::renderFinal()` и передаётся как caption с parse mode `HTML`; пустой `text` допустим
  - forum topic передаётся в `message_thread_id`
  - временный файл удаляется после успеха или ошибки
  - невалидный attachment или ошибка скачивания явно завершают delivery ошибкой
- `kind=image`:
  - содержит ровно один attachment с непустыми `file_id`, `name` и `mime`
  - `mime` должен быть равен `image/jpeg`, `image/png` или `image/webp`
  - transport скачивает attachment так же, как для `kind=document`
  - transport передаёт временный файл в Telegram `sendPhoto`
  - непустой `text` рендерится через `TelegramTextRenderer::renderFinal()` и передаётся как caption с parse mode `HTML`; пустой `text` допустим
  - forum topic передаётся в `message_thread_id`
  - временный файл удаляется после успеха или ошибки
  - невалидный attachment или ошибка доставки явно завершают delivery ошибкой
- `kind=voice`:
  - содержит ровно один attachment с непустыми `file_id`, `name` и `mime`
  - `mime` должен быть равен `audio/ogg`
  - `text` может быть пустым или содержать Markdown caption
  - transport скачивает attachment так же, как для `kind=document`
  - непустой `text` рендерится через `TelegramTextRenderer::renderFinal()` и передаётся как caption с parse mode `HTML` в Telegram `sendVoice`
  - forum topic передаётся в `message_thread_id`
  - временный файл удаляется после успеха или ошибки
  - невалидный attachment или ошибка доставки явно завершают delivery ошибкой

## Telegram HTML Contract
- Parse mode для `final`, `commentary`, `status` — `HTML`.
- Собственные markdown/html-converter'ы писать запрещено.
- Для markdown->HTML используется `fastvolt/markdown`, зафиксированный на production commit `1056e1bbbba7a7b908aa777eebe4b7569192bebf`.
- Исходный Markdown передаётся в `fastvolt/markdown` без предварительного HTML-экранирования; экранирование обычного текста, inline-code и fenced code block выполняет сама библиотека.
- Fenced code block с языком `text` рендерится как `<pre><code class="language-text">...</code></pre>`.
- Процедура очистки несовместимых тегов переносится из старого reference-transport без отсебятины.
- Очистка несовместимых тегов не декодирует HTML-сущности, созданные библиотекой: `&lt;`, `&gt;`, `&amp;` в ячейках таблиц и в любом другом месте остаются экранированными.
- Перевод заголовков `h1`/`h2` в верхний регистр применяется только к тексту; HTML-теги и HTML-сущности внутри заголовка сохраняются без изменения регистра.

## Telegram Webhook Endpoint

- Подготовительный webhook endpoint публикуется отдельно от действующего polling-процесса по адресу `https://webhook.codexgate.ru/`.
- Наличие endpoint само по себе не переключает Telegram с `getUpdates`: вызов `setWebhook` выполняется только отдельным согласованным шагом.
- Endpoint принимает только `POST` с JSON object Telegram Update.
- Запрос обязан содержать заголовок `X-Telegram-Bot-Api-Secret-Token`, в точности совпадающий с `telegram.webhook_secret_token`.
- Отсутствующий или неверный secret возвращает `401` без обработки payload.
- Метод, отличный от `POST`, возвращает `405`.
- Пустой, невалидный JSON или JSON не-object возвращает `400`.
- После успешного вызова `TelegramTransport::handleIncomingUpdate()` endpoint возвращает `204` без тела, в том числе для неподдержанного Telegram Update.
- `RouterUnavailableException`, `TelegramUnavailableException` и `TelegramRateLimitException` возвращают `503`, чтобы Telegram повторил доставку.
- Любая иная ошибка обработки возвращает `500`; ошибка пишется в runtime log, а детали исключения клиенту не раскрываются.
- Endpoint не хранит Telegram `update_id` и не изменяет polling cursor.
- До отдельного решения владельца polling-процесс и его данные остаются без изменений.

## Multi-bot Registry and Handover

- Bot token не является runtime config value. Bot tokens хранятся в локальной БД Transport в зашифрованном виде.
- Runtime config содержит только один master key шифрования `telegram.bot_token_encryption_key`; ключ обязан декодироваться в 32 байта и не хранится в БД.
- Каждый зарегистрированный бот имеет стабильный случайный `bot_key`, Telegram user ID, username, отдельный webhook secret и признак default bot.
- Webhook URL имеет вид `https://webhook.codexgate.ru:8443/{bot_key}`. Endpoint сначала загружает bot record по точному `bot_key`, затем проверяет secret этого бота и создаёт Telegram API client с его token.
- Один Telegram `chat_id` имеет не более одного активного бота. Локальная route table хранит `chat_id -> bot_key`.
- Если route для `chat_id` отсутствует, outbound использует явно назначенного default bot. Наличие более одного default bot запрещено схемой и регистрационной процедурой.
- `bot_key` не добавляется в `opaque`, Router payload или Core session identity.
- Добавление зарегистрированного нового бота в группу определяется по Telegram update `my_chat_member` со статусом `member` или `administrator`.
- Передача группы сериализуется одним `DB::getLock('telegram-bot-handover:' . chat_id)`; bot records отдельными lock не защищаются.
- Внутри lock Transport повторно читает текущий route. Если активен другой бот:
  1. старый бот отправляет `О, коллега! Привет. Пост сдал.`;
  2. новый бот отправляет `Пост принял.`;
  3. route атомарно заменяется на нового бота;
  4. старый бот вызывает `leaveChat`.
- Если route отсутствует или уже указывает на нового бота, Transport только фиксирует нового бота активным без handover-сообщений и `leaveChat`.
- При фактической смене активного бота Transport обнуляет сохранённые `status message_id` и `last_text` всех runtime-сессий этого `chat_id`. Для группы это `chat_id` и все `chat_id:thread_id`; для лички — точный `chat_id`.
- Lock всегда освобождается в `finally`. Невозможность получить lock является явной временной ошибкой webhook.
- Outbound извлекает `chat_id` из существующего `opaque`, выбирает route/default bot локально и отправляет событие его токеном. Контракт Router не меняется.
- В личном чате несколько зарегистрированных ботов могут работать одновременно; принудительный выход или handover-сообщения не используются.
- При получении любого сообщения из личного чата Transport до передачи ingress в Router записывает отправившего update бота как активного для `chat_id`.
- Поэтому пользователь получает следующий outbound через того бота, которому написал последним. Если до готовности ответа пользователь написал другому боту, маршрут уже указывает на второго бота и ответ отправляется через него.
- Выбор активного бота выполняется непосредственно перед Telegram API delivery, а не при чтении outbound event из Router.
- Управляющие команды, включая будущее подключение новых bot token, могут обрабатываться в личном чате без изменения Router или Core session identity.

## Bot Registration and Removal Commands

- `/addbot` и `/delbot` обрабатываются Transport только в личном чате и не передаются в Router.
- После команды Transport запоминает ожидаемое действие на 5 минут и запрашивает bot token. Ключ ожидания: `bot_key + private chat_id + Telegram user_id`.
- Просроченное ожидание удаляется; полученное сообщение после истечения срока обрабатывается как обычное ingress-сообщение.
- Сообщение с bot token удаляется из Telegram до вызова `getMe`, не логируется и никогда не передаётся в Router.
- `/addbot`: token проверяется через `getMe`. Для нового Telegram user ID Transport создаёт `bot_key` и webhook secret. Для уже зарегистрированного Telegram user ID Transport заменяет только token и актуальный username, сохраняя `bot_key`, webhook secret, routes, `is_default` и `deletion_protected`. Затем Transport устанавливает команды и webhook и сообщает, был бот добавлен или его token обновлён.
- `/updatetoken` не запрашивает token. Он объясняет, что новый token нужно отправить через `/addbot` любому из ботов с `deletion_protected=true`, и выводит до 5 случайных `@username` таких ботов. Команда обрабатывается локально и не уходит в Router.
- Telegram command menu по умолчанию пустое. Для `all_private_chats` показываются все команды, кроме групповых `/ignore`, `/unignore`, `/close`. Для `all_chat_administrators` показываются все команды, кроме личных `/addbot`, `/delbot`, `/updatetoken`. Обычные участники групп меню команд не видят.
- Области command menu устанавливаются при новой регистрации, обновлении token и административной перенастройке webhook.
- `/delbot`: `getMe` переданного token обязан вернуть Telegram user ID текущего бота. Это единственная проверка права на удаление.
- Каждый bot record имеет `deletion_protected`. Защищённый бот не удаляется через Telegram даже при верном token. Признак меняется только административно.
- Перед удалением бот во всех чатах типа `group`/`supergroup`, где он является активным, отправляет: `Всем спасибо, я пошел. Продолжить вы можете с другими ботами:` и до 5 случайных `@username` ботов с `deletion_protected=true`.
- После прощания бот выходит из этих групп. Все его group/private routes удаляются, webhook удаляется, затем удаляются token и bot record. Router bindings и Core sessions не изменяются.
- Если один из шагов Telegram API завершился ошибкой, удаление прекращается и ошибка webhook возвращается Telegram для повтора update.

## Group Write Access

- Каждая Telegram-группа имеет единый для всех тем режим приёма сообщений: `admins` или `members`. При отсутствии записи используется `admins`.
- В `admins` Transport принимает сообщения владельца, администраторов и анонимных администраторов; остальные сообщения удаляются. В `members` Transport принимает сообщения всех участников, которым Telegram разрешил писать.
- `/access` без аргумента показывает текущий режим. `/access admins` и `/access members` меняют его. Команда работает только в group/supergroup и всегда отвечает в той же теме.
- Менять режим может только Telegram member со `status=creator`. Анонимный администратор не считается доказанным владельцем.
- В `admins` обычный участник не может даже просматривать `/access`, поскольку его сообщения запрещены. В `members` текущий режим может посмотреть любой участник, но изменить — только владелец.
- `members` разрешает обычные ingress-сообщения, но не повышает права на локальные административные команды. `/ignore`, `/unignore` и `/close` остаются доступны только владельцу/администратору.

## Process Loop
- Один процесс transport instance:
  - запускает Telegram long polling только при `transport.telegram_polling_enabled=true`
  - отправляет ingress в `Router`
  - делает long polling `Router` outbound
  - отправляет outbound в нужные Telegram chats/topics
- Один transport process обслуживает все чаты данного `transport_instance_id`.
- При `transport.telegram_polling_enabled=false` supervisor не запускает и не перезапускает inbound worker; outbound worker продолжает работать без изменений.
- Для webhook-режима production config обязан устанавливать `transport.telegram_polling_enabled=false` до вызова Telegram `setWebhook`.

## Production Runtime Placement

- Production-код webhook endpoint и outbound worker размещается в одном каталоге `/home/codexgate-webhook/htdocs/webhook.codexgate.ru`.
- Оба процесса работают от системного пользователя `codexgate-webhook` и читают один файл `etc/config.php`.
- Systemd-сервис `codex-multitenant-telegram` запускает из этого каталога только supervisor/outbound worker; Telegram ingress обслуживается PHP-FPM через webhook endpoint.
- Прежний каталог пользователя `pio-bots` после переключения не является runtime source, но не удаляется до отдельного решения владельца.

## Binding Migration Contract

- До переключения ingress все строки прежней локальной таблицы
  `chat_id -> tenant` должны быть перенесены в Router.
- Канонический экспорт запускается на действующем Transport:

```bash
php bin/export-bindings.php
```

- Команда пишет в standard output JSON Lines, по одному объекту на binding:

```json
{"tenant":"user","binding":"123456789","label":"Telegram 123456789"}
```

- Экспорт не изменяет локальную таблицу.
- Каждая строка импортируется штатной Router-командой
  `bin/provision-binding.php` с фактическим `transport_instance_id`, полученным
  Router из transport token. Значение локального
  `transport.transport_instance_id` не считается источником истины для Router
  authentication principal.
- До переключения количества и пары `binding -> tenant` в обеих системах
  должны совпасть.
- Локальная таблица после переключения не удаляется автоматически.

## Example Config

```php
<?php

return [
    'router' => [
        'base_url' => 'https://router.example',
        'transport_token' => 'router-transport-token',
    ],
    'telegram' => [
        'base_url' => 'https://api.telegram.org',
        'endpoint_prefix' => 'bot',
        'bot_token_encryption_key' => 'base64-encoded-32-byte-key',
        'webhook_base_url' => 'https://webhook.codexgate.ru:8443',
        'webhook_ip_address' => '88.99.59.157',
    ],
    'file_exchange' => [
        'base_url' => 'https://files.ioannidis.ru',
        'token' => 'file-exchange-token',
    ],
    'transport' => [
        'transport_instance_id' => 'telegram-main',
        'outbound_poll_interval_ms' => 250,
        'telegram_poll_timeout_seconds' => 20,
        'telegram_polling_enabled' => true,
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
