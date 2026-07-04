<?php
session_start();
require_once 'db.php';

$error = '';
$success = '';

function normalizePhone($phone) {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) === 11 && $digits[0] === '7') {
        return '+' . $digits;
    }
    if (strlen($digits) === 10) {
        return '+7' . $digits;
    }
    if (strlen($digits) === 11 && $digits[0] === '8') {
        return '+7' . substr($digits, 1);
    }
    return $phone;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = normalizePhone(trim($_POST['phone'] ?? ''));
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $referral_code = trim($_POST['referral_code'] ?? '');

    if (empty($login) || empty($name) || empty($phone) || empty($password)) {
        $error = 'Заполните все обязательные поля';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль минимум 6 символов';
    } elseif ($password !== $password2) {
        $error = 'Пароли не совпадают';
    } elseif (!preg_match('/^\+7[0-9]{10}$/', $phone)) {
        $error = 'Введите корректный номер телефона (10 цифр после +7)';
    } else {
        // Проверяем уникальность логина
        $stmt = $pdo->prepare("SELECT id FROM hunters WHERE login = ? OR phone = ?");
        $stmt->execute([$login, $phone]);
        if ($stmt->fetch()) {
            $error = 'Этот логин или телефон уже зарегистрирован';
        } else {
            // Генерируем реферальный код
            $my_referral = 'H' . strtoupper(substr(md5(uniqid()), 0, 8));

            // Проверяем реферальный код пригласившего
            $referred_by = null;
            if (!empty($referral_code)) {
                $stmt = $pdo->prepare("SELECT id FROM hunters WHERE referral_code = ?");
                $stmt->execute([$referral_code]);
                $ref = $stmt->fetch();
                if ($ref) {
                    $referred_by = $ref['id'];
                    // Начисляем 500 бонусов пригласившему
                    $pdo->prepare("UPDATE hunters SET points = points + 500 WHERE id = ?")
                        ->execute([$referred_by]);
                    // Уведомление пригласившему
                    $pdo->prepare("INSERT INTO hunter_notifications (hunter_id, message, type) VALUES (?, ?, 'success')")
                        ->execute([$referred_by, "По вашей ссылке зарегистрировался новый охотник! +500 XP"]);
                }
            }

            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO hunters (login, full_name, phone, email, password, referral_code, referred_by, points, hunter_level, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 50, 1, 1, datetime('now'))");
            $stmt->execute([$login, $name, $phone, $email, $hashed, $my_referral, $referred_by]);

            $hunter_id = $pdo->lastInsertId();

            // Автоматический вход
            $_SESSION['hunter_id'] = $hunter_id;
            $_SESSION['hunter_name'] = $name;

            // Уведомление о регистрации
            $pdo->prepare("INSERT INTO hunter_notifications (hunter_id, message, type) VALUES (?, ?, 'info')")
                ->execute([$hunter_id, "Добро пожаловать в команду Охотников! Добавь свой первый лид и получи +50 XP."]);

            header('Location: hunter_dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Регистрация Охотника</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
            min-height: 100vh; color: #1e293b;
        }
        .container { max-width: 480px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; padding: 30px 0 20px; }
        .header h1 { font-family: 'Orbitron', sans-serif; font-size: 22px; font-weight: 700; color: #4f46e5; }
        .header p { color: #64748b; font-size: 14px; margin-top: 6px; }
        .card {
            background: #fff; border-radius: 20px; padding: 24px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06); margin-bottom: 16px;
        }
        .form-group { margin-bottom: 16px; }
        .form-label {
            display: block; font-size: 13px; font-weight: 600; color: #475569;
            margin-bottom: 6px;
        }
        .form-label .required { color: #ef4444; }
        .form-input {
            width: 100%; padding: 14px 16px; border: 2px solid #e2e8f0;
            border-radius: 12px; font-size: 15px; font-family: 'Inter', sans-serif;
            background: #f8fafc; transition: all 0.2s; color: #1e293b;
        }
        .form-input:focus { outline: none; border-color: #6366f1; background: #fff; }
        .form-input::placeholder { color: #94a3b8; }
        .error-msg {
            background: #fef2f2; color: #dc2626; padding: 12px 16px;
            border-radius: 12px; font-size: 13px; margin-bottom: 16px;
            border: 1px solid #fecaca;
        }
        .success-msg {
            background: #f0fdf4; color: #16a34a; padding: 12px 16px;
            border-radius: 12px; font-size: 13px; margin-bottom: 16px;
            border: 1px solid #bbf7d0;
        }
        .btn {
            width: 100%; padding: 16px; border: none; border-radius: 14px;
            font-family: 'Orbitron', sans-serif; font-size: 15px; font-weight: 700;
            cursor: pointer; transition: all 0.3s; text-transform: uppercase; letter-spacing: 1px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff;
            box-shadow: 0 8px 32px rgba(99,102,241,0.3);
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 12px 40px rgba(99,102,241,0.4); }
        .login-link { text-align: center; margin-top: 20px; font-size: 14px; color: #64748b; }
        .login-link a { color: #6366f1; text-decoration: none; font-weight: 600; }
        .hint { font-size: 12px; color: #94a3b8; margin-top: 4px; }
        .ref-section {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 12px; padding: 16px; margin-bottom: 16px;
            border: 1px solid #fcd34d;
        }
        .ref-title { font-size: 13px; font-weight: 700; color: #92400e; margin-bottom: 4px; }
        .ref-text { font-size: 12px; color: #a16207; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 СТАНЬ ОХОТНИКОМ</h1>
            <p>Регистрация в программе лояльности</p>
        </div>

        <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Логин <span class="required">*</span></label>
                    <input type="text" name="login" class="form-input" placeholder="Придумай никнейм" 
                           value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" required>
                    <div class="hint">Будет виден в рейтинге</div>
                </div>

                <div class="form-group">
                    <label class="form-label">ФИО <span class="required">*</span></label>
                    <input type="text" name="name" class="form-input" placeholder="Иванов Иван Иванович" 
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Телефон <span class="required">*</span></label>
                    <input type="tel" name="phone" class="form-input" placeholder="+7 (999) 999-99-99" 
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                    <div class="hint">Формат: +7 и 10 цифр</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-input" placeholder="email@example.com" 
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Пароль <span class="required">*</span></label>
                    <input type="password" name="password" class="form-input" placeholder="Минимум 6 символов" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Повторите пароль <span class="required">*</span></label>
                    <input type="password" name="password2" class="form-input" placeholder="Тот же пароль" required>
                </div>

                <div class="ref-section">
                    <div class="ref-title">👥 Есть код приглашения?</div>
                    <div class="ref-text">Пригласивший получит +500 XP, а ты — стартовый бонус</div>
                    <input type="text" name="referral_code" class="form-input" style="margin-top: 8px;"
                           placeholder="HXXXXXXXX" value="<?= htmlspecialchars($_POST['referral_code'] ?? '') ?>">
                </div>

                <button type="submit" class="btn">🚀 Создать аккаунт</button>
            </form>
        </div>

        <div class="login-link">
            Уже есть аккаунт? <a href="hunter_login.php">Войти</a>
        </div>
    </div>
</body>
</html>
