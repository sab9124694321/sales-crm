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

// Статистика
$total_leads = $pdo->prepare("SELECT COUNT(*) FROM hunter_leads WHERE hunter_id = ?");
$total_leads->execute([$hunter_id]);
$total_leads = $total_leads->fetchColumn();

$approved_leads = $pdo->prepare("SELECT COUNT(*) FROM hunter_leads WHERE hunter_id = ? AND status = 'approved'");
$approved_leads->execute([$hunter_id]);
$approved_leads = $approved_leads->fetchColumn();

$total_bonus = $pdo->prepare("SELECT COALESCE(SUM(bonus_points), 0) FROM hunter_leads WHERE hunter_id = ? AND status = 'approved'");
$total_bonus->execute([$hunter_id]);
$total_bonus = $total_bonus->fetchColumn();

$converted_bonus = $pdo->prepare("SELECT COALESCE(SUM(converted_bonus), 0) FROM hunter_leads WHERE hunter_id = ?");
$converted_bonus->execute([$hunter_id]);
$converted_bonus = $converted_bonus->fetchColumn();

// Рефералы
$referrals_count = $pdo->prepare("SELECT COUNT(*) FROM hunters WHERE referred_by = ?");
$referrals_count->execute([$hunter_id]);
$referrals_count = $referrals_count->fetchColumn();

// Рейтинг
$rank = $pdo->prepare("SELECT COUNT(*) + 1 FROM hunters WHERE points > ?");
$rank->execute([$hunter['points']]);
$rank = $rank->fetchColumn();

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Профиль охотника</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #1a1a2e;
            line-height: 1.5;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 16px;
            text-align: center;
            position: relative;
        }
        .header h1 { font-size: 20px; font-weight: 700; }
        .back-btn {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            text-decoration: none;
            font-size: 24px;
        }
        .container { padding: 16px; max-width: 600px; margin: 0 auto; }
        .profile-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            text-align: center;
        }
        .avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 32px;
            color: white;
            font-weight: 700;
        }
        .profile-name { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
        .profile-login { color: #888; font-size: 14px; margin-bottom: 8px; }
        .level-badge {
            display: inline-block;
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
        }
        .stat-label {
            font-size: 12px;
            color: #888;
            margin-top: 4px;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .info-title {
            font-size: 14px;
            font-weight: 600;
            color: #888;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #666; font-size: 14px; }
        .info-value { font-weight: 600; font-size: 14px; }
        .btn {
            display: block;
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            border: none;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            margin-bottom: 10px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        .btn-danger {
            background: #ff4757;
            color: white;
        }
        .referral-code {
            background: #f0f4ff;
            border: 2px dashed #667eea;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            margin-top: 12px;
        }
        .referral-code-label {
            font-size: 12px;
            color: #888;
            margin-bottom: 4px;
        }
        .referral-code-value {
            font-size: 20px;
            font-weight: 700;
            color: #667eea;
            font-family: monospace;
        }
        .alert {
            background: #d4edda;
            color: #155724;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 8px 0;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            z-index: 100;
        }
        .nav-item {
            text-align: center;
            color: #888;
            text-decoration: none;
            font-size: 11px;
            padding: 4px 12px;
        }
        .nav-item.active { color: #667eea; }
        .nav-item span { display: block; font-size: 20px; margin-bottom: 2px; }
        .content { padding-bottom: 70px; }
    </style>
</head>
<body>
    <div class="header">
        <a href="hunter_dashboard.php" class="back-btn">←</a>
        <h1>👤 Мой профиль</h1>
    </div>

    <div class="content">
        <div class="container">
            <?php if ($message): ?>
                <div class="alert"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="profile-card">
                <div class="avatar"><?= mb_substr(htmlspecialchars($hunter['full_name']), 0, 1) ?></div>
                <div class="profile-name"><?= htmlspecialchars($hunter['full_name']) ?></div>
                <div class="profile-login">@<?= htmlspecialchars($hunter['login']) ?></div>
                <div class="level-badge">⭐ Уровень <?= $hunter['hunter_level'] ?? 1 ?></div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $hunter['points'] ?></div>
                    <div class="stat-label">XP баллов</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">#<?= $rank ?></div>
                    <div class="stat-label">Место в рейтинге</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $total_leads ?></div>
                    <div class="stat-label">Всего лидов</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $approved_leads ?></div>
                    <div class="stat-label">Подтверждено</div>
                </div>
            </div>

            <div class="info-card">
                <div class="info-title">💰 Бонусы</div>
                <div class="info-row">
                    <span class="info-label">Начислено баллов</span>
                    <span class="info-value"><?= $total_bonus ?> XP</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Конвертировано</span>
                    <span class="info-value"><?= $converted_bonus ?> XP</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Доступно</span>
                    <span class="info-value" style="color: #667eea; font-size: 18px;"><?= $total_bonus - $converted_bonus ?> XP</span>
                </div>
            </div>

            <div class="info-card">
                <div class="info-title">📋 Контакты</div>
                <div class="info-row">
                    <span class="info-label">Телефон</span>
                    <span class="info-value"><?= htmlspecialchars($hunter['phone']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email</span>
                    <span class="info-value"><?= htmlspecialchars($hunter['email'] ?? '—') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Дата регистрации</span>
                    <span class="info-value"><?= date('d.m.Y', strtotime($hunter['created_at'])) ?></span>
                </div>
            </div>

            <div class="info-card">
                <div class="info-title">👥 Рефералы</div>
                <div class="info-row">
                    <span class="info-label">Приглашено друзей</span>
                    <span class="info-value"><?= $referrals_count ?></span>
                </div>
                <div class="referral-code">
                    <div class="referral-code-label">Твой реферальный код</div>
                    <div class="referral-code-value" onclick="copyCode()"><?= htmlspecialchars($hunter['referral_code']) ?></div>
                </div>
            </div>

            <a href="hunter_referrals.php" class="btn btn-primary">👥 Мои друзья</a>
            <a href="hunter_rating.php" class="btn btn-primary" style="background: linear-gradient(135deg, #f093fb, #f5576c);">🏆 Рейтинг</a>
            <a href="hunter_logout.php" class="btn btn-danger">🚪 Выйти</a>
        </div>
    </div>

    <div class="bottom-nav">
        <a href="hunter_dashboard.php" class="nav-item">
            <span>📊</span>
            Лиды
        </a>
        <a href="hunter_rating.php" class="nav-item">
            <span>🏆</span>
            Рейтинг
        </a>
        <a href="hunter_referrals.php" class="nav-item">
            <span>👥</span>
            Друзья
        </a>
        <a href="hunter_profile.php" class="nav-item active">
            <span>👤</span>
            Профиль
        </a>
    </div>

    <script>
        function copyCode() {
            const code = '<?= $hunter['referral_code'] ?>';
            navigator.clipboard.writeText(code).then(() => {
                alert('Реферальный код скопирован!');
            });
        }
    </script>
</body>
</html>
