# Инфраструктура сервера — контекст для деплоя

> Этот файл — для будущих сессий (AI и людей), чтобы не пересказывать одно и то же.
> Обновляется по мере изменения инфраструктуры.

## Сервер

- **IP:** 5.129.248.239
- **ОС:** Ubuntu 24.04.4 LTS (kernel 6.8)
- **Доступ:** `ssh root@5.129.248.239`
- **Домен:** szb-sales.ru (+ www)
- **SSL:** настроен на Nginx (https://), не в контейнере

## Как устроен сайт

Сайт работает в Docker-контейнере `sales-crm` на образе `php:8.2-apache`:

- Порт на хосте: `8080` → внутри контейнера `80`
- Веб-корень контейнера: `/var/www/html`
- PHP-расширения в контейнере (проверено): `curl, pdo_sqlite, sqlite3, PDO, json, openssl, mbstring, gd, xml, zip, sodium, PDO` — всё, что нужно для модулей с SQLite и внешними API, уже есть

### Bind-mount

    Хост:       /root/sales-crm/html
    Контейнер:  /var/www/html

Всё, что появляется в `/root/sales-crm/html/` на хосте, **мгновенно** становится доступно Apache внутри контейнера. Перезапуск контейнера не нужен.

### Nginx

`/etc/nginx/sites-enabled/sales-crm`:

- `listen 80` → редирект на https
- `listen 443 ssl http2`, `server_name szb-sales.ru www.szb-sales.ru`
- `proxy_pass http://127.0.0.1:8080` — все запросы уходят в контейнер

То есть: `https://szb-sales.ru/any-path` → Nginx → `http://127.0.0.1:8080/any-path` → Apache в контейнере → файл `/var/www/html/any-path`.

## Две независимые git-папки на сервере

**КЛЮЧЕВОЕ:** на сервере два git-репозитория, оба смотрят на `github.com/sab9124694321/sales-crm.git`, ветка `main`:

| Путь на сервере | Что отслеживает | Связь с сайтом |
|---|---|---|
| `/root/sales-crm/.git` | Корень (admin.php, calls.php, PHPMailer и т.д.) | **НЕ связан** с веб-корнем, просто склад |
| `/root/sales-crm/html/.git` | Всё внутри `html/` | **Это** и есть `/var/www/html` |

**Для деплоя модулей веб-интерфейса** (включая `contacts-finder/`) используется **`/root/sales-crm/html/.git`**.

Обновлять надо **ту папку, в которой живёт файл**. Если меняли файл в корне `sales-crm/` — `git pull` в корне. Если в `html/` — `git pull` в `html/`.

## Локальная структура (Mac)

    /Users/sabezrukikh/sales-crm-test/         ← песочница, локальный php -S
    /Users/sabezrukikh/sales-crm-production/   ← зеркало серверной /root/sales-crm/html/

**`sales-crm-production` на Mac = `/root/sales-crm/html` на сервере.** Обе на ветке `main`, обе отслеживают один и тот же удалённый репозиторий.

Рабочий цикл деплоя:

1. Сделали в `sales-crm-test`, проверили через `php -S localhost:8000`.
2. Скопировали в `sales-crm-production` через `rsync` (с `--exclude='config.php'` и `--exclude='*.db'`).
3. `git add / commit / push` из `sales-crm-production`.
4. На сервере в `/root/sales-crm/html` → `git pull`.
5. Файлы сразу видны в контейнере (bind-mount).
6. Если у модуля есть `config.php` — создать его на сервере вручную (из git он не придёт).
7. `chown -R www-data:www-data <модуль>/` — чтобы Apache мог писать.

## Секреты

**`config.php` — НЕ в git.** Правило `config.php` в `html/.gitignore` работает рекурсивно для всех подпапок, включая будущие модули.

Секреты хранятся только:

- Локально: `/Users/sabezrukikh/sales-crm-production/config.php` (корневой) и `<модуль>/config.php`
- На сервере: `/root/sales-crm/html/config.php` (корневой) и `<модуль>/config.php`

**При деплое нового модуля с секретами:**

1. Локально создать `config.php` в тестовой папке (`sales-crm-test/<модуль>/config.php`).
2. В `sales-crm-production` **не копировать** (rsync с `--exclude='config.php'`).
3. После `git pull` на сервере — **создать вручную** через `cat > config.php << 'EOF' ... EOF`.
4. `chown -R www-data:www-data <модуль>/`.

## .gitignore на сервере (что уже защищено)

`/root/sales-crm/html/.gitignore`:

    config.php
    mailer.php
    bot_settings.php
    sales.db
    *.db
    *.log
    ocr_debug.log
    __pycache__/
    *.pyc
    .DS_Store
    ._*
    *.backup
    *.tar.gz
    backups/
    sales.db.*
    vendor/
    composer.lock

Правила без слэшей действуют рекурсивно на все подпапки. То есть `contacts-finder/config.php` и `contacts-finder/contacts.db` защищены автоматически корневым `.gitignore`.

## Права на файлы на сервере

- После `git pull` файлы принадлежат `root:root`
- Apache в контейнере работает под `www-data` (uid 33 внутри контейнера)
- После `git pull` нового модуля **обязательно**:
      chown -R www-data:www-data <модуль>/
  Иначе модуль не сможет создавать SQLite-базу, логи, кеш токенов и упадёт с ошибкой «unable to open database file».

**Никогда не `chmod 777`.** Только `664` на файлы, `775` на папку, `chown www-data:www-data`.

## Бэкапы (страховка)

Папки на сервере:

    /root/backups/deploy_YYYYMMDD_HHMMSS/sales-crm/
    /root/backups/pre_deploy_YYYYMMDD_HHMMSS/sales-crm/
    /root/backup_before_migration_YYYYMMDD_HHMMSS/sales-crm/

Плюс в `html/`:

    sales.db.BACKUP_YYYYMMDD_HHMMSS   ← бэкапы боевой базы

Перед крупным деплоем имеет смысл сделать свежий бэкап:

    cp -a /root/sales-crm/html /root/backups/pre_deploy_$(date +%Y%m%d_%H%M%S)/html

## Что НЕ трогать на сервере

- `/root/sales-crm/html/config.php` — ключи основной CRM (GigaChat, БД)
- `/root/sales-crm/html/sales.db` — боевая база
- `/root/sales-crm/html/vendor/` — composer-зависимости
- `/root/sales-crm/` (корень, не `html/`) — склад, но не рабочий веб-корень; изменения там на сайте не видны
- `/etc/nginx/sites-enabled/sales-crm` — конфиг Nginx (менять только с бэкапом)
- Контейнер `sales-crm` — работает 5+ недель, **не перезапускать без причины**

## Известные грабли

1. **Два `.git` в `/root/sales-crm`** — самый частый источник путаницы. Для веб-модулей деплой всегда в `html/`, не в корень.
2. **`git pull` в `/root/sales-crm` (без `html/`)** — обновит корневой репо, но сайт это не увидит. Перед `git pull` проверяйте `pwd`: должно быть `.../html`.
3. **Untracked `composer.json` на сервере в `html/`** — есть, не наш, не трогать. В `git status` видно, в коммит не включать.
4. **404 после деплоя** — если файл на хосте есть, а на сайте 404: скорее всего положили не в `html/`, а в корень `sales-crm/`. Контейнер смонтирован только на `html/`.
5. **Ошибка `SQLSTATE[HY000] [14] unable to open database file`** — не хватает прав на запись в папку модуля. Лечится `chown -R www-data:www-data <модуль>/`.
6. **GigaChat OAuth HTTP 400** — если токен не выдаётся, это проблема ключа GigaChat, а не модуля. Модуль должен gracefully деградировать (продолжать работу без AI).

## Ссылки на существующую документацию

- `DEPLOY.md` — процесс деплоя (в репозитории)
- `ARCHITECTURE.md` — архитектура приложения
- `DECISIONS.md` — журнал решений
- `STATUS.md` — текущий статус
- `CONTEXT.md` — доменный контекст (бизнес, роли)

Этот файл (`SERVER_INFRA.md`) — только про **физическую инфраструктуру** (сервер, контейнер, репозитории, деплой). Остальное — в существующих файлах.

---

_Последнее обновление: 2026-10-17_
