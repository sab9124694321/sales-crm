# SZB CRM v2.2 — Статус проекта

**Репозиторий:** https://github.com/sab9124694321/sales-crm  
**Сервер:** root@5.129.248.239  
**Docker:** sales-crm → /var/www/html  
**Прод:** https://szb-sales.ru  
**Дата обновления:** 2026-07-11

---

## ✅ Работает (запушено + задеплоено)

| Файл | Что работает |
|------|-------------|
| `calls.php` | Номер задачи (конец UUID), ссылка «🔗 Открыть в Ритм», копирование с историей, console.log диагностика |
| `calls.php` | Статусы: Согласен, Перезвон, Нет контакта, Думает, Недозвон, Подтверждена, На контроле РОП |
| `rop_control.php` | Ссылка на Ритм, поле комментария всегда видно, конец UUID |
| `rop_control.php` | CSV выгрузка: ID, Задача, Менеджер, Территория, Фрод-скор, Статус РОП, Статус задачи, Верхнеуровневый статус, Комментарий, РОП-комментарий, Создано, Проверено |
| `api_call_coach.php` | AI-коуч через GigaChat |
| `api_save_call_comment.php` | Сохранение звонка, локальная проверка ПДН (регулярки) |

---

## 🗄️ Структура БД (SQLite sales.db)

```sql
-- epk_tasks
CREATE TABLE epk_tasks (
    task_id TEXT PRIMARY KEY,
    user_tabel TEXT NOT NULL,
    product TEXT DEFAULT 'Торговый эквайринг',
    status TEXT DEFAULT 'Назначена',
    top_status TEXT DEFAULT 'active',
    call_count INTEGER DEFAULT 0,
    first_status_at TEXT,
    next_call_date DATETIME,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    imported_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- call_comments
CREATE TABLE call_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    call_result TEXT DEFAULT 'think',
    comment_text TEXT NOT NULL,
    fraud_score INTEGER DEFAULT 0,
    call_count INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- rop_control_queue
CREATE TABLE rop_control_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    tabel TEXT NOT NULL,
    fraud_score INTEGER DEFAULT 0,
    comment_text TEXT,
    status TEXT DEFAULT 'На проверке',
    rop_comment TEXT,
    rop_action TEXT,
    top_status TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    checked_at DATETIME
);

-- manager_call_stats
CREATE TABLE manager_call_stats (
    user_id INTEGER PRIMARY KEY,
    tabel TEXT NOT NULL,
    total_calls INTEGER DEFAULT 0,
    valid_calls INTEGER DEFAULT 0,
    fraud_flags INTEGER DEFAULT 0,
    last_call_date DATE,
    avg_comment_length INTEGER DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## 📊 Статусы звонков

| call_result | Статус в БД | top_status | Финальный? | Комментарий |
|-------------|-------------|------------|------------|-------------|
| `signed` | Согласен | `closed` | ✅ Да | Клиент согласен, задача закрыта |
| `contract` | Согласен | `closed` | ✅ Да | Объединён с signed → "Согласен" |
| `reject` | Отказ подтверждён | `rejected_confirmed` | ✅ Да | Клиент отказал |
| `noanswer` | Недозвон | `active` | ❌ Нет | Не дозвонились |
| `think` | Думает | `think` | ❌ Нет | Клиент думает |
| `recall` | Перезвон | `recall` | ❌ Нет | Назначен перезвон |
| `nocontact` | Нет контакта | `nocontact` | ❌ Нет | Не удалось связаться |

---

## 🤖 Проверка комментариев (локальная, v2.2)

GigaChat отключён (HTTP 400, токен невалиден). Используется локальная проверка через регулярные выражения:

| Проверка | Что ищем | Результат |
|----------|----------|-----------|
| Фамилия | Иванов, Петров, Смирнов и др. (50+ фамилий) | has_pdn=true, fraud_score=90 |
| Телефон | +7, 8, 9 + 9-10 цифр | has_pdn=true, fraud_score=95 |
| ИНН | 10 или 12 цифр подряд | has_pdn=true, fraud_score=95 |
| Email | текст@домен.зона | has_pdn=true, fraud_score=90 |
| Релевантность | Ключевые слова эквайринга | is_relevant=false, fraud_score=70 |
| Длина < 15 симв. | — | fraud_score=40 |
| Длина < 30 симв. | — | fraud_score=25 |
| Нормальный текст | — | fraud_score=10 |

**Порог для РОПа:** fraud_score < 40 → добавляется в очередь РОПа  
**Порог для manager_call_stats:** fraud_score < 40 → fraud_flags + 1

---

## ⚠️ Известные проблемы

| Проблема | Статус | Решение |
|----------|--------|---------|
| GigaChat HTTP 400 | ❌ Не работает | Токен невалиден, нужно получить новый в https://developers.sber.ru |
| fraud_score всегда 50 | ✅ Исправлено | Перешли на локальную проверку (регулярки) |
| `no such column: uuid` | ✅ Исправлено | uuid → task_id в SQL-запросе |
| `no such column: fraud_score` | ✅ Исправлено | ALTER TABLE call_comments ADD COLUMN |
| `NOT NULL constraint failed: tabel` | ✅ Исправлено | Добавлено поле tabel в INSERT rop_control_queue |
| `no such column: call_date` | ✅ Исправлено | Убрана call_date из manager_call_stats |
| `no such column: fraud_low_count` | ✅ Исправлено | Заменено на fraud_flags |
| Дублирование "Договор заключён" | ✅ Исправлено | Объединён с "Согласен" |

---

## 📝 Памятка для следующей сессии

Если диалог оборвался — начните новый с текстом:
> Продолжаем работу над SZB CRM. Репозиторий: https://github.com/sab9124694321/sales-crm  
> Статус в STATUS.md. Нужно [описать задачу].

Или просто: **«Продолжаем SZB CRM, вот репо: https://github.com/sab9124694321/sales-crm»** — я сам прочитаю STATUS.md.

---

## 🚀 Команды для деплоя

```bash
# На Mac:
cd ~/sales-crm-production
git add .
git commit -m "описание изменений"
git push origin main

# На сервере:
ssh root@5.129.248.239
docker exec -it sales-crm bash -c "cd /var/www/html && cp sales.db sales.db.BACKUP_$(date +%Y%m%d_%H%M%S) && git pull origin main && chown www-data:www-data sales.db && chmod 664 sales.db && echo '=== ГОТОВО ==='"
```

---

## 🔧 Полезные команды на сервере

```bash
# Проверить структуру таблицы
docker exec -it sales-crm bash -c "cd /var/www/html && sqlite3 sales.db '.schema ИМЯ_ТАБЛИЦЫ'"

# Добавить колонку
docker exec -it sales-crm bash -c "cd /var/www/html && sqlite3 sales.db 'ALTER TABLE ИМЯ ADD COLUMN колонка ТИП DEFAULT значение;'"

# Проверить синтаксис PHP
docker exec -it sales-crm bash -c "cd /var/www/html && php -l ИМЯ_ФАЙЛА.php"

# Права на базу
docker exec -it sales-crm bash -c "cd /var/www/html && chown www-data:www-data sales.db && chmod 664 sales.db"
```
