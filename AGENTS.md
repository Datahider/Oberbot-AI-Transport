# AGENTS.md

## Проект
- `Transport-Telegram` — отдельный репозиторий внутри каталога `Codex-Multitenant`.
- Назначение проекта: transport gateway для Telegram-compatible Bot API.

## Архитектурные границы
- Репозиторий знает только transport ingress/outbound и нормализацию payload.
- Репозиторий не содержит tenant core runtime-логики.
- Репозиторий не содержит router storage-логики.
- Любой transport-native media должен нормализоваться до text/references до передачи в `Router`.

## Контракты
- Источник истины — фактический код и будущие contract documents внутри репозитория.
- Любой новый endpoint или webhook contract сначала фиксируется в документации.
- Затем добавляется fail-first тест.
- Только затем добавляется код.

## Технологический базис
- Основной runtime: PHP.
- Канонический внешний boundary — Telegram-compatible HTTP API.
