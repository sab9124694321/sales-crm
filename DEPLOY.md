# SZB CRM — Инструкция по деплою

## Предварительные требования

- Mac с установленным Git
- Доступ к серверу root@5.129.248.239 (пароль есть)
- Docker на сервере (контейнер sales-crm)

## Структура работы

```
Mac (~/sales-crm-production)          Сервер (Docker sales-crm)
        │                                        │
        │  1. git push origin main               │
        │───────────────────────────────────────▶│
        │                                        │
        │  2. ssh root@5.129.248.239            │
        │  3. docker exec -it sales-crm bash    │
        │  4. cd /var/www/html && git pull      │
        │                                        │
        │  5. Проверить права на sales.db       │
        │  6. Проверить в браузере              │
```

## Шаг 1 — Подготовка на Mac

```bash
cd ~/sales-crm-production

# Проверить статус
git status

# Добавить изменённые файлы
git add .

# Закоммитить
git commit -m "описание изменений"

# Запушить на GitHub
git push origin main
```

## Шаг 2 — Деплой на сервере

```bash
# Подключиться к серверу
ssh root@5.129.248.239

# Внутри Docker сделать git pull
docker exec -it sales-crm bash -c "cd /var/www/html && cp sales.db sales.db.BACKUP_$(date +%Y%m%d_%H%M%S) && git pull origin main && chown www-data:www-data sales.db && chmod 664 sales.db && echo '=== ГОТОВО ==='"
```

**Что делает команда:**
1. `cp sales.db sales.db.BACKUP_...` — бэкап базы данных
2. `git pull origin main` — скачивание нового кода
3. `chown www-data:www-data sales.db` — владелец базы = веб-сервер
4. `chmod 664 sales.db` — права на чтение/запись

## Шаг 3 — Проверка

```bash
# Проверить синтаксис PHP
docker exec -it sales-crm bash -c "cd /var/www/html && php -l calls.php && php -l api_save_call_comment.php && php -l rop_control.php"

# Проверить структуру таблицы
docker exec -it sales-crm bash -c "cd /var/www/html && sqlite3 sales.db '.schema call_comments'"

# Проверить права на базу
docker exec -it sales-crm bash -c "cd /var/www/html && ls -la sales.db"
```

## Шаг 4 — Проверка в браузере

1. Открыть https://szb-sales.ru/calls.php
2. Выбрать задачу, заполнить форму
3. Нажать «Сохранить звонок»
4. Открыть консоль (Cmd+Option+J)
5. Проверить ответ сервера: `{"success":true,...}`

## Частые проблемы

### Git pull не работает (локальные изменения на сервере)

```bash
# Сбросить локальные изменения
docker exec -it sales-crm bash -c "cd /var/www/html && git checkout -- ИМЯ_ФАЙЛА.php && git pull origin main"

# Или сбросить ВСЕ локальные изменения
docker exec -it sales-crm bash -c "cd /var/www/html && git reset --hard && git pull origin main"
```

⚠️ **ВНИМАНИЕ:** `git reset --hard` удалит ВСЕ локальные изменения, включая sales.db (но sales.db в .gitignore, так что база не пострадает).

### Ошибка "no such column"

```bash
# Добавить колонку в таблицу
docker exec -it sales-crm bash -c "cd /var/www/html && sqlite3 sales.db 'ALTER TABLE call_comments ADD COLUMN fraud_score INTEGER DEFAULT 0;'"
```

### Ошибка "NOT NULL constraint failed"

Проверить, что все обязательные поля заполнены в INSERT-запросе.

### Ошибка прав доступа

```bash
# Исправить права
docker exec -it sales-crm bash -c "cd /var/www/html && chown www-data:www-data sales.db && chmod 664 sales.db"
```

## Безопасность

- `sales.db` в `.gitignore` — база не попадает в Git
- `config.php` в `.gitignore` — секреты (токены) не попадают в Git
- После `git pull` всегда проверять права на `sales.db`
- Делать бэкап `sales.db` перед каждым деплоем
