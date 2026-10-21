# AGENTS.md

## Проект
- `Oberbot-AI-Transport` — отдельный проект, созданный как fork `Transport-Telegram`.
- Проект не входит в `Codex-Multitenant`.
- Назначение: Telegram transport для скрытой AI-группы Oberbot и учёт времени Codex в Oberbot.

## Архитектурные границы
- Репозиторий знает только transport ingress/outbound и нормализацию payload.
- Репозиторий не содержит tenant core runtime-логики.
- Репозиторий не содержит router storage-логики.
- Любой transport-native media должен нормализоваться до text/references до передачи в `Router`.

## Контракты
- Источник истины — фактический код, `CONTRACT.md` и `OBERBOT_INTEGRATION_CONTRACT.md`.
- Любой новый endpoint или webhook contract сначала фиксируется в документации.
- Затем добавляется fail-first тест.
- Только затем добавляется код.

## Технологический базис
- Основной runtime: PHP.
- Канонический внешний boundary — Telegram-compatible HTTP API.

## Деплой
- Штатный production deployment path должен быть описан до первого деплоя.
