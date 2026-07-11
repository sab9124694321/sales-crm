# SZB CRM — Текущий статус разработки

**Последнее обновление:** 2026-07-11
**Версия:** v2.1.2 (в разработке)
**Сайт:** https://szb-sales.ru

---

## ✅ Работает

| Модуль | Файл | Что работает |
|--------|------|-------------|
| Я звоню | `calls.php` | Номер задачи (конец UUID), ссылка «🔗 Открыть в Ритм», копирование с историей (3 кнопки) |
| Я звоню | `calls.php` | Исправлено поле `status` → `call_result` в JSON-запросе |
| Контроль РОП | `rop_control.php` | Ссылка на Ритм, поле комментария всегда видно, конец UUID |
| AI-проверка | `api_save_call_comment.php` | Проверка ПДН и релевантности через GigaChat AI |

---

## ⚠️ В процессе исправления

### Проблема: Сохранение звонка падает с ошибкой

**Ошибка:** `table manager_call_stats has no column named call_date`

**Причина:** В `api_save_call_comment.php` INSERT использует колонку `call_date`, которой нет в таблице `manager_call_stats`.

**Исправления в очереди (нужно запушить и задеплоить):**

1. **uuid → task_id** (строка ~210 в `api_save_call_comment.php`)
   - В таблице `epk_tasks` колонка называется `task_id`, не `uuid`
   - ✅ Исправлено в коммите `fa6a6dd`

2. **Добавлено поле `tabel` в `rop_control_queue`**
   - Таблица требует `tabel TEXT NOT NULL`
   - ✅ Исправлено в файле, ждёт push

3. **Убрана колонка `call_date` из `manager_call_stats`**
   - Заменено на `updated_at` + `ON CONFLICT(user_id)`
   - ✅ Исправлено в файле, ждёт push

---

## 🔧 Команды для деплоя

### 1. На Mac (git push)

```bash
cd ~/sales-crm-production
git add api_save_call_comment.php
git commit -m "api_save_call_comment.php: исправления uuid→task_id, tabel, manager_call_stats"
git push origin main
```

### 2. На сервере (git pull в Docker)

```bash
ssh root@5.129.248.239
docker exec -it sales-crm bash -c "cd /var/www/html && cp sales.db sales.db.BACKUP_$(date +%Y%m%d_%H%M%S) && git pull origin main && chown www-data:www-data sales.db && chmod 664 sales.db && echo '=== ГОТОВО ==='"
```

### 3. Проверка в браузере

- Открыть `https://szb-sales.ru/calls.php`
- Выбрать задачу, заполнить форму
- Нажать «Сохранить звонок»
- В консоли (Cmd+Option+J) должно быть: `{"success":true,...}`

---

## 📋 Структура базы данных (актуальная)

### `call_comments`
- `id`, `task_id`, `user_id`, `comment_text`, `call_result`, `next_call_date`, `deal_readiness`, `created_at`, `call_count`, `fraud_score`

### `epk_tasks`
- `task_id` (PRIMARY KEY), `user_tabel`, `product`, `status`, `next_call_date`, `imported_at`, `updated_at`, `call_count`, `first_status_at`, `top_status`

### `rop_control_queue`
- `id`, `task_id`, `user_id`, `tabel` (NOT NULL), `fraud_score`, `comment_text`, `status`, `rop_comment`, `rop_action`, `created_at`, `checked_at`, `top_status`

### `manager_call_stats`
- `user_id`, `total_calls`, `fraud_low_count`, `updated_at`

---

## 📝 История коммитов (последние)

| Коммит | Описание | Статус |
|--------|----------|--------|
| `ebc211a` | calls.php: исправлено поле status → call_result | ✅ На сервере |
| `fa6a6dd` | api_save_call_comment.php: исправлено uuid → task_id | ✅ На сервере |
| (следующий) | api_save_call_comment.php: tabel + manager_call_stats | ⏳ Ждёт push |

---

## 🔐 Важно

- `sales.db` в `.gitignore` — git не трогает базу
- После `git pull` проверять права: `chown www-data:www-data sales.db && chmod 664 sales.db`
- Делать бэкап базы перед деплоем: `cp sales.db sales.db.BACKUP_YYYYMMDD_HHMMSS`
