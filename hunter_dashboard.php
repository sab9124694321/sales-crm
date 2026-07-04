<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['hunter_id'])) {
    header('Location: hunter_login.php');
    exit;
}

$hunter_id = $_SESSION['hunter_id'];

// Данные охотника
$stmt = $pdo->prepare("SELECT * FROM hunters WHERE id = ?");
$stmt->execute([$hunter_id]);
$hunter = $stmt->fetch();

if (!$hunter) {
    session_destroy();
    header('Location: hunter_login.php');
    exit;
}

// Статистика
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM hunter_leads WHERE hunter_id = ?");
$stmt->execute([$hunter_id]);
$total_leads = $stmt->fetch()['total'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as approved FROM hunter_leads WHERE hunter_id = ? AND status = 'converted'");
$stmt->execute([$hunter_id]);
$approved_leads = $stmt->fetch()['approved'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as pending FROM hunter_leads WHERE hunter_id = ? AND status IN ('new', 'assigned')");
$stmt->execute([$hunter_id]);
$pending_leads = $stmt->fetch()['pending'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as rejected FROM hunter_leads WHERE hunter_id = ? AND status = 'rejected'");
$stmt->execute([$hunter_id]);
$rejected_leads = $stmt->fetch()['rejected'] ?? 0;

// Лиды охотника
$stmt = $pdo->prepare("SELECT * FROM hunter_leads WHERE hunter_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$hunter_id]);
$leads = $stmt->fetchAll();

// Уведомления — счётчик непрочитанных
$stmt = $pdo->prepare("SELECT COUNT(*) FROM hunter_notifications WHERE hunter_id = ? AND is_read = 0");
$stmt->execute([$hunter_id]);
$unread_count = $stmt->fetchColumn();

// Топ охотников
$stmt = $pdo->query("SELECT login, full_name, points, hunter_level FROM hunters WHERE is_active = 1 ORDER BY points DESC LIMIT 10");
$top_hunters = $stmt->fetchAll();

// Реферальный код
$referral_link = 'https://szb-sales.ru/terms.php?ref=' . urlencode($hunter['referral_code'] ?? '');

// Сообщения
$lead_success = $_SESSION['lead_success'] ?? '';
$lead_error = $_SESSION['lead_error'] ?? '';
unset($_SESSION['lead_success'], $_SESSION['lead_error']);

// XP для уровня
$current_xp = $hunter['points'] ?? 0;
$level = $hunter['hunter_level'] ?? 1;
$xp_for_next = $level * 100;
$xp_progress = min(100, ($current_xp % $xp_for_next) / $xp_for_next * 100);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Кабинет Охотника</title>
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
        .notify-btn { position: relative; background: rgba(255,255,255,0.2); border: none; border-radius: 12px; padding: 8px 12px; color: #fff; font-size: 20px; cursor: pointer; }
        .notify-badge { position: absolute; top: -4px; right: -4px; background: #ef4444; color: #fff; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center; font-weight: 700; }
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
        .xp-bar { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
        .xp-track { flex: 1; height: 6px; background: rgba(255,255,255,0.3); border-radius: 3px; overflow: hidden; }
        .xp-fill { height: 100%; background: #fff; border-radius: 3px; transition: width 0.5s; }
        .xp-text { font-size: 11px; font-weight: 600; }

        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;
            padding: 16px; margin-top: -20px; position: relative; z-index: 2;
        }
        .stat-card {
            background: #fff; border-radius: 16px; padding: 14px 8px;
            text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .stat-icon { font-size: 22px; margin-bottom: 4px; }
        .stat-value { font-family: 'Orbitron', sans-serif; font-size: 18px; font-weight: 700; color: #4f46e5; }
        .stat-label { font-size: 10px; color: #94a3b8; margin-top: 2px; }

        .section { padding: 0 16px; margin-bottom: 20px; }
        .section-title {
            font-family: 'Orbitron', sans-serif; font-size: 13px; font-weight: 700;
            color: #4f46e5; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;
        }
        .card {
            background: #fff; border-radius: 16px; padding: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04); margin-bottom: 12px;
        }
        .form-group { margin-bottom: 14px; }
        .form-label { display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 5px; }
        .form-label .required { color: #ef4444; }
        .form-input {
            width: 100%; padding: 12px 14px; border: 2px solid #e2e8f0;
            border-radius: 10px; font-size: 14px; font-family: 'Inter', sans-serif;
            background: #f8fafc; transition: all 0.2s; color: #1e293b;
        }
        .form-input:focus { outline: none; border-color: #6366f1; background: #fff; }
        .form-input::placeholder { color: #94a3b8; }
        .form-hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }
        .btn {
            width: 100%; padding: 14px; border: none; border-radius: 12px;
            font-family: 'Orbitron', sans-serif; font-size: 14px; font-weight: 700;
            cursor: pointer; transition: all 0.3s; text-transform: uppercase; letter-spacing: 0.5px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff;
            box-shadow: 0 6px 24px rgba(99,102,241,0.25);
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 8px 28px rgba(99,102,241,0.35); }
        .success-msg { background: #f0fdf4; color: #16a34a; padding: 12px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 12px; border: 1px solid #bbf7d0; }
        .error-msg { background: #fef2f2; color: #dc2626; padding: 12px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 12px; border: 1px solid #fecaca; }

        .lead-item {
            display: flex; align-items: center; gap: 12px; padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .lead-item:last-child { border-bottom: none; }
        .lead-status {
            width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
        }
        .status-new { background: #f59e0b; }
        .status-assigned { background: #3b82f6; }
        .status-converted { background: #22c55e; }
        .status-rejected { background: #ef4444; }
        .lead-info { flex: 1; min-width: 0; }
        .lead-name { font-size: 14px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lead-inn { font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .lead-date { font-size: 11px; color: #94a3b8; }
        .lead-points { font-family: 'Orbitron', sans-serif; font-size: 12px; font-weight: 700; color: #6366f1; }

        .top-item {
            display: flex; align-items: center; gap: 10px; padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .top-item:last-child { border-bottom: none; }
        .top-rank {
            width: 28px; height: 28px; border-radius: 50%; display: flex;
            align-items: center; justify-content: center; font-size: 12px;
            font-weight: 700; flex-shrink: 0;
        }
        .rank-1 { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; }
        .rank-2 { background: linear-gradient(135deg, #94a3b8, #64748b); color: #fff; }
        .rank-3 { background: linear-gradient(135deg, #b45309, #92400e); color: #fff; }
        .rank-other { background: #f1f5f9; color: #64748b; }
        .top-name { flex: 1; font-size: 13px; font-weight: 600; }
        .top-xp { font-family: 'Orbitron', sans-serif; font-size: 12px; color: #6366f1; font-weight: 700; }

        .ref-box {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 12px; padding: 14px; border: 1px solid #fcd34d;
        }
        .ref-code {
            font-family: 'Orbitron', monospace; font-size: 18px; font-weight: 700;
            color: #92400e; background: #fff; padding: 8px 16px;
            border-radius: 8px; display: inline-block; margin-top: 8px;
            border: 2px dashed #fbbf24;
        }
        .ref-text { font-size: 12px; color: #a16207; margin-top: 4px; }

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

        .consent-box {
            display: flex; align-items: flex-start; gap: 8px;
            padding: 10px; background: #fefce8; border-radius: 8px;
            border: 1px solid #fde047; margin-bottom: 12px;
        }
        .consent-box input { margin-top: 2px; }
        .consent-box label { font-size: 12px; color: #713f12; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-top">
            <div class="header-title">🎯 ОХОТНИК</div>
            <a href="hunter_notifications.php" class="notify-btn" style="text-decoration: none; display: inline-flex;">
                🔔
                <?php if ($unread_count > 0): ?>
                <span class="notify-badge"><?= $unread_count ?></span>
                <?php endif; ?>
            </a>
        </div>
        <div class="profile-card">
            <div class="avatar">🏆</div>
            <div class="profile-info">
                <div class="profile-name"><?= htmlspecialchars($hunter['full_name'] ?? 'Охотник') ?></div>
                <div class="profile-level">⭐ Уровень <?= $level ?> · <?= $current_xp ?> XP</div>
                <div class="xp-bar">
                    <div class="xp-track"><div class="xp-fill" style="width: <?= $xp_progress ?>%"></div></div>
                    <div class="xp-text"><?= round($xp_progress) ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">🎯</div>
            <div class="stat-value"><?= $total_leads ?></div>
            <div class="stat-label">Всего лидов</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-value"><?= $approved_leads ?></div>
            <div class="stat-label">Одобрено</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-value"><?= $pending_leads ?></div>
            <div class="stat-label">На проверке</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🏆</div>
            <div class="stat-value"><?= $rejected_leads ?></div>
            <div class="stat-label">Отказов</div>
        </div>
    </div>

    <?php if ($lead_success): ?>
    <div class="section"><div class="success-msg"><?= htmlspecialchars($lead_success) ?></div></div>
    <?php endif; ?>
    <?php if ($lead_error): ?>
    <div class="section"><div class="error-msg"><?= htmlspecialchars($lead_error) ?></div></div>
    <?php endif; ?>

    <div class="section">
        <div class="section-title">🎯 Новый лид</div>
        <div class="card">
            <form method="POST" action="submit_lead.php">
                <div class="form-group">
                    <label class="form-label">Название заведения <span class="required">*</span></label>
                    <input type="text" name="client_name" class="form-input" placeholder="Например: Кафе 'Вкусно'" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Телефон заведения <span class="required">*</span></label>
                    <input type="tel" name="client_phone" id="client_phone" class="form-input" placeholder="+7 (999) 999-99-99" required>
                </div>

                <div class="form-group">
                    <label class="form-label">ИНН <span class="required">*</span></label>
                    <input type="text" name="inn" class="form-input" placeholder="10 или 12 цифр" 
                           maxlength="12" pattern="[0-9]{10}|[0-9]{12}" required>
                    <div class="form-hint">Обязательное поле. Проверьте ИНН на сайте nalog.ru</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Имя контактного лица <span class="required">*</span></label>
                    <input type="text" name="contact_name" class="form-input" placeholder="Иванов Иван" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Телефон контакта <span class="required">*</span></label>
                    <input type="tel" name="contact_phone" id="contact_phone" class="form-input" placeholder="+7 (999) 999-99-99" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Email контакта</label>
                    <input type="email" name="client_email" class="form-input" placeholder="email@example.com">
                </div>

                <div class="form-group">
                    <label class="form-label">Адрес</label>
                    <input type="text" name="address" class="form-input" placeholder="Город, улица, дом">
                </div>

                <div class="consent-box">
                    <input type="checkbox" id="consent" name="consent" required>
                    <label for="consent">Я подтверждаю, что получил(а) <strong>согласие</strong> на передачу персональных данных (ФИО и телефон) контактного лица третьим лицам в рамках данной программы</label>
                </div>

                <button type="submit" class="btn">🚀 Отправить лид · +50 XP</button>
            </form>
        </div>
    </div>

    <div class="section">
        <div class="section-title">📋 Мои лиды</div>
        <div class="card">
            <?php if (empty($leads)): ?>
            <div style="text-align: center; padding: 20px; color: #94a3b8; font-size: 14px;">
                🎯 У тебя пока нет лидов<br>
                <span style="font-size: 12px;">Добавь первый и получи бонус!</span>
            </div>
            <?php else: ?>
            <?php foreach ($leads as $lead): ?>
            <div class="lead-item">
                <div class="lead-status status-<?= $lead['status'] ?>"></div>
                <div class="lead-info">
                    <div class="lead-name"><?= htmlspecialchars($lead['client_name'] ?? 'Без названия') ?></div>
                    <div class="lead-inn">ИНН: <?= htmlspecialchars($lead['inn'] ?? '') ?> · <?= date('d.m', strtotime($lead['created_at'])) ?> · <span style="font-weight:600;color:<?= $lead['status']==='converted'?'#22c55e':($lead['status']==='rejected'?'#ef4444':($lead['status']==='assigned'?'#3b82f6':'#f59e0b')) ?>"><?= $lead['status']==='converted'?'✅ Успех':($lead['status']==='rejected'?'❌ Отказ':($lead['status']==='assigned'?'🔵 В работе':'⏳ Новый')) ?></span></div>
                </div>
                <div class="lead-points">+<?= ($lead['bonus_points'] ?? 0) + ($lead['converted_bonus'] ?? 0) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>



    <div class="section">
        <div class="section-title">👥 Приглашай друзей</div>
        <div class="card">
            <div class="ref-box">
                <div style="font-size: 13px; font-weight: 600; color: #92400e;">Твой код приглашения</div>
                <div class="ref-code"><?= htmlspecialchars($hunter['referral_code'] ?? '') ?></div>
                <div class="ref-text">Поделись кодом — получи +500 XP за каждого друга</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">🏆 Топ Охотников</div>
        <div class="card">
            <?php foreach ($top_hunters as $i => $top): ?>
            <?php $rank = $i + 1; $rank_class = $rank <= 3 ? 'rank-' . $rank : 'rank-other'; ?>
            <div class="top-item">
                <div class="top-rank <?= $rank_class ?>"><?= $rank ?></div>
                <div class="top-name"><?= htmlspecialchars($top['login'] ?? 'Охотник') ?></div>
                <div class="top-xp"><?= $top['points'] ?? 0 ?> XP</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bottom-nav">
        <a href="hunter_dashboard.php" class="nav-item active">
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

    <script>
        // Маска телефона: ввод 9 или 8 → формат +7 (XXX) XXX-XX-XX
        function formatPhone(input) {
            let value = input.value.replace(/\D/g, '');

            // Если начинается с 8 или 9 — заменяем на +7
            if (value.startsWith('8')) {
                value = '7' + value.slice(1);
            } else if (value.startsWith('9')) {
                value = '7' + value;
            } else if (value.startsWith('7')) {
                // уже с 7, оставляем
            } else if (value.length > 0) {
                // любая другая цифра — добавляем 7 в начало
                value = '7' + value;
            }

            let formatted = '+7';
            if (value.length > 1) {
                const rest = value.slice(1);
                if (rest.length > 0) formatted += ' (' + rest.slice(0, 3);
                if (rest.length >= 3) formatted += ')';
                if (rest.length > 3) formatted += ' ' + rest.slice(3, 6);
                if (rest.length > 6) formatted += '-' + rest.slice(6, 8);
                if (rest.length > 8) formatted += '-' + rest.slice(8, 10);
            }

            input.value = formatted;
        }

        document.getElementById('client_phone').addEventListener('input', function(e) {
            formatPhone(e.target);
        });

        document.getElementById('contact_phone').addEventListener('input', function(e) {
            formatPhone(e.target);
        });
    </script>
</body>
</html>
