<?php
session_start();
require_once 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($login) || empty($password)) {
        $error = 'Введите логин и пароль';
    } else {
        // Проверяем по логину, телефону или email
        $stmt = $pdo->prepare("SELECT * FROM hunters WHERE login = ? OR phone = ? OR email = ? LIMIT 1");
        $stmt->execute([$login, $login, $login]);
        $hunter = $stmt->fetch();

        if ($hunter && password_verify($password, $hunter['password'])) {
            if (!$hunter['is_active']) {
                $error = 'Аккаунт заблокирован';
            } else {
                $_SESSION['hunter_id'] = $hunter['id'];
                $_SESSION['hunter_name'] = $hunter['full_name'];
                header('Location: hunter_dashboard.php');
                exit;
            }
        } else {
            $error = 'Неверный логин или пароль';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Вход Охотника</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
            min-height: 100vh; color: #1e293b; display: flex; align-items: center; justify-content: center;
        }
        .container { max-width: 400px; width: 100%; padding: 20px; }
        .login-card {
            background: #fff; border-radius: 24px; padding: 40px 32px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06); text-align: center;
        }
        .logo {
            width: 64px; height: 64px; margin: 0 auto 20px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 20px; display: flex; align-items: center; justify-content: center;
            font-size: 28px;
        }
        .login-card h1 {
            font-family: 'Orbitron', sans-serif; font-size: 20px; font-weight: 700;
            color: #4f46e5; margin-bottom: 8px;
        }
        .login-card p { color: #64748b; font-size: 14px; margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; text-align: left; }
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
            border: 1px solid #fecaca; text-align: left;
        }
        .btn {
            width: 100%; padding: 16px; border: none; border-radius: 14px;
            font-family: 'Orbitron', sans-serif; font-size: 15px; font-weight: 700;
            cursor: pointer; transition: all 0.3s; text-transform: uppercase; letter-spacing: 1px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff;
            box-shadow: 0 8px 32px rgba(99,102,241,0.3); margin-top: 8px;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 12px 40px rgba(99,102,241,0.4); }
        .links { margin-top: 24px; font-size: 14px; color: #64748b; }
        .links a { color: #6366f1; text-decoration: none; font-weight: 600; display: block; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-card">
            <div class="logo">🎯</div>
            <h1>ВХОД ОХОТНИКА</h1>
            <p>Войди в свой аккаунт</p>

            <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <input type="text" name="login" class="form-input" 
                           placeholder="Логин, телефон или email" required
                           value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <input type="password" name="password" class="form-input" 
                           placeholder="Пароль" required>
                </div>
                <button type="submit" class="btn">🔓 Войти</button>
            </form>

            <div class="links">
                <a href="reset_password.php">🔑 Забыли пароль?</a>
                <a href="hunter_register.php">🚀 Создать аккаунт</a>
            </div>
        </div>
    </div>
</body>
</html>
