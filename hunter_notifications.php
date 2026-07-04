<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['hunter_id'])) {
    header('Location: hunter_login.php');
    exit;
}

$hunter_id = $_SESSION['hunter_id'];

// Получаем данные охотника
$stmt = $pdo->prepare("SELECT * FROM hunters WHERE id = ?");
$stmt->execute([$hunter_id]);
$hunter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hunter) {
    session_destroy();
    header('Location: hunter_login.php');
    exit;
}

// Отмечаем все уведомления как прочитанные
$pdo->prepare("UPDATE hunter_notifications SET is_read = 1 WHERE hunter_id = ? AND is_read = 0")->execute([$hunter_id]);

// Получаем все уведомления
$stmt = $pdo->prepare("SELECT * FROM hunter_notifications WHERE hunter_id = ? ORDER BY created_at DESC");
$stmt->execute([$hunter_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем количество непрочитанных (теперь 0, т.к. только отметили)
$unread_count = 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Уведомления — Охотник</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9; min-height: 100vh; color: #1e293b;
            padding-bottom: 100px;
        }
        .header {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            padding: 20px 16px; color: #fff; position: relative;
        }
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .header-title { font-family: 'Orbitron', sans-serif; font-size: 16px; font-weight: 700; }
        .back-btn { background: rgba(255,255,255,0.2); border: none; border-radius: 12px; padding: 8px 12px; color: #fff; font-size: 14px; cursor: pointer; text-decoration: none; }
        .profile-card {
            display: flex; align-items: center; gap: 14px;
        }
        .avatar {
            width: 56px; height: 56px; border-radius: 50%;
            background: linear-gradient(135deg, #fff, #e0e7ff);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; border: 3px solid rgba(255,255,255,0.3);
        }
        .profile-info { flex: 1; }
        .profile-name { font-size: 16px; font-weight: 700; }
        .profile-level { font-size: 12px; opacity: 0.9; margin-top: 2px; }

        .section { padding: 16px; }
        .section-title {
            font-family: 'Orbitron', sans-serif; font-size: 13px; font-weight: 700;
            color: #4f46e5; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;
        }
        .card {
            background: #fff; border-radius: 16px; padding: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04); margin-bottom: 12px;
        }
        .notif-item {
            padding: 14px 0; border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: flex-start; gap: 12px;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-icon {
            width: 40px; height: 40px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; flex-shrink: 0;
        }
        .notif-icon.success { background: #f0fdf4; }
        .notif-icon.info { background: #eff6ff; }
        .notif-icon.warning { background: #fefce8; }
        .notif-icon.error { background: #fef2f2; }
        .notif-content { flex: 1; }
        .notif-message { font-size: 14px; font-weight: 500; color: #1e293b; line-height: 1.5; }
        .notif-date { font-size: 11px; color: #94a3b8; margin-top: 4px; }
        .notif-badge {
            font-size: 10px; padding: 2px 8px; border-radius: 10px;
            font-weight: 600; flex-shrink: 0;
        }
        .badge-new { background: #ef4444; color: #fff; }
        .badge-read { background: #e2e8f0; color: #64748b; }

        .empty-state {
            text-align: center; padding: 40px 20px; color: #94a3b8;
        }
        .empty-icon { font-size: 48px; margin-bottom: 12px; }
        .empty-text { font-size: 14px; }

        .bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: #fff; border-top: 1px solid #e2e8f0;
            display: flex; justify-content: space-around; padding: 8px 0;
            z-index: 100; box-shadow: 0 -2px 12px rgba(0,0,0,0.04);
        }
        .nav-item {
            flex: 1; text-align: center; padding: 6px 0; text-decoration: none;
            color: #94a3b8; font-size: 11px; transition: all 0.2s;
        }
        .nav-item.active { color: #6366f1; }
        .nav-item .nav-icon { font-size: 22px; display: block; margin-bottom: 2px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-top">
            <div class="header-title">🔔 УВЕДОМЛЕНИЯ</div>
            <a href="hunter_dashboard.php" class="back-btn">← Назад</a>
        </div>
        <div class="profile-card">
            <div class="avatar">🏆</div>
            <div class="profile-info">
                <div class="profile-name"><?= htmlspecialchars($hunter['full_name'] ?? 'Охотник') ?></div>
                <div class="profile-level">⭐ Уровень <?= $hunter['hunter_level'] ?? 1 ?> · <?= $hunter['points'] ?? 0 ?> XP</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">📨 Все уведомления</div>
        <div class="card">
            <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <div class="empty-text">У вас пока нет уведомлений</div>
            </div>
            <?php else: ?>
            <?php foreach ($notifications as $notif): 
                $type = $notif['type'] ?? 'info';
                $icon = match($type) {
                    'success' => '✅',
                    'warning' => '⚠️',
                    'error' => '❌',
                    default => 'ℹ️'
                };
                $is_read = $notif['is_read'] ?? 1;
            ?>
            <div class="notif-item">
                <div class="notif-icon <?= $type ?>"><?= $icon ?></div>
                <div class="notif-content">
                    <div class="notif-message"><?= htmlspecialchars($notif['message']) ?></div>
                    <div class="notif-date"><?= date('d.m.Y H:i', strtotime($notif['created_at'])) ?></div>
                </div>
                <?php if (!$is_read): ?>
                <span class="notif-badge badge-new">NEW</span>
                <?php else: ?>
                <span class="notif-badge badge-read">Прочитано</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="bottom-nav">
        <a href="hunter_dashboard.php" class="nav-item">
            <span class="nav-icon">🎯</span>
            Лиды
        </a>
        <a href="hunter_rating.php" class="nav-item">
            <span class="nav-icon">🏆</span>
            Рейтинг
        </a>
        <a href="hunter_shop.php" class="nav-item">
            <span class="nav-icon">🎁</span>
            Магазин
        </a>
        <a href="hunter_referrals.php" class="nav-item">
            <span class="nav-icon">👥</span>
            Друзья
        </a>
        <a href="hunter_profile.php" class="nav-item">
            <span class="nav-icon">👤</span>
            Профиль
        </a>
    </div>
</body>
</html>
