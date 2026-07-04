<?php
session_start();
require_once 'db.php';

$error = '';
$success = '';
$step = $_SESSION['reset_step'] ?? 'request';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_reset'])) {
        $login = trim($_POST['login'] ?? '');
        $stmt = $pdo->prepare("SELECT id, phone, email FROM hunters WHERE login = ? OR phone = ? OR email = ? LIMIT 1");
        $stmt->execute([$login, $login, $login]);
        $hunter = $stmt->fetch();

        if ($hunter) {
            $code = strtoupper(substr(md5(uniqid()), 0, 6));
            $_SESSION['reset_code'] = $code;
            $_SESSION['reset_hunter_id'] = $hunter['id'];
            $_SESSION['reset_step'] = 'verify';
            $step = 'verify';
            // В реальном проекте здесь отправка SMS/email
            $success = 'Код подтверждения: ' . $code . ' (в демо показываем здесь)';
        } else {
            $error = 'Аккаунт не найден';
        }
    } elseif (isset($_POST['verify_code'])) {
        $code = trim($_POST['code'] ?? '');
        if ($code === ($_SESSION['reset_code'] ?? '')) {
            $_SESSION['reset_step'] = 'new_password';
            $step = 'new_password';
        } else {
            $error = 'Неверный код';
        }
    } elseif (isset($_POST['new_password'])) {
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (strlen($password) < 6) {
            $error = 'Пароль минимум 6 символов';
        } elseif ($password !== $password2) {
            $error = 'Пароли не совпадают';
        } else {
            $hunter_id = $_SESSION['reset_hunter_id'] ?? 0;
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE hunters SET password = ? WHERE id = ?")->execute([$hashed, $hunter_id]);

            unset($_SESSION['reset_code'], $_SESSION['reset_hunter_id'], $_SESSION['reset_step']);
            $success = 'Пароль изменён! Теперь войди с новым паролем.';
            $step = 'done';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Сброс пароля</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
            min-height: 100vh; color: #1e293b; display: flex; align-items: center; justify-content: center;
        }
        .container { max-width: 400px; width: 100%; padding: 20px; }
        .card {
            background: #fff; border-radius: 24px; padding: 40px 32px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06); text-align: center;
        }
        .card h1 { font-family: 'Orbitron', sans-serif; font-size: 18px; color: #4f46e5; margin-bottom: 8px; }
        .card p { color: #64748b; font-size: 14px; margin-bottom: 24px; }
        .form-input {
            width: 100%; padding: 14px 16px; border: 2px solid #e2e8f0;
            border-radius: 12px; font-size: 15px; margin-bottom: 12px;
            background: #f8fafc; font-family: 'Inter', sans-serif; color: #1e293b;
        }
        .form-input:focus { outline: none; border-color: #6366f1; background: #fff; }
        .btn {
            width: 100%; padding: 16px; border: none; border-radius: 14px;
            font-family: 'Orbitron', sans-serif; font-size: 14px; font-weight: 700;
            cursor: pointer; background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff; box-shadow: 0 8px 32px rgba(99,102,241,0.3); margin-top: 8px;
        }
        .error-msg { background: #fef2f2; color: #dc2626; padding: 12px; border-radius: 12px; font-size: 13px; margin-bottom: 16px; border: 1px solid #fecaca; }
        .success-msg { background: #f0fdf4; color: #16a34a; padding: 12px; border-radius: 12px; font-size: 13px; margin-bottom: 16px; border: 1px solid #bbf7d0; }
        .links { margin-top: 20px; font-size: 14px; }
        .links a { color: #6366f1; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>🔑 СБРОС ПАРОЛЯ</h1>
            <p>Восстановление доступа к аккаунту</p>

            <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="success-msg"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if ($step === 'request'): ?>
            <form method="POST">
                <input type="text" name="login" class="form-input" placeholder="Логин, телефон или email" required>
                <button type="submit" name="request_reset" class="btn">📩 Получить код</button>
            </form>
            <?php elseif ($step === 'verify'): ?>
            <form method="POST">
                <input type="text" name="code" class="form-input" placeholder="Введите код подтверждения" required maxlength="6">
                <button type="submit" name="verify_code" class="btn">✅ Подтвердить</button>
            </form>
            <?php elseif ($step === 'new_password'): ?>
            <form method="POST">
                <input type="password" name="password" class="form-input" placeholder="Новый пароль (мин. 6 символов)" required>
                <input type="password" name="password2" class="form-input" placeholder="Повторите пароль" required>
                <button type="submit" name="new_password" class="btn">💾 Сохранить</button>
            </form>
            <?php elseif ($step === 'done'): ?>
            <div class="links"><a href="hunter_login.php">🔓 Войти с новым паролем</a></div>
            <?php endif; ?>

            <?php if ($step !== 'done'): ?>
            <div class="links"><a href="hunter_login.php">← Назад к входу</a></div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
