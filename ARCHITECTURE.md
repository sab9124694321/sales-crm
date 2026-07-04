# Архитектура SZB CRM

## Стек
- PHP 7.4+
- SQLite
- GigaChat AI (OAuth, API)
- Docker + Nginx
- PHPMailer (для почты)

## Структура файлов
- Ядро: config.php, db.php, login.php
- Дашборды: dashboard.php, team.php, ubr_dashboard.php, mmb_dashboard.php, mmb_head_dashboard.php, ai_dashboard.php, hunter_dashboard.php
- Модуль "Я звоню": calls.php, api_call_coach.php, api_save_call_comment.php, api_call_history.php, api_add_tasks.php, api_clear_tasks.php, api_upload_tasks.php, call_schema.sql
- AI-модули: ai.php, ai_dashboard.php, ai_classify.php, api_ai_ask.php, api_ai_analytics.php, generate_ai_advices.php, api_meeting_summary.php
- Охотники: hunter_*.php
- API: api_*.php, ocr_*.php
- Админка: admin.php, admin_shop.php

## База данных (основные таблицы)
- users — сотрудники
- daily_reports — ежедневные отчёты
- plans — планы на месяц
- daily_forecasts — прогнозы
- leads — лиды
- hunters — охотники
- tickets — тикеты поддержки
- epk_tasks — задачи из Ритм (новое!)
- call_comments — комментарии к звонкам (новое!)
- ai_advice_cache / ai_book_cache — кэш AI

## Интеграции
- GigaChat (Сбер) — AI, OCR
- Ритм (бывш. Тортуга) — задачи для звонков
- Макс — корп. мессенджер (тестовое сообщение ушло)
- PHPMailer — почта (есть проблемы на iPad)
