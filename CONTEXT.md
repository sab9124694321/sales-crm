# SZB CRM — Полный контекст проекта (v2.2.1)

> **Для нейросети:** Этот файл содержит ВЕСЬ контекст проекта. При обрыве диалога — начинайте с чтения этого файла.

---

## 1. О проекте

CRM-система для отдела продаж эквайринга Сбербанка.
- **Стек:** PHP 7.4+ + SQLite + GigaChat AI (Сбер) + Docker + Nginx
- **Хостинг:** VPS szb-sales.ru, Docker контейнер `sales-crm`
- **Репозиторий:** https://github.com/sab9124694321/sales-crm
- **Прод:** https://szb-sales.ru

### Ограничения безопасности (критично!)
| Ограничение | Почему | Как обходим |
|-------------|--------|-------------|
| Нельзя хранить данные клиентов на внешних серверах | Запрет безопасности Сбера | Используем почту, планируем интеграции |
| Нет доступа к микрофону с рабочего ноутбука | Политика безопасности | Анализ голоса — только на Android |
| Не работает кнопка отправки почты на корпоративных iPad | Техническое ограничение | Ищем обходные пути |
| Telegram заблокирован в России | Регуляторное ограничение | Планировали автоотчёты в Макс и на почту |

### Технические детали сервера
| Параметр | Значение |
|----------|----------|
| Сервер | `root@5.129.248.239` |
| Docker контейнер | `sales-crm` |
| Папка проекта | `/var/www/html/` |
| База данных | `/var/www/html/sales.db` |
| Пользователь PHP | `www-data` |
| Права на БД | `664` (владелец `www-data`) |
| Важно | После операций от `root` — **всегда проверять права sales.db!** |

---

## 2. Технологии и архитектурные решения

| Компонент | Выбор | Почему | Статус |
|-----------|-------|--------|--------|
| AI | **GigaChat (Сбер)** | Бесплатные токены + российское происхождение. **Другие модели использовать НЕЛЬЗЯ** | ⚠️ HTTP 400 (токен невалиден), используем локальную проверку |
| База данных | SQLite | Простота, не требует отдельного сервера | Работает |
| Хостинг | Docker на VPS | Изоляция, контроль | Работает |
| Почта | PHPMailer | Отправка уведомлений | Работает |

### ⚠️ ВАЖНО: AI-модель
- **Разрешено:** Только GigaChat (Сбер)
- **Запрещено:** ChatGPT, Claude, YandexGPT и другие иностранные/платные модели
- **Причина:** Политика безопасности Сбера — только российское ПО
- **Текущий статус:** Токен GigaChat невалиден (HTTP 400), используется локальная проверка через регулярные выражения

---

## 3. Роли пользователей

| Роль | Код | Кто это | Кого контролирует |
|------|-----|---------|-------------------|
| Менеджер УБР | `manager` | Менеджер по продажам | — |
| Менеджер УБР (уменьш. план) | `ubr_middle` | Менеджер с частично уменьшенным планом | — |
| Начальник УБР | `head` | Начальник Отдела продаж УБР (РОП) | manager, ubr_middle |
| Менеджер ММБ | `mmb_manager` | Менеджер по продажам ММБ | — |
| Начальник ММБ | `mmb_tp_head` | Начальник ММБ | mmb_manager |
| Начальник управления | `territory_head` | Начальник управления безналичных решений | head, mmb_tp_head |
| Термен | `terman` | Терминальный менеджер | Все территории |
| Админ | `admin` | Администратор | Всё |

### Иерархия
```
Термен (terman)
    └── Начальник управления (territory_head)
            ├── Начальник Отдела продаж УБР (head)
            │       └── Менеджер (manager / ubr_middle)
            └── Начальник ММБ (mmb_tp_head)
                    └── Менеджер ММБ (mmb_manager)
```

Связь в БД: `users.manager_id → users.id`

---

## 4. Структура файлов проекта

### Ядро
- `config.php` — конфигурация (в .gitignore, секреты: GIGACHAT_AUTH, почта, БД)
- `db.php` — подключение к SQLite
- `login.php` — авторизация
- `logout.php` — выход
- `nav.php` — навигация
- `header.php` — шапка
- `style.css` — стили

### Дашборды
- `dashboard.php` — главный дашборд менеджера
- `team.php` — команда (для руководителей)
- `ubr_dashboard.php` — дашборд УБР
- `ubr_stats_dashboard.php` — статистика УБР
- `mmb_dashboard.php` — дашборд ММБ
- `mmb_head_dashboard.php` — дашборд начальника ММБ
- `ai_dashboard.php` — AI-дашборд
- `hunter_dashboard.php` — дашборд охотников

### Модуль "Я звоню" v2.2
- `calls.php` — основная страница телефонии
- `api_save_call_comment.php` — API сохранения звонка + проверка ПДН
- `api_call_coach.php` — AI-коуч через GigaChat
- `api_call_history.php` — история звонков
- `api_rop_action.php` — действия РОПа
- `rop_control.php` — модуль контроля для руководителей
- `call_schema.sql` — схема таблиц модуля

### AI-модули
- `ai.php` — основной AI-модуль
- `ai_dashboard.php` — AI-дашборд
- `ai_classify.php` — классификация
- `api_ai_ask.php` — API вопросов к AI
- `api_ai_analytics.php` — AI-аналитика
- `generate_ai_advices.php` — генерация советов
- `api_meeting_summary.php` — саммари встреч
- `ocr_gigachat.php` — OCR через GigaChat
- `ocr_hybrid.php` / `ocr_hybrid_v2.php` — гибридный OCR
- `ocr_upload_form.php` — форма загрузки для OCR
- `ocr_parser.py` — Python-парсер OCR

### Охотники (внешние агенты)
- `hunter_*.php` — все файлы для работы с охотниками
- `hunter_login.php`, `hunter_register.php`, `hunter_dashboard.php`
- `hunter_leads.php`, `hunter_shop.php`, `hunter_profile.php`
- `hunter_rating.php`, `hunter_leaderboard.php`, `hunter_referrals.php`
- `hunter_notifications.php`, `hunter_submit.php`, `hunter_migrate.php`

### API
- `api_add_tasks.php` — добавление задач
- `api_add_inn.php` — добавление ИНН
- `api_upload_tasks.php` — загрузка задач
- `api_clear_tasks.php` — очистка задач
- `api_lead_status.php` — статусы лидов
- `api_take_lead.php` — взятие лида
- `api_update_lead.php` — обновление лида
- `api_upload_file_analysis.php` — анализ файла
- `api_save_forecast.php` — сохранение прогноза
- `api_mark_all_read.php` — отметить прочитанным
- `api_mark_book_read.php` — отметить книгу прочитанной
- `api_increment_ai_calls.php` — инкремент AI-вызовов

### Админка
- `admin.php` — панель администратора
- `admin_shop.php` — магазин админа
- `territories.php` — управление территориями
- `support_settings.php` — настройки поддержки
- `migrate_db.php` — миграция БД
- `check_sla_violations.php` — проверка SLA

### Прочее
- `employee_meeting.php` — встречи сотрудников
- `auto_reports.php` — автоотчёты
- `save_report.php` — сохранение отчёта
- `team_report.php` — отчёт по команде
- `export_inn.php` — экспорт ИНН
- `leads.php` — лиды
- `quests.php` — квесты
- `terms.php` — условия
- `notifications.php` — уведомления
- `reset_password.php` — сброс пароля
- `ticket_view.php` — просмотр тикета
- `test.php`, `test_server.php`, `test_ocr.php` — тестовые

---

## 5. База данных (SQLite sales.db)

### Таблицы модуля "Я звоню"

```sql
-- epk_tasks — задачи из Ритм
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

-- call_comments — комментарии к звонкам
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

-- rop_control_queue — очередь на проверку РОПу
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

-- manager_call_stats — статистика менеджеров
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

### Другие таблицы (основные)
- `users` — сотрудники (id, full_name, tabel_number, role, manager_id, territory_id, is_active)
- `territories` — территории (id, name)
- `plans` — планы звонков (tabel_number, period, calls_plan)
- `daily_reports` — ежедневные отчёты (user_id, report_date, calls)
- `leads` — лиды
- `hunter_leads` — лиды от охотников
- `hunter_referrals` — рефералы охотников
- `hunter_achievements` — достижения охотников
- `notifications` — уведомления
- `books` — книги (для квестов)
- `book_reads` — прочитанные книги
- `employee_meetings` — встречи сотрудников

---

## 6. Статусы звонков (v2.2)

| call_result | Статус в БД | top_status | Финальный? | Комментарий |
|-------------|-------------|------------|------------|-------------|
| `signed` | **Согласен** | `closed` | ✅ Да | Клиент согласен, задача закрыта |
| `contract` | **Согласен** | `closed` | ✅ Да | Объединён с signed → "Согласен" |
| `reject` | **Отказ подтверждён** | `rejected_confirmed` | ✅ Да | Клиент отказал |
| `noanswer` | **Недозвон** | `active` | ❌ Нет | Не дозвонились |
| `think` | **Думает** | `think` | ❌ Нет | Клиент думает |
| `recall` | **Перезвон** | `recall` | ❌ Нет | Назначен перезвон |
| `nocontact` | **Нет контакта** | `nocontact` | ❌ Нет | Не удалось связаться |

### CSS-классы статусов
```css
.status-confirmed { background:#e6f4ea; color:#188038; }
.status-rop { background:#fce8e6; color:#c5221f; }
.status-think { background:#fef3e8; color:#b06000; }
.status-noanswer { background:#f3e8fd; color:#9334e6; }
.status-signed { background:#e6f4ea; color:#188038; }
.status-recall { background:#e8f0fe; color:#1a73e8; }
.status-nocontact { background:#fce8e6; color:#c5221f; }
```

---

## 7. Проверка комментариев (локальная, v2.2)

GigaChat отключён (HTTP 400, токен невалиден). Используется локальная проверка:

| Проверка | Что ищем | fraud_score |
|----------|----------|-------------|
| Фамилия | 50+ типичных русских фамилий | 90 |
| Телефон | +7, 8, 9 + 9-10 цифр | 95 |
| ИНН | 10 или 12 цифр подряд | 95 |
| Email | текст@домен.зона | 90 |
| Нерелевантность | Нет ключевых слов эквайринга | 70 |
| Длина < 15 симв. | — | 40 |
| Длина < 30 симв. | — | 25 |
| Нормальный текст | — | 10 |

**Порог для РОПа:** fraud_score < 40 → добавляется в очередь  
**Порог для статистики:** fraud_score < 40 → fraud_flags + 1

---

## 8. CSV Выгрузка (rop_control.php)

| Роль | Фильтр на странице | CSV выгружает |
|------|---------------------|---------------|
| Начальник | На проверке | Только "На проверке" своей команды |
| Начальник | Все | Все задачи своей команды |
| Термен | На проверке | Только "На проверке" всех территорий |
| Термен | Все | Все задачи всех территорий |
| Админ | Любой | Все задачи всех территорий |

**Поля CSV:** ID, Задача, Менеджер, Территория, Фрод-скор, Статус РОП, Статус задачи, Верхнеуровневый статус, Комментарий, Комментарий РОП, Дата создания, Дата проверки

---

## 9. История изменений (ключевые)

### v2.2.1 (2026-07-11)
- CSV выгрузка: корректная фильтрация по статусу страницы

### v2.2 (2026-07-11)
- Локальная проверка ПДН (регулярки) вместо GigaChat
- Новые статусы: Перезвон, Нет контакта
- Поле "Статус задачи" в CSV
- Выгрузка CSV для термена
- "Договор заключён" → "Согласен"
- Порог fraud_score: 60 → 40

### v2.1 (2026-07-09)
- Модуль "Контроль" (rop_control.php)
- AI-проверка через GigaChat
- Очередь на проверку РОПу
- Статистика менеджеров
- CSV выгрузка
- Ссылка на Ритм
- Конец UUID в отображении
- Копирование с историей

### v2.0 (2026-07-04)
- Модуль "Я звоню"
- План звонков, таймер, AI-помощник
- Журнал звонков, разбор сеанса
- API сохранения, AI-коуч, действия РОПа

### Ранее
- SQLite вместо PostgreSQL
- Docker на VPS
- Удаление токенов из GitHub
- Защита sales.db от git reset --hard

---

## 10. Термины

### Git
- Репозиторий — папка с кодом, которую отслеживает git
- Commit — снимок файлов
- Push — отправить код на GitHub
- Pull — скачать код с GitHub
- Branch — ветка (основная — main)
- .gitignore — что git не отслеживает

### Сервер
- VPS — виртуальный сервер
- Docker — контейнер, изолированная среда
- Cron — планировщик задач
- Nginx — веб-сервер

### База данных
- SQLite — файл-база данных
- Таблица — как Excel-лист
- SQL — язык запросов

### AI
- GigaChat — нейросеть от Сбера (единственная разрешённая!)
- Токен — ключ доступа к API
- OCR — распознавание текста
- API — интерфейс для связи программ

### Бизнес
- Эквайринг — приём платежей по картам
- ТЭ — торговый эквайринг
- ТСТ — торговая точка
- ИНН — идентификационный номер
- Лид — потенциальный клиент
- Охотник — внешний агент, приводящий лиды
- SLA — соглашение об уровне обслуживания
- Ритм — платформа для задач (бывш. Тортуга)
- Макс — российский мессенджер
- УБР — управление безналичных решений
- ММБ — малое и микро бизнес

### Модуль "Я звоню"
- Фрод-скор — оценка достоверности звонка (0-100)
- РОП-контроль — проверка руководителем
- Smart-форма — 5 обязательных полей
- top_status — верхнеуровневый статус задачи
- call_count — количество звонков по задаче

---

## 11. Команды для деплоя

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

### Полезные команды на сервере
```bash
# Проверить структуру таблицы
docker exec -it sales-crm bash -c "cd /var/www/html && sqlite3 sales.db '.schema ИМЯ_ТАБЛИЦЫ'"

# Добавить колонку
docker exec -it sales-crm bash -c "cd /var/www/html && sqlite3 sales.db 'ALTER TABLE ИМЯ ADD COLUMN колонка ТИП DEFAULT значение;'"

# Проверить синтаксис PHP
docker exec -it sales-crm bash -c "cd /var/www/html && php -l ИМЯ_ФАЙЛА.php"

# Права на базу
docker exec -it sales-crm bash -c "cd /var/www/html && chown www-data:www-data sales.db && chmod 664 sales.db"

# Сбросить локальные изменения (если git pull не работает)
docker exec -it sales-crm bash -c "cd /var/www/html && git checkout -- ИМЯ_ФАЙЛА.php && git pull origin main"
```

---

## 12. Памятка для следующей сессии

Если диалог оборвался — начните новый с текстом:
> Продолжаем работу над SZB CRM. Репозиторий: https://github.com/sab9124694321/sales-crm  
> Полный контекст в CONTEXT.md. Нужно [описать задачу].

Или просто: **«Продолжаем SZB CRM, вот репо: https://github.com/sab9124694321/sales-crm»** — я сам прочитаю CONTEXT.md и пойму весь контекст.

---

*Последнее обновление: 2026-07-11 (v2.2.1)*
