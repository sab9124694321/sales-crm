<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['hunter_id'])) {
    header('Location: hunter_login.php');
    exit;
}

$hunter_id = $_SESSION['hunter_id'];

// Мои данные
$stmt = $pdo->prepare("SELECT * FROM hunters WHERE id = ?");
$stmt->execute([$hunter_id]);
$me = $stmt->fetch();

// Мои рефералы
$stmt = $pdo->prepare("SELECT login, full_name, points, created_at FROM hunters WHERE referred_by = ? ORDER BY created_at DESC");
$stmt->execute([$hunter_id]);
$referrals = $stmt->fetchAll();

$total_referrals = count($referrals);
$total_bonus = $total_referrals * 500;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Мои друзья</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; min-height: 100vh; color: #1e293b; padding-bottom: 100px; }
        .header { background: linear-gradient(135deg, #6366f1, #8b5cf6); padding: 20px 16px; color: #fff; }
        .header h1 { font-family: 'Orbitron', sans-serif; font-size: 18px; font-weight: 700; }
        .header p { font-size: 13px; opacity: 0.9; margin-top: 4px; }
        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 16px; }
        .stat-card { background: #fff; border-radius: 16px; padding: 20px; text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .stat-icon { font-size: 28px; margin-bottom: 8px; }
        .stat-value { font-family: 'Orbitron', sans-serif; font-size: 24px; font-weight: 700; color: #4f46e5; }
        .stat-label { font-size: 12px; color: #94a3b8; margin-top: 4px; }
        .section { padding: 0 16px; }
        .section-title { font-family: 'Orbitron', sans-serif; font-size: 13px; font-weight: 700; color: #4f46e5; margin-bottom: 12px; }
        .card { background: #fff; border-radius: 16px; padding: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); margin-bottom: 12px; }
        .ref-code-box {
            background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 12px; padding: 16px;
            border: 2px dashed #fbbf24; text-align: center; margin-bottom: 16px;
        }
        .ref-code { font-family: 'Orbitron', monospace; font-size: 22px; font-weight: 700; color: #92400e; letter-spacing: 2px; }
        .ref-text { font-size: 12px; color: #a16207; margin-top: 6px; }
        .ref-item { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .ref-item:last-child { border-bottom: none; }
        .ref-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #e0e7ff, #c7d2fe); display: flex; align-items: center; justify-content: center; font-size: 16px; }
        .ref-info { flex: 1; }
        .ref-name { font-size: 14px; font-weight: 600; }
        .ref-date { font-size: 11px; color: #94a3b8; }
        .ref-xp { font-family: 'Orbitron', sans-serif; font-size: 12px; font-weight: 700; color: #22c55e; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-around; padding: 8px 0; z-index: 100; box-shadow: 0 -2px 12px rgba(0,0,0,0.04); }
        .nav-item { flex: 1; text-align: center; padding: 6px 0; text-decoration: none; color: #94a3b8; font-size: 11px; }
        .nav-item.active { color: #6366f1; }
        .nav-item .nav-icon { font-size: 22px; display: block; margin-bottom: 2px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>👥 ДРУЗЬЯ</h1>
        <p>Приглашай и зарабатывай XP</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-value"><?= $total_referrals ?></div>
            <div class="stat-label">Приглашено</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⭐</div>
            <div class="stat-value">+<?= $total_bonus ?></div>
            <div class="stat-label">Бонус XP</div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">🔗 Твой код</div>
        <div class="card">
            <div class="ref-code-box">
                <div class="ref-code"><?= htmlspecialchars($me['referral_code'] ?? '') ?></div>
                <div class="ref-text">Поделись кодом с друзьями<br>+500 XP за каждого</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">📋 Твои рефералы</div>
        <div class="card">
            <?php if (empty($referrals)): ?>
            <div style="text-align: center; padding: 20px; color: #94a3b8; font-size: 14px;">
                👥 Пока нет приглашенных<br>
                <span style="font-size: 12px;">Поделись кодом и получи бонусы!</span>
            </div>
            <?php else: ?>
            <?php foreach ($referrals as $ref): ?>
            <div class="ref-item">
                <div class="ref-avatar">🏆</div>
                <div class="ref-info">
                    <div class="ref-name"><?= htmlspecialchars($ref['login'] ?? 'Охотник') ?></div>
                    <div class="ref-date"><?= date('d.m.Y', strtotime($ref['created_at'])) ?></div>
                </div>
                <div class="ref-xp">+500 XP</div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="bottom-nav">
        <a href="hunter_dashboard.php" class="nav-item">
            <span class="nav-icon">🎯</span>Лиды
        </a>
        <a href="hunter_rating.php" class="nav-item">
            <span class="nav-icon">🏆</span>Рейтинг
        </a>
        <a href="hunter_referrals.php" class="nav-item active">
            <span class="nav-icon">👥</span>Друзья
        </a>
        <a href="hunter_profile.php" class="nav-item">
            <span class="nav-icon">👤</span>Профиль
        </a>
    </div>
</body>
</html>
