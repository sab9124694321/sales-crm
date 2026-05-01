<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

// Экспорт в CSV
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="credentials_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Табельный номер', 'ФИО', 'Роль', 'Пароль (незашифрованный)', 'Email руководителя']);
    
    // Получаем всех пользователей, у которых есть email
    $users = $pdo->query("SELECT tabel_number, full_name, role FROM users")->fetchAll();
    
    foreach ($users as $user) {
        // Пароль по умолчанию 123456 (нужно указать реальный, но он хранится в хэше)
        // Для рассылки лучше выдать временные пароли
        fputcsv($output, [
            $user['tabel_number'],
            $user['full_name'],
            $user['role'],
            '123456', // временный пароль
            '' // почту нужно добавить вручную или из другого источника
        ]);
    }
    fclose($output);
    exit;
}

// Получаем список для отображения
$users = $pdo->query("SELECT id, tabel_number, full_name, phone, role FROM users ORDER BY role, full_name")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Экспорт учётных данных</title>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; background: #f0f2f5; margin: 0; }
        .header { background: #1a2c3e; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #00a36c; }
        .nav a, .logout { color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; background: #00a36c; margin: 5px; display: inline-block; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 30px; }
        button { background: #00a36c; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-size: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 12px; display: inline-block; }
        .badge.admin { background: #d4edda; color: #155724; }
        .badge.manager { background: #fff3cd; color: #856404; }
        .badge.employee { background: #d1ecf1; color: #0c5460; }
        .note { background: #e8f5e9; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #00a36c; }
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
            <a href="export_credentials.php" class="nav">📧 Экспорт</a>
            <a href="logout.php" class="logout">Выйти</a>
        </div>
    </div>
    <div class="container">
        <div class="card">
            <h2>📧 Экспорт учётных данных для руководителей</h2>
            
            <div class="note">
                <strong>ℹ️ Информация:</strong>
                <ul style="margin-top: 10px; margin-left: 20px;">
                    <li>Пароль по умолчанию для всех новых сотрудников: <strong>123456</strong></li>
                    <li>После первого входа сотрудник сможет сменить пароль в настройках (функция в разработке)</li>
                    <li>CSV файл можно открыть в Excel и отправить руководителям по email</li>
                </ul>
            </div>
            
            <div style="margin-bottom: 20px;">
                <a href="?export=1"><button>📥 Скачать CSV с учётными данными</button></a>
            </div>
            
            <h3>📋 Список всех пользователей</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>Табельный номер</th><th>ФИО</th><th>Роль</th><th>Пароль по умолчанию</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): 
                            $roleClass = '';
                            $roleText = '';
                            if ($user['role'] == 'admin') {
                                $roleClass = 'admin';
                                $roleText = 'Администратор';
                            } elseif ($user['role'] == 'manager') {
                                $roleClass = 'manager';
                                $roleText = 'Руководитель';
                            } else {
                                $roleClass = 'employee';
                                $roleText = 'Сотрудник';
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($user['tabel_number']) ?></td>
                            <td><?= htmlspecialchars($user['full_name']) ?></td>
                            <td><span class="badge <?= $roleClass ?>"><?= $roleText ?></span></td>
                            <td><code>123456</code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
