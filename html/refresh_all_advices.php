<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("Доступ запрещён. Только для администратора.");
}

require_once 'db.php';

// Очищаем кеш на сервере
exec("rm -f /tmp/ai_advice_*.cache");

// Получаем всех пользователей
$users = $pdo->query("SELECT id FROM users WHERE role != 'admin'")->fetchAll();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Обновление советов</title>
    <meta charset='utf-8'>
    <meta http-equiv='refresh' content='5;url=dashboard.php'>
    <style>
        body { font-family: system-ui; text-align: center; padding: 50px; background: #f5f7fb; }
        .success { color: #10b981; }
        .box { background: white; border-radius: 16px; padding: 30px; max-width: 500px; margin: 0 auto; }
    </style>
</head>
<body>
<div class='box'>
    <h2>🔄 Обновление ИИ-советов</h2>
    <p>Кеш советов очищен для <strong>" . count($users) . "</strong> сотрудников.</p>
    <p>При следующем заходе на дашборд каждый получит новый уникальный совет!</p>
    <p class='success'>✅ Готово!</p>
    <p>Через 5 секунд вы вернётесь на дашборд...</p>
</div>
</body>
</html>";
?>
