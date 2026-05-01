<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$message = '';
$error = '';

// Функция отправки почты
function sendCredentials($email, $tabel, $password, $name) {
    $subject = "=?UTF-8?B?" . base64_encode("Sales CRM - Ваши учётные данные") . "?=";
    $body = "
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif;'>
        <h2 style='color: #00a36c;'>Sales CRM</h2>
        <p>Уважаемый(ая) <strong>$name</strong>,</p>
        <p>Вам были выданы учётные данные для доступа к системе управления продажами.</p>
        <h3>Данные для входа:</h3>
        <ul>
            <li><strong>Ссылка:</strong> http://5.129.248.239/login.php</li>
            <li><strong>Табельный номер (логин):</strong> $tabel</li>
            <li><strong>Пароль:</strong> $password</li>
        </ul>
        <p>Рекомендуем сменить пароль после первого входа.</p>
        <hr>
        <p style='font-size: 12px; color: #666;'>Это автоматическое сообщение, пожалуйста, не отвечайте на него.</p>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type: text/html; charset=utf-8" . "\r\n";
    $headers .= "From: Sales CRM <noreply@sales-crm.local>" . "\r\n";
    
    return mail($email, $subject, $body, $headers);
}

// Обработка загрузки CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Ошибка загрузки файла. Код: " . $file['error'];
    } else {
        $csvData = array_map('str_getcsv', file($file['tmp_name']));
        
        if (empty($csvData)) {
            $error = "Файл пуст или не может быть прочитан";
        } else {
            // Удаляем заголовок
            array_shift($csvData);
            
            $added = 0;
            $emailsSent = 0;
            $errors = [];
            
            foreach ($csvData as $row) {
                if (count($row) < 5) continue;
                
                $tabel = trim($row[0]);
                $full_name = trim($row[1]);
                $role = trim($row[3] ?? 'employee');
                $manager_tabel = trim($row[4] ?? '');
                $email = trim($row[5] ?? ''); // Добавляем колонку email
                
                if (empty($tabel) || empty($full_name)) continue;
                
                // Генерация случайного пароля
                $password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Находим руководителя
                $manager_id = null;
                if (!empty($manager_tabel)) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE tabel_number = ?");
                    $stmt->execute([$manager_tabel]);
                    $manager_id = $stmt->fetchColumn();
                }
                
                try {
                    $stmt = $pdo->prepare("INSERT INTO users (tabel_number, full_name, phone, role, manager_id, password) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$tabel, $full_name, '', $role, $manager_id, $hashed_password]);
                    $added++;
                    
                    // Отправка email, если указан
                    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        if (sendCredentials($email, $tabel, $password, $full_name)) {
                            $emailsSent++;
                        } else {
                            $errors[] = "$tabel - не удалось отправить email на $email";
                        }
                    }
                } catch (PDOException $e) {
                    $errors[] = "$tabel - " . $e->getMessage();
                }
            }
            
            $message = "✅ Импортировано: $added сотрудников. Отправлено писем: $emailsSent";
            if (!empty($errors)) {
                $error = "⚠️ Ошибки: " . implode('; ', array_slice($errors, 0, 3));
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Импорт сотрудников</title>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; background: #f0f2f5; margin: 0; }
        .header { background: #1a2c3e; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #00a36c; }
        .nav a, .logout { color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; background: #00a36c; margin: 5px; display: inline-block; }
        .container { max-width: 900px; margin: 0 auto; padding: 30px 20px; }
        .card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        input[type="file"] { margin: 15px 0; }
        button { background: #00a36c; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 8px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 8px; margin-bottom: 15px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
        .note { background: #fff3cd; padding: 10px; border-radius: 8px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sales CRM</h1>
        <div>
            <a href="dashboard.php" class="nav">📊 Дашборд</a>
            <a href="team.php" class="nav">👥 Команда</a>
            <a href="admin.php" class="nav">⚙️ Админ</a>
            <a href="import_employees.php" class="nav">📥 Импорт</a>
            <a href="logout.php" class="logout">Выйти</a>
        </div>
    </div>
    <div class="container">
        <div class="card">
            <h2>📥 Импорт сотрудников с автоматической отправкой пароля</h2>
            
            <?php if ($message): ?>
                <div class="success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <div class="note">
                <strong>📌 Формат CSV (первая строка - заголовок):</strong><br>
                <strong>Важно!</strong> Добавлена колонка <strong>Email</strong> для отправки пароля.
            </div>
            
            <pre>
Табельный,ФИО,Телефон,Роль,Табельный_руководителя,Email
1002,Иванов Иван Иванович,+79123456789,employee,0001,ivan@company.ru
1003,Петрова Анна Сергеевна,+79129876543,employee,0001,anna@company.ru
1004,Соколов Дмитрий Петрович,+79125556677,manager,,dmitry@company.ru
1005,Козлова Екатерина,+79123334455,employee,1004,ekaterina@company.ru</pre>
            
            <p><strong>Роли:</strong> employee (сотрудник), manager (руководитель), admin (администратор)</p>
            <p><strong>Пароль генерируется автоматически (8 символов) и отправляется на Email</strong></p>
            
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="csv_file" accept=".csv" required>
                <button type="submit">📤 Загрузить и отправить пароли</button>
            </form>
        </div>
        
        <div class="card">
            <h3>📎 Скачать пример CSV</h3>
            <p><a href="#" onclick="downloadTemplate()">📥 Скачать шаблон CSV</a></p>
        </div>
    </div>
    
    <script>
        function downloadTemplate() {
            const csvContent = "Табельный,ФИО,Телефон,Роль,Табельный_руководителя,Email\n1002,Иванов Иван Иванович,+79123456789,employee,0001,ivan@company.ru\n1003,Петрова Анна Сергеевна,+79129876543,employee,0001,anna@company.ru\n1004,Соколов Дмитрий Петрович,+79125556677,manager,,dmitry@company.ru\n1005,Козлова Екатерина,+79123334455,employee,1004,ekaterina@company.ru";
            const blob = new Blob(["\uFEFF" + csvContent], { type: "text/csv;charset=utf-8;" });
            const link = document.createElement("a");
            const url = URL.createObjectURL(blob);
            link.href = url;
            link.setAttribute("download", "template_employees_with_email.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>
