<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$message = '';

// Очищаем существующие данные
$pdo->exec("DELETE FROM daily_reports");
$pdo->exec("DELETE FROM monthly_plans");
$pdo->exec("DELETE FROM users WHERE role != 'admin'");

// Команды и их руководители
$teams = [
    1 => ['name' => 'Северная', 'manager_tabel' => '1001', 'manager_name' => 'Соколов Дмитрий Петрович', 'manager_phone' => '+79125556677'],
    2 => ['name' => 'Южная', 'manager_tabel' => '2001', 'manager_name' => 'Волкова Екатерина Андреевна', 'manager_phone' => '+79125558899'],
    3 => ['name' => 'Центральная', 'manager_tabel' => '3001', 'manager_name' => 'Кузнецов Алексей Викторович', 'manager_phone' => '+79125551122'],
    4 => ['name' => 'Западная', 'manager_tabel' => '4001', 'manager_name' => 'Морозова Ольга Сергеевна', 'manager_phone' => '+79125553344'],
    5 => ['name' => 'Восточная', 'manager_tabel' => '5001', 'manager_name' => 'Новиков Игорь Владимирович', 'manager_phone' => '+79125555566']
];

// Фамилии для генерации
$lastNames = ['Иванов', 'Петров', 'Сидоров', 'Козлов', 'Морозов', 'Волков', 'Соколов', 'Лебедев', 'Новиков', 'Кузнецов', 'Попов', 'Васильев', 'Павлов', 'Семёнов', 'Голубев', 'Виноградов', 'Богданов', 'Воробьёв', 'Фёдоров', 'Михайлов'];
$firstNames = ['Александр', 'Дмитрий', 'Максим', 'Сергей', 'Андрей', 'Алексей', 'Артём', 'Илья', 'Кирилл', 'Михаил', 'Екатерина', 'Анна', 'Мария', 'Ольга', 'Татьяна', 'Наталья', 'Ирина', 'Елена', 'Светлана', 'Юлия'];

// Генерация сотрудников по командам
$employeeCount = 0;
$managers = [];

foreach ($teams as $teamId => $team) {
    // Создаём руководителя команды
    $stmt = $pdo->prepare("INSERT INTO users (tabel_number, full_name, phone, role, manager_id, password) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $team['manager_tabel'],
        $team['manager_name'],
        $team['manager_phone'],
        'manager',
        null,
        password_hash('123456', PASSWORD_DEFAULT)
    ]);
    $managerId = $pdo->lastInsertId();
    $managers[$team['manager_tabel']] = $managerId;
    
    // Создаём 12 сотрудников в команде
    for ($i = 1; $i <= 12; $i++) {
        $num = ($teamId - 1) * 12 + $i;
        $tabel = '2' . sprintf("%04d", 1000 + $num);
        $fullName = $lastNames[array_rand($lastNames)] . ' ' . $firstNames[array_rand($firstNames)];
        $phone = '+7912' . rand(1000000, 9999999);
        
        $stmt = $pdo->prepare("INSERT INTO users (tabel_number, full_name, phone, role, manager_id, password) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tabel, $fullName, $phone, 'employee', $managerId, password_hash('123456', PASSWORD_DEFAULT)]);
        $employeeCount++;
    }
}

$message .= "Создано 5 руководителей и $employeeCount сотрудников. ";

// Устанавливаем планы на текущий месяц
$today = date('Y-m-d');
$year = date('Y');
$month = date('m');

// Планы на месяц для каждого сотрудника
$plan_calls = 900;  // 30 звонков в день * 30 дней
$plan_answered = 450; // 15 дозвонов в день
$plan_meetings = 90; // 3 встречи в день
$plan_contracts = 60; // 2 договора в день
$plan_registrations = 90; // 3 регистрации в день
$plan_smart_cash = 10; // 10 смарт-касс в месяц
$plan_pos_systems = 1; // 1 ПОС система в месяц
$plan_inn_leads = 60; // 2 ИНН в день
$plan_teams = 5; // 5 команд чаевых в месяц
$plan_turnover = 220000; // 220 тыс. руб. оборот чаевых

// Получаем всех сотрудников
$employees = $pdo->query("SELECT id FROM users WHERE role = 'employee'")->fetchAll();

foreach ($employees as $emp) {
    $stmt = $pdo->prepare("INSERT INTO monthly_plans (user_id, year, month, plan_calls, plan_answered, plan_meetings, plan_contracts, plan_registrations, plan_smart_cash, plan_pos_systems, plan_inn_leads, plan_teams, plan_turnover) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$emp['id'], $year, $month, $plan_calls, $plan_answered, $plan_meetings, $plan_contracts, $plan_registrations, $plan_smart_cash, $plan_pos_systems, $plan_inn_leads, $plan_teams, $plan_turnover]);
}
$message .= "Установлены планы на месяц. ";

// Генерируем отчёты за последние 30 дней
$days = 30;
for ($d = $days - 1; $d >= 0; $d--) {
    $date = date('Y-m-d', strtotime("-$d days"));
    
    foreach ($employees as $emp) {
        // Генерируем случайные показатели с отклонением ±20%
        $calls = round($plan_calls / 30 * (0.8 + rand(0, 40) / 100));
        $answered = round($plan_answered / 30 * (0.7 + rand(0, 50) / 100));
        $meetings = round($plan_meetings / 30 * (0.6 + rand(0, 60) / 100));
        $contracts = round($plan_contracts / 30 * (0.5 + rand(0, 80) / 100));
        $registrations = round($plan_registrations / 30 * (0.6 + rand(0, 60) / 100));
        $smart_cash = rand(0, 2);
        $pos_systems = rand(0, 1);
        $inn_leads = round($plan_inn_leads / 30 * (0.5 + rand(0, 80) / 100));
        $teams = rand(0, 1);
        $turnover = round($plan_turnover / 30 * (0.5 + rand(0, 80) / 100));
        
        $stmt = $pdo->prepare("INSERT INTO daily_reports (user_id, report_date, calls, calls_answered, meetings, contracts, registrations, smart_cash, pos_systems, inn_leads, teams, turnover) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$emp['id'], $date, $calls, $answered, $meetings, $contracts, $registrations, $smart_cash, $pos_systems, $inn_leads, $teams, $turnover]);
    }
}
$message .= "Сгенерированы отчёты за $days дней. ";

// Получение статистики после генерации
$totalEmployees = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'employee'")->fetchColumn();
$totalManagers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'manager'")->fetchColumn();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Загрузка демо-данных</title>
    <meta charset='utf-8'>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 50px; text-align: center; }
        .success { background: #d4edda; color: #155724; padding: 20px; border-radius: 12px; display: inline-block; }
        a { color: #00a36c; }
    </style>
</head>
<body>
    <div class='success'>
        <h2>✅ Демо-данные успешно загружены!</h2>
        <p>Руководителей: $totalManagers</p>
        <p>Сотрудников: $totalEmployees</p>
        <p>Отчётов создано: " . ($days * $totalEmployees) . "</p>
        <p><a href='region_manager.php'>📊 Перейти на страницу территориального менеджера</a></p>
        <p><a href='team.php'>👥 Перейти на страницу команды</a></p>
        <p><a href='dashboard.php'>📊 Перейти на главный дашборд</a></p>
    </div>
</body>
</html>";
?>
