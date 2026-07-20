<?php
// Увеличиваем время жизни сессии до 24 часов (86400 секунд)
session_set_cookie_params(86400);
ini_set('session.gc_maxlifetime', 86400);
// Стартуем сессию, если ещё не запущена
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
