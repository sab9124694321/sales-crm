<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'db.php';
    $tabel = trim($_POST['tabel'] ?? '');
    $pass = $_POST['password'] ?? '';
    $user = getUserByTabel($pdo, $tabel);
    if ($user && password_verify($pass, $user['password_hash']) && $user['is_active']) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['tabel'] = $user['tabel_number'];
        $_SESSION['name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        
        // Перенаправление ТОЛЬКО для ММБ
        if (in_array($user['role'], ['mmb_manager', 'mmb_tp_head'])) {
            header('Location: mmb_dashboard.php');
        } else {
            header('Location: dashboard.php');
        }
        exit;
    }
    $error = 'Неверный табельный номер или пароль';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Центр управления продажами (ЦУП) – вход</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,sans-serif;background:linear-gradient(135deg,#0a0a1a,#1a1a3e);min-height:100vh;display:flex;align-items:center;justify-content:center}
.card{background:rgba(20,20,50,.9);border-radius:20px;padding:40px;border:1px solid rgba(100,100,255,.3);width:100%;max-width:400px}
h1{color:#e0e0ff;font-size:24px;text-align:center;margin-bottom:20px}
label{color:#aaa;font-size:13px;display:block;margin-bottom:5px}
input{width:100%;padding:14px;background:rgba(10,10,30,.8);border:1px solid rgba(100,100,255,.2);border-radius:10px;color:#fff;font-size:16px;margin-bottom:15px}
button{width:100%;padding:14px;background:linear-gradient(135deg,#44c,#66e);border:none;border-radius:10px;color:#fff;font-size:16px;cursor:pointer}
.error{background:rgba(255,50,50,.15);color:#f66;padding:12px;border-radius:8px;margin-bottom:15px;text-align:center}
</style></head>
<body>
<div class="card">
<h1>🚀 Центр управления продажами</h1>
<?php if(isset($error)) echo "<div class='error'>$error</div>"; ?>
<form method="POST">
<label>Табельный номер</label><input type="text" name="tabel" required>
<label>Пароль</label><input type="password" name="password" required>
<button type="submit">Войти</button>
</form>
</div></body></html>