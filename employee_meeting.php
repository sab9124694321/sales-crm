<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['head', 'admin', 'territory_head', 'terman'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$selected_tabel = $_GET['tabel'] ?? '';
$employees = [];
if ($_SESSION['role'] == 'head') {
    $stmt = $pdo->prepare("SELECT id, tabel_number, full_name FROM users WHERE manager_id = ? AND role = 'manager' AND is_active = 1 ORDER BY full_name");
    $stmt->execute([$_SESSION['user_id']]);
    $employees = $stmt->fetchAll();
} elseif ($_SESSION['role'] == 'admin') {
    $employees = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE role = 'manager' AND is_active = 1 ORDER BY full_name")->fetchAll();
} elseif ($_SESSION['role'] == 'territory_head' || $_SESSION['role'] == 'terman') {
    $employees = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE role = 'manager' AND is_active = 1 ORDER BY full_name")->fetchAll();
}

$employee = null;
if ($selected_tabel) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE tabel_number = ?");
    $stmt->execute([$selected_tabel]);
    $employee = $stmt->fetch();
}

$current_month = date('Y-m');
$prev_month = date('Y-m', strtotime('-1 month'));

function getMetrics($pdo, $tabel, $month) {
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ? AND period = ?");
    $stmt->execute([$tabel, $month]);
    $plan = $stmt->fetch();
    if (!$plan) {
        $plan = ['calls_plan'=>350,'calls_answered_plan'=>245,'meetings_plan'=>35,'contracts_plan'=>21,'registrations_plan'=>15,'smart_cash_plan'=>10,'pos_systems_plan'=>5,'inn_leads_plan'=>5,'teams_plan'=>3,'turnover_plan'=>1500000,'rko_plan'=>0];
    }
    $stmt = $pdo->prepare("SELECT 
        COALESCE(SUM(calls),0) calls, COALESCE(SUM(calls_answered),0) calls_answered,
        COALESCE(SUM(meetings),0) meetings, COALESCE(SUM(contracts),0) contracts,
        COALESCE(SUM(registrations),0) registrations, COALESCE(SUM(smart_cash),0) smart_cash,
        COALESCE(SUM(pos_systems),0) pos_systems, COALESCE(SUM(inn_leads),0) inn_leads,
        COALESCE(SUM(teams),0) teams, COALESCE(SUM(turnover),0) turnover,
        COALESCE(SUM(rko),0) rko
        FROM daily_reports WHERE tabel_number = ? AND strftime('%Y-%m', report_date) = ?");
    $stmt->execute([$tabel, $month]);
    $fact = $stmt->fetch();
    if (!$fact) $fact = array_fill_keys(['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover','rko'], 0);
    return ['plan' => $plan, 'fact' => $fact];
}

if ($employee) {
    $current = getMetrics($pdo, $employee['tabel_number'], $current_month);
    $prev = getMetrics($pdo, $employee['tabel_number'], $prev_month);
    
    // Ранги в отделе (по договорам)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(dr.contracts),0) as contracts
        FROM users u
        LEFT JOIN daily_reports dr ON u.tabel_number = dr.tabel_number AND strftime('%Y-%m', dr.report_date) = ?
        WHERE u.manager_id = (SELECT manager_id FROM users WHERE tabel_number = ?)
        GROUP BY u.id
    ");
    $stmt->execute([$current_month, $employee['tabel_number']]);
    $all_contracts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $rank_dept = 1;
    foreach ($all_contracts as $c) {
        if ($c > $current['fact']['contracts']) $rank_dept++;
    }
    $total_dept = count($all_contracts);
    
    // Ранги на территории
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(dr.contracts),0) as contracts
        FROM users u
        LEFT JOIN daily_reports dr ON u.tabel_number = dr.tabel_number AND strftime('%Y-%m', dr.report_date) = ?
        WHERE u.territory_id = (SELECT territory_id FROM users WHERE tabel_number = ?)
        GROUP BY u.id
    ");
    $stmt->execute([$current_month, $employee['tabel_number']]);
    $all_contracts_terr = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $rank_terr = 1;
    foreach ($all_contracts_terr as $c) {
        if ($c > $current['fact']['contracts']) $rank_terr++;
    }
    $total_terr = count($all_contracts_terr);
    
    $diff_contracts = $prev['fact']['contracts'] > 0 ? round(($current['fact']['contracts'] - $prev['fact']['contracts']) / $prev['fact']['contracts'] * 100, 1) : ($current['fact']['contracts'] > 0 ? 100 : 0);
    $diff_turnover = $prev['fact']['turnover'] > 0 ? round(($current['fact']['turnover'] - $prev['fact']['turnover']) / $prev['fact']['turnover'] * 100, 1) : ($current['fact']['turnover'] > 0 ? 100 : 0);
    
    $books = $pdo->prepare("SELECT book_title, read_at FROM employee_book_reads WHERE employee_tabel = ? ORDER BY read_at DESC");
    $books->execute([$employee['tabel_number']]);
    $read_books = $books->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Подготовка к встрече</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container { max-width:1200px; margin:0 auto; padding:20px; }
        .nav { background:linear-gradient(135deg,#1a1a2e,#16213e); color:#fff; padding:12px 20px; border-radius:16px; display:flex; gap:20px; margin-bottom:20px; }
        .nav a { color:#ccc; text-decoration:none; }
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,0.05); }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        .metric { margin-bottom:8px; }
        .metric-label { display:inline-block; width:140px; font-weight:600; }
        .rank-badge { background:#1a73e8; color:#fff; padding:4px 12px; border-radius:20px; display:inline-block; margin-right:10px; }
        .green { color:#2e7d32; }
        .red { color:#d32f2f; }
        .btn { background:#1a73e8; color:#fff; border:none; padding:8px 16px; border-radius:8px; cursor:pointer; }
        .textarea-box { width:100%; min-height:150px; padding:12px; border:1px solid #ccc; border-radius:12px; font-family:monospace; margin-top:8px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php">📊 Дашборд</a>
        <a href="team.php">👥 Команда</a>
        <a href="logout.php" style="margin-left:auto;">🚪 Выйти</a>
    </div>
    <h2>Подготовка к встрече</h2>
    <div class="card">
        <form method="GET" action="employee_meeting.php">
            <select name="tabel" required>
                <option value="">-- Выберите сотрудника --</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= htmlspecialchars($emp['tabel_number']) ?>" <?= $selected_tabel == $emp['tabel_number'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($emp['full_name']) ?> (<?= htmlspecialchars($emp['tabel_number']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">Показать</button>
        </form>
    </div>

    <?php if ($employee): ?>
        <div class="card">
            <h3><?= htmlspecialchars($employee['full_name']) ?></h3>
            <p>Роль: <?= htmlspecialchars($employee['role']) ?> | Таб. номер: <?= htmlspecialchars($employee['tabel_number']) ?></p>
        </div>
        <div class="grid2">
            <div class="card">
                <h3>📊 Результаты за <?= $current_month ?></h3>
                <?php foreach (['calls'=>'Звонки','calls_answered'=>'Дозвоны','meetings'=>'Встречи','contracts'=>'Договоры','registrations'=>'ТЭ','smart_cash'=>'Смарт','pos_systems'=>'ПОС','inn_leads'=>'Чаевые','teams'=>'Команды','turnover'=>'Оборот','rko'=>'РКО'] as $key=>$label):
                    $plan = $current['plan'][$key.'_plan'] ?? 0;
                    $fact = $current['fact'][$key] ?? 0;
                    $percent = $plan>0 ? round($fact/$plan*100) : 0;
                ?>
                <div class="metric"><span class="metric-label"><?= $label ?>:</span> <?= number_format($fact,0,'.',' ') ?> / <?= number_format($plan,0,'.',' ') ?> (<?= $percent ?>%)</div>
                <?php endforeach; ?>
            </div>
            <div class="card">
                <h3>📈 Динамика к <?= $prev_month ?></h3>
                <div class="metric"><span class="metric-label">Договоры:</span> <span class="<?= $diff_contracts>=0?'green':'red' ?>"><?= $diff_contracts>=0?'+':'' ?><?= $diff_contracts ?>%</span></div>
                <div class="metric"><span class="metric-label">Оборот:</span> <span class="<?= $diff_turnover>=0?'green':'red' ?>"><?= $diff_turnover>=0?'+':'' ?><?= $diff_turnover ?>%</span></div>
                <hr>
                <h3>🏆 Рейтинг</h3>
                <div><span class="rank-badge">Отдел: <?= $rank_dept ?> из <?= $total_dept ?></span></div>
                <div><span class="rank-badge">Территория: <?= $rank_terr ?> из <?= $total_terr ?></span></div>
                <hr>
                <h3>🎮 Геймификация</h3>
                <div>Уровень: <?= $employee['level'] ?> | Баллы: <?= number_format($employee['total_points'],0,'.',' ') ?></div>
            </div>
        </div>
        <div class="card">
            <h3>📚 Прочитанные книги</h3>
            <?php if ($read_books): ?>
                <ul>
                <?php foreach ($read_books as $b): ?>
                    <li><?= htmlspecialchars($b['book_title']) ?> (<?= $b['read_at'] ?>)</li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>Нет отмеченных материалов.</p>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>📝 Дополнительная информация о сотруднике (характеристика, 360, заметки)</h3>
            <textarea id="manualText" class="textarea-box" placeholder="Вставьте сюда текст характеристики, результатов 360-градусной оценки, личных заметок о сотруднике..."></textarea>
            <button class="btn" id="refreshAi" style="margin-top:10px;">🔄 Запросить AI (с учётом всех данных)</button>
            <div id="aiSummary" class="ai-summary" style="margin-top:20px; background:#e6f7ff; padding:15px; border-radius:12px; display:none;"></div>
        </div>
    <?php elseif ($selected_tabel): ?>
        <div class="card">Сотрудник не найден.</div>
    <?php endif; ?>
</div>
<script>
document.getElementById('refreshAi')?.addEventListener('click', function() {
    const tabel = '<?= htmlspecialchars($selected_tabel) ?>';
    if (!tabel) { alert('Выберите сотрудника'); return; }
    const manualText = document.getElementById('manualText').value;
    const div = document.getElementById('aiSummary');
    div.style.display = 'block';
    div.innerHTML = '⏳ AI анализирует данные и текст...';
    fetch('api_meeting_summary.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'tabel=' + encodeURIComponent(tabel) + '&manual_text=' + encodeURIComponent(manualText)
    }).then(r => r.json()).then(d => {
        if (d.summary) div.innerHTML = d.summary;
        else if (d.error) div.innerHTML = 'Ошибка: ' + d.error;
        else div.innerHTML = 'Неизвестная ошибка';
    }).catch(e => div.innerHTML = 'Ошибка соединения');
});
</script>
</body>
</html>