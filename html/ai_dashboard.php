<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';
require_once 'GigaChat.php';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$name = $_SESSION['name'];

// ПРОВЕРКА ДОСТУПА: обычный сотрудник не видит ИИ-дашборд
if ($role == 'employee') {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$AUTH_KEY = 'NzA0OTMxMWYtMTJkNy00OTQ5LWI2MzUtN2ZhYjZiNWRjMzY3OmIyYWJhMDQxLWRiYzgtNGY4ZC1hZDEwLTBhOTY2ZDQ4ZTc3OA==';
$gigachat = new GigaChat($AUTH_KEY);
$answer = '';
$question = $_POST['question'] ?? '';

// Текущий месяц
$current_month = date('Y-m');
$days_in_month = date('t');
$current_day = date('j');
$days_passed = max(1, $current_day);
$days_remaining = max(1, $days_in_month - $current_day + 1);

// ВСЕ ПОКАЗАТЕЛИ из ежедневного отчёта
$all_metrics = [
    'calls' => '📞 Звонки',
    'calls_answered' => '✅ Дозвоны',
    'meetings' => '🤝 Встречи',
    'contracts' => '📄 Договоры',
    'registrations' => '📝 Регистрации',
    'smart_cash' => '💳 smart-кассы',
    'pos_systems' => '🖥️ POS-системы',
    'inn_leads' => '📊 инн по чаевым',
    'teams' => '👥 новые команды по чаевым',
    'turnover' => '💰 оборот по чаевым'
];

// Функция для получения статистики
function getStats($pdo, $user_ids, $metrics, $current_month, $days_passed, $days_remaining) {
    if (empty($user_ids)) {
        $empty_result = ['fact' => [], 'plan' => [], 'forecast' => []];
        foreach (array_keys($metrics) as $metric) {
            $empty_result['fact']["total_$metric"] = 0;
            $empty_result['plan']["plan_$metric"] = 0;
            $empty_result['forecast'][$metric] = 0;
        }
        return $empty_result;
    }
    
    $ids_str = implode(',', $user_ids);
    
    // Факт
    $sql = "SELECT ";
    foreach (array_keys($metrics) as $metric) {
        $sql .= "COALESCE(SUM($metric), 0) as total_$metric, ";
    }
    $sql = rtrim($sql, ', ');
    $sql .= " FROM daily_reports WHERE strftime('%Y-%m', report_date) = '$current_month' AND user_id IN ($ids_str)";
    $stmt = $pdo->query($sql);
    $fact = $stmt->fetch();
    if (!$fact) {
        $fact = [];
        foreach (array_keys($metrics) as $metric) {
            $fact["total_$metric"] = 0;
        }
    }
    
    // Планы
    $sql_plans = "SELECT ";
    foreach (array_keys($metrics) as $metric) {
        $sql_plans .= "COALESCE(SUM(p.{$metric}_plan), 0) as plan_$metric, ";
    }
    $sql_plans = rtrim($sql_plans, ', ');
    $sql_plans .= " FROM plans p JOIN users u ON p.tabel_number = u.tabel_number WHERE u.id IN ($ids_str)";
    $stmt = $pdo->query($sql_plans);
    $plan = $stmt->fetch();
    if (!$plan) {
        $plan = [];
        foreach (array_keys($metrics) as $metric) {
            $plan["plan_$metric"] = 0;
        }
    }
    
    // Прогноз
    $forecast = [];
    foreach (array_keys($metrics) as $metric) {
        $val = $fact["total_$metric"] ?? 0;
        $daily_avg = $days_passed > 1 ? $val / ($days_passed - 1) : 0;
        $forecast[$metric] = round($val + ($daily_avg * $days_remaining));
    }
    
    return ['fact' => $fact, 'plan' => $plan, 'forecast' => $forecast];
}

// ========== ОПРЕДЕЛЯЕМ ДАННЫЕ ==========
$stats = ['fact' => [], 'plan' => [], 'forecast' => []];
$employees = [];
$territories = [];
$daily_stats = [];
$title = "";
$subtitle = "";

if ($role == 'admin') {
    // АДМИН
    $title = "🤖 ИИ-дашборд (Администратор)";
    $subtitle = "Полная аналитика по всей компании";
    
    // Получаем всех сотрудников
    $stmt = $pdo->query("SELECT id FROM users WHERE role != 'admin'");
    $all_user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($all_user_ids)) {
        $stats = getStats($pdo, $all_user_ids, $all_metrics, $current_month, $days_passed, $days_remaining);
    }
    
    // Сотрудники с детализацией
    $stmt = $pdo->query("SELECT id, full_name FROM users WHERE role != 'admin' ORDER BY full_name");
    while ($emp = $stmt->fetch()) {
        $emp_stats = getStats($pdo, [$emp['id']], $all_metrics, $current_month, $days_passed, $days_remaining);
        $employees[] = [
            'id' => $emp['id'],
            'name' => $emp['full_name'],
            'fact' => $emp_stats['fact'],
            'plan' => $emp_stats['plan']
        ];
    }
    
    // Территории
    $stmt = $pdo->query("SELECT id, name FROM territories");
    while ($terr = $stmt->fetch()) {
        $stmt2 = $pdo->prepare("SELECT manager_id FROM territories WHERE id = ?");
        $stmt2->execute([$terr['id']]);
        $manager = $stmt2->fetch();
        $manager_id = $manager['manager_id'] ?? null;
        
        $terr_user_ids = [];
        if ($manager_id) {
            $stmt3 = $pdo->prepare("SELECT id FROM users WHERE manager_id = ? OR id = ?");
            $stmt3->execute([$manager_id, $manager_id]);
            $terr_user_ids = $stmt3->fetchAll(PDO::FETCH_COLUMN);
        }
        
        if (!empty($terr_user_ids)) {
            $terr_stats = getStats($pdo, $terr_user_ids, $all_metrics, $current_month, $days_passed, $days_remaining);
            $territories[] = [
                'name' => $terr['name'],
                'contracts' => $terr_stats['fact']['total_contracts'] ?? 0,
                'turnover' => $terr_stats['fact']['total_turnover'] ?? 0
            ];
        } else {
            $territories[] = [
                'name' => $terr['name'],
                'contracts' => 0,
                'turnover' => 0
            ];
        }
    }
    
    // Дневная динамика
    $stmt = $pdo->prepare("
        SELECT 
            strftime('%d', report_date) as day,
            COALESCE(SUM(contracts), 0) as contracts,
            COALESCE(SUM(calls), 0) as calls,
            COALESCE(SUM(meetings), 0) as meetings
        FROM daily_reports
        WHERE strftime('%Y-%m', report_date) = '$current_month'
        GROUP BY day
        ORDER BY day
    ");
    $stmt->execute();
    $daily_stats = $stmt->fetchAll();
    
} elseif ($role == 'manager') {
    // МЕНЕДЖЕР
    $title = "🤖 ИИ-дашборд (Руководитель)";
    $subtitle = "Аналитика по вашей команде";
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE manager_id = ? OR id = ?");
    $stmt->execute([$user_id, $user_id]);
    $team_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($team_ids)) {
        $stats = getStats($pdo, $team_ids, $all_metrics, $current_month, $days_passed, $days_remaining);
        
        $ids_str = implode(',', $team_ids);
        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id IN ($ids_str) AND role != 'admin' ORDER BY full_name");
        $stmt->execute();
        while ($emp = $stmt->fetch()) {
            $emp_stats = getStats($pdo, [$emp['id']], $all_metrics, $current_month, $days_passed, $days_remaining);
            $employees[] = [
                'id' => $emp['id'],
                'name' => $emp['full_name'],
                'fact' => $emp_stats['fact'],
                'plan' => $emp_stats['plan']
            ];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                strftime('%d', report_date) as day,
                COALESCE(SUM(contracts), 0) as contracts,
                COALESCE(SUM(calls), 0) as calls,
                COALESCE(SUM(meetings), 0) as meetings
            FROM daily_reports
            WHERE strftime('%Y-%m', report_date) = '$current_month' AND user_id IN ($ids_str)
            GROUP BY day
            ORDER BY day
        ");
        $stmt->execute();
        $daily_stats = $stmt->fetchAll();
    }
}

// ========== ФОРМИРУЕМ КОНТЕКСТ С ИМЕНАМИ СОТРУДНИКОВ ==========
$context = "📊 ДАННЫЕ SALES CRM за " . date('F Y') . " (прошло $current_day из $days_in_month дней)\n\n";
$context .= "ОБЩАЯ СТАТИСТИКА:\n";
foreach ($all_metrics as $key => $metric_name) {
    $fact_val = $stats['fact']["total_$key"] ?? 0;
    $plan_val = $stats['plan']["plan_$key"] ?? 0;
    $forecast_val = $stats['forecast'][$key] ?? 0;
    if ($key == 'turnover') {
        $context .= "$metric_name: " . number_format($fact_val, 0, ',', ' ') . " ₽ / " . number_format($plan_val, 0, ',', ' ') . " ₽ → прогноз: " . number_format($forecast_val, 0, ',', ' ') . " ₽\n";
    } else {
        $context .= "$metric_name: $fact_val / $plan_val → прогноз: $forecast_val\n";
    }
}

// Добавляем список сотрудников С ИМЕНАМИ в контекст
$context .= "\n👥 СПИСОК СОТРУДНИКОВ С ПОКАЗАТЕЛЯМИ:\n";
foreach ($employees as $emp) {
    $context .= "• " . $emp['name'] . ":\n";
    $context .= "  - Звонки: " . ($emp['fact']['total_calls'] ?? 0) . " / " . ($emp['plan']['plan_calls'] ?? 0) . "\n";
    $context .= "  - Дозвоны: " . ($emp['fact']['total_calls_answered'] ?? 0) . " / " . ($emp['plan']['plan_calls_answered'] ?? 0) . "\n";
    $context .= "  - Встречи: " . ($emp['fact']['total_meetings'] ?? 0) . " / " . ($emp['plan']['plan_meetings'] ?? 0) . "\n";
    $context .= "  - Договоры: " . ($emp['fact']['total_contracts'] ?? 0) . " / " . ($emp['plan']['plan_contracts'] ?? 0) . "\n";
    $context .= "  - Оборот: " . number_format($emp['fact']['total_turnover'] ?? 0, 0, ',', ' ') . " ₽ / " . number_format($emp['plan']['plan_turnover'] ?? 0, 0, ',', ' ') . " ₽\n";
}

// Формирование ответа ИИ
if ($question) {
    $prompt = "
Ты - ИИ-аналитик Sales CRM. Ответь на вопрос пользователя НА ОСНОВЕ ДАННЫХ НИЖЕ.

$context

Вопрос пользователя: \"$question\"

ПРАВИЛА ОТВЕТА:
1. Обязательно указывай ФИО сотрудников, когда отвечаешь на вопросы о них
2. Используй ТОЛЬКО данные из контекста
3. Отвечай на русском, будь конкретным, используй цифры
4. Отвечай кратко (3-5 предложений)

ОТВЕТ:
";
    $answer = $gigachat->generateAdvice($prompt);
    if (!$answer) {
        $answer = "❌ Не удалось получить ответ от GigaChat. Попробуйте позже.";
    }
}

// Данные для графиков
$chart_days = [];
$chart_contracts = [];
$chart_calls = [];
$chart_meetings = [];
foreach ($daily_stats as $d) {
    $chart_days[] = $d['day'];
    $chart_contracts[] = $d['contracts'];
    $chart_calls[] = $d['calls'];
    $chart_meetings[] = $d['meetings'];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>ИИ-дашборд - Sales CRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f5f7fb; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #00a36c; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .subtitle { color: #666; margin-bottom: 20px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 16px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card .value { font-size: 24px; font-weight: bold; color: #00a36c; }
        .stat-card .label { font-size: 13px; color: #666; margin-top: 5px; }
        .stat-card .plan { font-size: 11px; color: #999; margin-top: 4px; }
        .stat-card .forecast { font-size: 11px; color: #f59e0b; margin-top: 4px; }
        .progress-bar { width: 100%; background: #e5e7eb; border-radius: 10px; height: 6px; margin-top: 8px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 10px; }
        
        .card { background: white; border-radius: 16px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h2 { margin-bottom: 15px; font-size: 18px; border-left: 3px solid #00a36c; padding-left: 12px; }
        
        .ai-chat { background: linear-gradient(135deg, #1a2c3e 0%, #2d4a6e 100%); color: white; }
        .ai-chat .question-input { width: 100%; padding: 15px; border: none; border-radius: 12px; font-size: 16px; margin-bottom: 15px; }
        .ai-chat .ask-btn { background: #00a36c; color: white; border: none; padding: 12px 24px; border-radius: 12px; cursor: pointer; font-size: 16px; }
        .ai-chat .answer { background: rgba(255,255,255,0.1); border-radius: 12px; padding: 20px; margin-top: 20px; line-height: 1.6; white-space: pre-wrap; }
        
        .employee-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .employee-table th, .employee-table td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        .employee-table th { background: #f8f9fa; font-weight: 600; }
        
        .two-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .two-columns { grid-template-columns: 1fr; } }
        
        canvas { max-height: 280px; }
        .badge { padding: 2px 8px; border-radius: 12px; font-size: 11px; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .scroll-table { overflow-x: auto; max-height: 500px; overflow-y: auto; }
        .territory-list li { padding: 8px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    </style>
</head>
<body>
<div class="container">
    <?php require_once 'navbar.php'; ?>
    
    <h1>🤖 ИИ-дашборд <span style="font-size: 14px; background: #e8f5e9; color: #00a36c; padding: 4px 12px; border-radius: 20px;">GigaChat AI</span></h1>
    <div class="subtitle"><?= $subtitle ?></div>
    
    <!-- ИИ-чат -->
    <div class="card ai-chat">
        <h2 style="color: white; border-left-color: white;">🤖 Спросите ИИ-аналитика</h2>
        <form method="post">
            <input type="text" name="question" class="question-input" 
                   placeholder="Например: какой сотрудник лучший? какой прогноз? кто отстаёт?" 
                   value="<?= htmlspecialchars($question) ?>">
            <button type="submit" class="ask-btn">🚀 Спросить ИИ</button>
        </form>
        
        <?php if ($answer): ?>
        <div class="answer">
            <strong>🤖 GigaChat отвечает:</strong><br><br>
            <?= nl2br(htmlspecialchars($answer)) ?>
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 15px; font-size: 12px; opacity: 0.7;">
            💡 Примеры: "Кто лучший по договорам?", "Сравни Иванова и Петрова", "Кто отстаёт по плану?"
        </div>
    </div>
    
    <!-- Статистика -->
    <div class="stats-grid">
        <?php foreach ($all_metrics as $key => $metric_name): 
            $fact_val = $stats['fact']["total_$key"] ?? 0;
            $plan_val = $stats['plan']["plan_$key"] ?? 0;
            $forecast_val = $stats['forecast'][$key] ?? 0;
            $percent = $plan_val > 0 ? round(($fact_val / $plan_val) * 100) : 0;
            $color = $percent >= 80 ? '#00a36c' : ($percent >= 50 ? '#f59e0b' : '#ef4444');
        ?>
        <div class="stat-card">
            <div class="value"><?= $key == 'turnover' ? number_format($fact_val, 0, ',', ' ') . ' ₽' : number_format($fact_val) ?></div>
            <div class="label"><?= $metric_name ?></div>
            <div class="plan">план: <?= $key == 'turnover' ? number_format($plan_val, 0, ',', ' ') . ' ₽' : number_format($plan_val) ?></div>
            <div class="forecast">📈 прогноз: <?= $key == 'turnover' ? number_format($forecast_val, 0, ',', ' ') . ' ₽' : number_format($forecast_val) ?></div>
            <div class="progress-bar"><div class="progress-fill" style="width: <?= $percent ?>%; background: <?= $color ?>"></div></div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Графики -->
    <div class="two-columns">
        <div class="card"><h2>📈 Договоры</h2><canvas id="contractsChart"></canvas></div>
        <div class="card"><h2>📞 Звонки и встречи</h2><canvas id="callsMeetingsChart"></canvas></div>
    </div>
    
    <!-- Таблица сотрудников -->
    <div class="card">
        <h2>👥 Сотрудники</h2>
        <div class="scroll-table">
            <table class="employee-table">
                <thead><tr><th>Сотрудник</th><th>📞 Звонки</th><th>✅ Дозвоны</th><th>🤝 Встречи</th><th>📄 Договоры</th><th>💳 smart-кассы</th><th>👥 команды</th><th>💰 оборот</th><th>%</th></tr></thead>
                <tbody>
                    <?php foreach ($employees as $emp): 
                        $contracts_plan = $emp['plan']['plan_contracts'] ?? 1;
                        $contracts_fact = $emp['fact']['total_contracts'] ?? 0;
                        $contracts_percent = $contracts_plan > 0 ? round(($contracts_fact / $contracts_plan) * 100) : 0;
                        $badge_class = $contracts_percent >= 80 ? 'badge-success' : ($contracts_percent >= 50 ? 'badge-warning' : 'badge-danger');
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($emp['name'] ?? '') ?></strong></td>
                        <td><?= $emp['fact']['total_calls'] ?? 0 ?> / <?= $emp['plan']['plan_calls'] ?? 0 ?></td>
                        <td><?= $emp['fact']['total_calls_answered'] ?? 0 ?> / <?= $emp['plan']['plan_calls_answered'] ?? 0 ?></td>
                        <td><?= $emp['fact']['total_meetings'] ?? 0 ?> / <?= $emp['plan']['plan_meetings'] ?? 0 ?></td>
                        <td><?= $emp['fact']['total_contracts'] ?? 0 ?> / <?= $emp['plan']['plan_contracts'] ?? 0 ?></td>
                        <td><?= $emp['fact']['total_smart_cash'] ?? 0 ?> / <?= $emp['plan']['plan_smart_cash'] ?? 0 ?></td>
                        <td><?= $emp['fact']['total_teams'] ?? 0 ?> / <?= $emp['plan']['plan_teams'] ?? 0 ?></td>
                        <td><?= number_format($emp['fact']['total_turnover'] ?? 0, 0, ',', ' ') ?> ₽</span></td>
                        <td><div class="progress-bar" style="width: 50px; display: inline-block; margin-right: 5px;"><div class="progress-fill" style="width: <?= $contracts_percent ?>%; background: <?= $color ?? '#00a36c' ?>"></div></div><span class="badge <?= $badge_class ?>"><?= $contracts_percent ?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php if (!empty($territories)): ?>
    <div class="card">
        <h2>🗺️ Территории</h2>
        <ul class="territory-list">
            <?php foreach ($territories as $terr): ?>
            <li><strong><?= htmlspecialchars($terr['name'] ?? '') ?></strong> <span>📄 <?= $terr['contracts'] ?? 0 ?> договоров</span> <span>💰 <?= number_format($terr['turnover'] ?? 0, 0, ',', ' ') ?> ₽</span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>

<script>
new Chart(document.getElementById('contractsChart'), {
    type: 'line',
    data: { labels: <?= json_encode($chart_days) ?>, datasets: [{ label: 'Договоры', data: <?= json_encode($chart_contracts) ?>, borderColor: '#00a36c', fill: true, tension: 0.3 }] },
    options: { responsive: true, maintainAspectRatio: true }
});
new Chart(document.getElementById('callsMeetingsChart'), {
    type: 'line',
    data: { labels: <?= json_encode($chart_days) ?>, datasets: [{ label: 'Звонки', data: <?= json_encode($chart_calls) ?>, borderColor: '#3b82f6', fill: true }, { label: 'Встречи', data: <?= json_encode($chart_meetings) ?>, borderColor: '#f59e0b', fill: true }] },
    options: { responsive: true, maintainAspectRatio: true }
});
</script>
</body>
</html>
