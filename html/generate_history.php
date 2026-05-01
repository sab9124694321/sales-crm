<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$message = '';
$error = '';

// Параметры генерации
$months_back = 3; // за 3 месяца
$days_in_month = 30;

// Удаляем старые данные (оставляем только последние 7 дней)
$keep_days = 7;
$pdo->exec("DELETE FROM daily_reports WHERE report_date < date('now', '-$keep_days days')");
$message .= "Удалены старые отчёты. ";

// Получаем всех сотрудников (employee)
$employees = $pdo->query("SELECT id FROM users WHERE role = 'employee'")->fetchAll();

if (empty($employees)) {
    $error = "Нет сотрудников! Сначала добавьте сотрудников через импорт или админку.";
} else {
    $generated = 0;
    
    // Генерация данных за каждый из последних 3 месяцев
    for ($m = $months_back; $m >= 0; $m--) {
        $date = date('Y-m-d', strtotime("-$m months"));
        $year = date('Y', strtotime($date));
        $month = date('m', strtotime($date));
        $days_in_this_month = date('t', strtotime($date));
        
        // Получаем планы для каждого сотрудника (если есть)
        $plans = [];
        $stmt = $pdo->prepare("SELECT user_id, plan_calls, plan_answered, plan_meetings, plan_contracts, plan_registrations, plan_smart_cash, plan_pos_systems, plan_inn_leads, plan_teams, plan_turnover FROM monthly_plans WHERE year = ? AND month = ?");
        $stmt->execute([$year, $month]);
        foreach ($stmt->fetchAll() as $p) {
            $plans[$p['user_id']] = $p;
        }
        
        // Для каждого дня месяца
        for ($day = 1; $day <= $days_in_this_month; $day++) {
            $report_date = sprintf("%04d-%02d-%02d", $year, $month, $day);
            
            // Пропускаем будущие даты
            if ($report_date > date('Y-m-d')) continue;
            
            foreach ($employees as $emp) {
                $emp_id = $emp['id'];
                
                // Берём планы для этого сотрудника (если нет - дефолтные)
                $plan = $plans[$emp_id] ?? null;
                
                // Дефолтные планы на месяц (если не заданы)
                $plan_calls = $plan ? $plan['plan_calls'] / $days_in_this_month : 25;
                $plan_answered = $plan ? $plan['plan_answered'] / $days_in_this_month : 12;
                $plan_meetings = $plan ? $plan['plan_meetings'] / $days_in_this_month : 2;
                $plan_contracts = $plan ? $plan['plan_contracts'] / $days_in_this_month : 1.5;
                $plan_registrations = $plan ? $plan['plan_registrations'] / $days_in_this_month : 2;
                $plan_smart_cash = $plan ? $plan['plan_smart_cash'] / $days_in_this_month : 0.3;
                $plan_pos_systems = $plan ? $plan['plan_pos_systems'] / $days_in_this_month : 0.03;
                $plan_inn_leads = $plan ? $plan['plan_inn_leads'] / $days_in_this_month : 1.5;
                $plan_teams = $plan ? $plan['plan_teams'] / $days_in_this_month : 0.15;
                $plan_turnover = $plan ? $plan['plan_turnover'] / $days_in_this_month : 6000;
                
                // Случайное выполнение с нормальным распределением
                // Для разных дней разная продуктивность (пн-пт выше, выходные ниже)
                $day_of_week = date('N', strtotime($report_date));
                $is_weekend = ($day_of_week >= 6);
                $base_factor = $is_weekend ? 0.5 : 1.2;
                
                // Случайный фактор от 0.6 до 1.4
                $random_factor = 0.7 + (rand(0, 70) / 100);
                
                // Некоторые дни могут быть очень хорошими или плохими
                $lucky_factor = (rand(1, 10) == 1) ? 1.5 : ((rand(1, 20) == 1) ? 0.5 : 1);
                
                $final_factor = $base_factor * $random_factor * $lucky_factor;
                
                // Генерируем показатели
                $calls = max(0, round($plan_calls * $final_factor));
                $calls_answered = max(0, round($plan_answered * $final_factor * (0.5 + rand(0, 100) / 100)));
                $meetings = max(0, round($plan_meetings * $final_factor * (0.6 + rand(0, 80) / 100)));
                $contracts = max(0, round($plan_contracts * $final_factor * (0.5 + rand(0, 100) / 100)));
                $registrations = max(0, round($plan_registrations * $final_factor * (0.7 + rand(0, 60) / 100)));
                $smart_cash = rand(0, 2);
                $pos_systems = rand(0, 1);
                $inn_leads = max(0, round($plan_inn_leads * $final_factor * (0.5 + rand(0, 100) / 100)));
                $teams = rand(0, 1);
                $turnover = max(0, round($plan_turnover * $final_factor * (0.5 + rand(0, 100) / 100) / 100) * 100);
                
                // Сохраняем в БД
                try {
                    $stmt = $pdo->prepare("INSERT OR REPLACE INTO daily_reports (user_id, report_date, calls, calls_answered, meetings, contracts, registrations, smart_cash, pos_systems, inn_leads, teams, turnover) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$emp_id, $report_date, $calls, $calls_answered, $meetings, $contracts, $registrations, $smart_cash, $pos_systems, $inn_leads, $teams, $turnover]);
                    $generated++;
                } catch (PDOException $e) {
                    // Ошибка уникальности - пропускаем
                }
            }
        }
    }
    
    $message .= "Сгенерировано $generated записей за последние $months_back месяцев.";
}

// Подсчёт статистики после генерации
$totalReports = $pdo->query("SELECT COUNT(*) FROM daily_reports")->fetchColumn();
$uniqueEmployees = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM daily_reports")->fetchColumn();
$minDate = $pdo->query("SELECT MIN(report_date) FROM daily_reports")->fetchColumn();
$maxDate = $pdo->query("SELECT MAX(report_date) FROM daily_reports")->fetchColumn();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Генерация исторических данных</title>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 50px; text-align: center; }
        .success { background: #d4edda; color: #155724; padding: 20px; border-radius: 12px; display: inline-block; max-width: 600px; text-align: left; }
        .error { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 12px; display: inline-block; }
        a { color: #00a36c; }
        ul { margin-top: 15px; }
    </style>
</head>
<body>
    <?php if ($error): ?>
    <div class="error">
        <h2>❌ Ошибка</h2>
        <p><?= htmlspecialchars($error) ?></p>
        <p><a href="admin.php">В админ-панель</a></p>
    </div>
    <?php else: ?>
    <div class="success">
        <h2>✅ Исторические данные сгенерированы!</h2>
        <ul>
            <li>📊 Всего записей: <?= $totalReports ?></li>
            <li>👥 Сотрудников с отчётами: <?= $uniqueEmployees ?></li>
            <li>📅 Период: с <?= $minDate ?> по <?= $maxDate ?></li>
            <li>📅 За последние <?= $months_back ?> месяцев</li>
        </ul>
        <p><a href="region_manager.php">🗺️ Перейти на страницу территориального менеджера</a></p>
        <p><a href="team.php">👥 Перейти на страницу команды</a></p>
        <p><a href="dashboard.php">📊 Перейти на главный дашборд</a></p>
    </div>
    <?php endif; ?>
</body>
</html>
