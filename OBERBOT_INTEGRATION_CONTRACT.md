# Oberbot AI Transport Integration Contract

## Scope

`Oberbot-AI-Transport` сохраняет Telegram/Router contract исходного transport и добавляет только:

- синхронный local handoff из Oberbot;
- создание forum topic в одной скрытой Telegram supergroup;
- initial Router ingress для автоматического первого Codex turn;
- учёт длительности Codex turns в общей БД Oberbot.

Исходный `Codex-Multitenant/Transport-Telegram` не изменяется.

## Config

Дополнительные обязательные values:

- `oberbot.hidden_chat_id`
- `oberbot.ai_timer_subject`
- `oberbot.turn_timeout_seconds`, каноническое значение `12`
- `oberbot.heartbeat_coverage_seconds`, каноническое значение `4`
- `oberbot.submit_command_secret`

AI transport и Oberbot используют одну MariaDB database и один table prefix для integration/time-tracker tables.

## Local submit command

Канонический entrypoint:

```text
php bin/submit-oberbot-ticket.php <config-path> <job-path>
```

- Job path обязан указывать на regular readable file.
- Job — JSON object с `ticket_id`, `source_chat_id`, `source_thread_id`, `ticket_title`, `log_path`, `secret`.
- `secret` должен точно совпадать с `oberbot.submit_command_secret`.
- stdout success — JSON object `{"accepted":true,"hidden_thread_id":<int>}` и exit code `0`.
- Ошибка — ненулевой exit code; детали не показываются Telegram-пользователю.
- Команда синхронна: success возвращается только после всех шагов handoff.

## Topic creation

- Одной Oberbot ticket соответствует не более одного hidden topic.
- Topic title: `#<ticket_id> — <ticket_title>`.
- Title обрезается по Unicode code points до 128 символов.
- Повтор после partial failure использует уже созданный hidden topic и не создаёт дубль.
- Log file отправляется в hidden topic как Telegram document.

## Initial ingress

- Log file загружается в configured file exchange.
- Router ingress использует:
  - `binding` = hidden group ID;
  - `opaque` = `<hidden_chat_id>:<hidden_thread_id>`;
  - `kind` = `message`;
  - один document attachment с URL log file.
- Text начинается с инструкции изучить новую заявку.
- Text дополняется строкой:

```text
Данные по доступам и компании клиента в файле ~/clients/*<source_chat_id>.md
```

- `source_chat_id` подставляется без изменения. Для `-10037366278346` путь равен `~/clients/*-10037366278346.md`; дополнительный дефис не добавляется.
- Transport не читает и не проверяет client file. Это делает Codex на Core host.

## Time accounting

- Accounting применяется только к outbound events hidden topics, имеющих Oberbot ticket mapping.
- Первый `heartbeat` открывает integration turn state с `started_at` и `last_heartbeat_at`.
- Следующий `heartbeat` обновляет `last_heartbeat_at`.
- `final` закрывает turn с `end_time`, равным времени приёма `final`.
- Timeout-worker закрывает turn, если heartbeat не было 12 секунд.
- `end_time` timeout event равен `last_heartbeat_at + 4 seconds`.
- При закрытии создаётся один закрытый `losthost/time-tracker` event через `Timer::add()`:
  - timer subject = configured artificial AI agent;
  - object = Oberbot ticket ID;
  - project = source client group ID;
  - start/end = integration turn start/end.
- `Timer::start()` и `Timer::stop()` не используются.
- Параллельные turns создают независимые closed events.
