<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['hunter_id'])) {
    header('Location: hunter_login.php');
    exit;
}

$hunter_id = $_SESSION['hunter_id'];

// Топ охотников
$stmt = $pdo->query("SELECT id, login, full_name, points, hunter_level FROM hunters WHERE is_active = 1 ORDER BY points DESC LIMIT 50");
$hunters = $stmt->fetchAll();

// Моя позиция
$my_rank = 0;
foreach ($hunters as $i => $h) {
    if ($h['id'] == $hunter_id) {
        $my_rank = $i + 1;
        break;
    }
}

// Мои данные
$stmt = $pdo->prepare("SELECT * FROM hunters WHERE id = ?");
$stmt->execute([$hunter_id]);
$me = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Рейтинг Охотников</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; min-height: 100vh; color: #1e293b; padding-bottom: 100px; }
        .header {
            background: linear-gradient(135deg, #6366f1, #8b5cf6); padding: 20px 16px; color: #fff;
        }
        .header h1 { font-family: 'Orbitron', sans-serif; font-size: 18px; font-weight: 700; }
        .header p { font-size: 13px; opacity: 0.9; margin-top: 4px; }
        .my-card {
            background: #fff; margin: -20px 16px 16px; border-radius: 16px; padding: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04); display: flex; align-items: center; gap: 14px;
            position: relative; z-index: 2;
        }
        .my-rank {
            width: 44px; height: 44px; border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Orbitron', sans-serif; font-size: 14px; font-weight: 700;
        }
        .my-info { flex: 1; }
        .my-name { font-size: 15px; font-weight: 700; }
        .my-level { font-size: 12px; color: #64748b; margin-top: 2px; }
        .my-xp { font-family: 'Orbitron', sans-serif; font-size: 16px; font-weight: 700; color: #6366f1; }
        .section { padding: 0 16px; }
        .section-title { font-family: 'Orbitron', sans-serif; font-size: 13px; font-weight: 700; color: #4f46e5; margin-bottom: 12px; }
        .card { background: #fff; border-radius: 16px; padding: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); margin-bottom: 12px; }
        .top-item { display: flex; align-items: center; gap: 10px; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .top-item:last-child { border-bottom: none; }
        .top-rank { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }
        .rank-1 { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; }
        .rank-2 { background: linear-gradient(135deg, #94a3b8, #64748b); color: #fff; }
        .rank-3 { background: linear-gradient(135deg, #b45309, #92400e); color: #fff; }
        .rank-other { background: #f1f5f9; color: #64748b; }
        .top-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #e0e7ff, #c7d2fe); display: flex; align-items: center; justify-content: center; font-size: 16px; }
        .top-info { flex: 1; }
        .top-name { font-size: 14px; font-weight: 600; }
        .top-level { font-size: 11px; color: #94a3b8; }
        .top-xp { font-family: 'Orbitron', sans-serif; font-size: 13px; font-weight: 700; color: #6366f1; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-around; padding: 8px 0; z-index: 100; box-shadow: 0 -2px 12px rgba(0,0,0,0.04); }
        .nav-item { flex: 1; text-align: center; padding: 6px 0; text-decoration: none; color: #94a3b8; font-size: 11px; }
        .nav-item.active { color: #6366f1; }
        .nav-item .nav-icon { font-size: 22px; display: block; margin-bottom: 2px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🏆 РЕЙТИНГ</h1>
        <p>Топ-50 лучших охотников</p>
    </div>

    <div class="my-card">
        <div class="my-rank"><?= $my_rank ?: '?' ?></div>
        <div class="my-info">
            <div class="my-name"><?= htmlspecialchars($me['login'] ?? 'Вы') ?></div>
            <div class="my-level">⭐ Уровень <?= $me['hunter_level'] ?? 1 ?></div>
        </div>
        <div class="my-xp"><?= $me['points'] ?? 0 ?> XP</div>
    </div>

    <div class="section">
        <div class="section-title">🏆 Топ Охотников</div>
        <div class="card">
            <?php foreach ($hunters as $i => $h): ?>
            <?php $rank = $i + 1; $rank_class = $rank <= 3 ? 'rank-' . $rank : 'rank-other'; ?>
            <div class="top-item">
                <div class="top-rank <?= $rank_class ?>"><?= $rank ?></div>
                <div class="top-avatar">🏆</div>
                <div class="top-info">
                    <div class="top-name"><?= htmlspecialchars($h['login'] ?? 'Охотник') ?></div>
                    <div class="top-level">Ур. <?= $h['hunter_level'] ?? 1 ?> · <?= htmlspecialchars($h['full_name'] ?? '') ?></div>
                </div>
                <div class="top-xp"><?= $h['points'] ?? 0 ?> XP</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bottom-nav">
        <a href="hunter_dashboard.php" class="nav-item">
            <span class="nav-icon">🎯</span>Лиды
        </a>
        <a href="hunter_rating.php" class="nav-item active">
            <span class="nav-icon">🏆</span>Рейтинг
        </a>
        <a href="hunter_referrals.php" class="nav-item">
            <span class="nav-icon">👥</span>Друзья
        </a>
        <a href="hunter_profile.php" class="nav-item">
            <span class="nav-icon">👤</span>Профиль
        </a>
    </div>
</body>
</html>
