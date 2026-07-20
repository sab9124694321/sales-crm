<?php
// Устанавливаем параметры сессии (24 часа) без её запуска
// Запуск сессии будет выполнен в основном скрипте (например, session_start())
session_set_cookie_params(86400);
ini_set('session.gc_maxlifetime', 86400);
// НЕ вызываем session_start() здесь, чтобы избежать ошибки "already active"