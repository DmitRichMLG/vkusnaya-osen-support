# Схема базы данных

Статус: реализовано на шаге 1 (`database/migrations/2026_10_01_000000_create_promo_tables.php`); фото и оценки операторов добавлены доработкой после шага 5, 01.10.2026 (`…_000001_add_photo_file_and_ratings.php`). PostgreSQL 18. Всё время в `timestamptz` (UTC); в МСК переводим при показе и при расчёте сроков и розыгрышей.

```mermaid
erDiagram
    participants ||--o{ messages : "вся переписка"
    participants ||--o{ tickets : "открытое — не больше одного"
    tickets ||--o{ messages : "ответы оператора, уведомления"
    tickets ||--o{ bot_decisions : "передачи оператору"
    messages ||--o| bot_decisions : "inbound: одно решение на входящее"
    messages ||--o| bot_decisions : "reply: ответ бота"
    users ||--o{ messages : "автор ответа"
    users |o--o{ tickets : "закрыл"
    users |o--o{ bot_decisions : "оценил"

    users {
        bigint id PK
        text name
        text email UK
        text password
    }
    participants {
        bigint id PK
        bigint telegram_user_id UK "личный чат: chat_id = user_id"
        text username
        text first_name
        text last_name
        timestamptz created_at
    }
    tickets {
        bigint id PK
        bigint participant_id FK "UNIQUE WHERE closed_at IS NULL"
        timestamptz opened_at
        timestamptz closed_at "NULL — открыто"
        bigint closed_by FK "users"
    }
    messages {
        bigint id PK
        bigint participant_id FK
        bigint ticket_id FK "у ответов оператора и уведомлений"
        text author "participant, bot, operator"
        bigint operator_id FK "users, у ответов оператора"
        bigint telegram_message_id "UNIQUE с participant_id у входящих"
        text content_type "text, photo, other; у фото без подписи text NULL"
        text telegram_file_id "file_id фото в Telegram; панель подгружает картинку по нему"
        text text "номера карт скрыты до записи"
        timestamptz created_at
    }
    bot_decisions {
        bigint id PK
        bigint message_id FK "входящее, UNIQUE"
        bigint reply_message_id FK "ответ бота, UNIQUE, NULL если не отправлен"
        bigint ticket_id FK "если передано оператору"
        text action "answer, operator, refuse, smalltalk"
        text reason "model, invalid_refs, llm_error, no_text, media_to_ticket, start"
        jsonb rule_refs "номера пунктов"
        text operator_summary
        text model "какая модель ответила"
        jsonb model_output "ответ модели как есть"
        text rating "оценка оператора: correct, wrong, debatable; NULL — не оценено"
        text rating_comment
        bigint rated_by FK "users"
        timestamptz rated_at
        timestamptz created_at
    }
```

Служебные таблицы Laravel из стандартных миграций: `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`, `password_reset_tokens`.

## Таблицы

- `users` — операторы панели, стандартная таблица Laravel. Первый оператор создаётся из `.env` при старте.
- `participants` — участники, написавшие боту (только личные чаты).
- `tickets` — обращения к операторам. Статус выводится из `closed_at`. Закрытое обращение не переоткрывается: если человек напишет снова и снова понадобится оператор, откроется новое.
- `messages` — вся переписка: участник, бот, оператор. Из неё строятся история для оператора и контекст для модели.
- `bot_decisions` — журнал решений бота: ровно одно решение на каждое входящее сообщение. Что сделал бот и почему, на какие пункты опирался, какая модель ответила. Здесь же оценка оператора по шкале из задания (верно / неверно / спорно) с комментарием: одна на решение, последняя побеждает. Из этого журнала считается статистика.

## Почему так

- **Одно открытое обращение** закреплено в базе частичным уникальным индексом `tickets (participant_id) WHERE closed_at IS NULL`. Новое обращение открывается через `INSERT … ON CONFLICT DO NOTHING`, поэтому даже при гонке второе не появится.
- **Бот не отвечает дважды.** Дубль от Telegram отсекает уникальный индекс `(participant_id, telegram_message_id)`; на одно входящее — одно решение (UNIQUE на `message_id`), у решения не больше одного ответа (UNIQUE на `reply_message_id`).
- **Журнал решений — единственный источник статистики.** Отдельных счётчиков нет, рассинхронизироваться нечему. Определения статистики можно менять без миграций.
- **Тексты пунктов не дублируем.** В решении хранятся только номера; панель показывает текст пункта из `promo-rules.md`. Правила в MVP не меняются.
- **Без дублирования состояния.** Нет `status`, `last_*_at` и счётчиков: «ждёт с», «время первого ответа» и статус обращения выводятся запросами.
- **Номера карт скрываются до записи.** Ни одна колонка их не содержит, сырые апдейты Telegram не хранятся.
- **Время не съезжает.** Приложение и сессия базы в UTC, колонки `timestamptz` с точностью до секунды (`timestamp(0) with time zone`, умолчание Laravel). Это закреплено тестом.
- **Фото не копируем.** В `messages.telegram_file_id` лежит только идентификатор файла в Telegram; панель скачивает картинку у Bot API в момент показа и отдаёт оператору по своему адресу. Файлов нет ни на диске, ни в базе, токен бота в браузер не попадает. Если файл в Telegram станет недоступен, оператор увидит ошибку вместо картинки.
- **Оценка оператора — в журнале решений,** а не в отдельной таблице: оценивается именно решение, оценка одна, история правок для пилота не нужна, а статистика оценок считается тем же запросом, что и остальная.
