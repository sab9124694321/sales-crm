<!DOCTYPE html>
<html>
<head>
    <title>Sales CRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; }
        .header { background: #1a2c3e; color: white; padding: 20px; text-align: center; }
        .header h1 { color: #00a36c; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h3 { color: #1a2c3e; margin-bottom: 15px; font-size: 16px; }
        .card .value { font-size: 32px; font-weight: bold; color: #00a36c; }
        .form-card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #555; }
        input, select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; }
        button { background: #00a36c; color: white; border: none; padding: 12px 30px; border-radius: 8px; cursor: pointer; width: 100%; }
        button:hover { background: #008a5a; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
        .warning { background: #fff3cd; color: #856404; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 12px; display: inline-block; }
        .badge.success { background: #d4edda; color: #155724; }
        .badge.warning { background: #fff3cd; color: #856404; }
        .badge.danger { background: #f8d7da; color: #721c24; }
        @media (max-width: 768px) { .stats { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sales CRM</h1>
        <p>Система управления продажами</p>
    </div>
    <div class="container">
        <?php
        $dataFile = __DIR__ . '/reports.json';
        $reports = [];
        if (file_exists($dataFile)) {
            $reports = json_decode(file_get_contents($dataFile), true) ?: [];
        }
        
        // Сохранение отчёта
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $newReport = [
                'date' => date('Y-m-d H:i:s'),
                'calls' => intval($_POST['calls'] ?? 0),
                'calls_answered' => intval($_POST['calls_answered'] ?? 0),
                'meetings' => intval($_POST['meetings'] ?? 0),
                'contracts' => intval($_POST['contracts'] ?? 0),
                'registrations' => intval($_POST['registrations'] ?? 0),
                'smart_cash' => intval($_POST['smart_cash'] ?? 0),
                'pos_systems' => intval($_POST['pos_systems'] ?? 0),
                'inn_leads' => intval($_POST['inn_leads'] ?? 0),
                'teams' => intval($_POST['teams'] ?? 0),
                'turnover' => floatval($_POST['turnover'] ?? 0)
            ];
            array_unshift($reports, $newReport);
            file_put_contents($dataFile, json_encode($reports, JSON_PRETTY_PRINT));
            echo '<div class="success">✅ Отчёт сохранён!</div>';
        }
        
        // Расчёт статистики за сегодня
        $today = date('Y-m-d');
        $todayCalls = 0;
        $todayContracts = 0;
        foreach ($reports as $r) {
            if (strpos($r['date'], $today) === 0) {
                $todayCalls += $r['calls'];
                $todayContracts += $r['contracts'];
            }
        }
        $progress = min(100, round(($todayContracts / 30) * 100));
        ?>
        
        <div class="stats">
            <div class="card"><h3>📞 Звонков сегодня</h3><div class="value"><?= $todayCalls ?></div></div>
            <div class="card"><h3>📄 Договоров сегодня</h3><div class="value"><?= $todayContracts ?></div></div>
            <div class="card"><h3>📈 Выполнение плана</h3><div class="value"><?= $progress ?>%</div></div>
        </div>
        
        <?php if ($progress < 50): ?>
        <div class="card warning" style="border-left: 4px solid #856404;">
            <h3>⚠️ Задание на завтра</h3>
            <p>Ваше отставание от плана. Рекомендуем увеличить активность! Цель на завтра: минимум 5 договоров.</p>
        </div>
        <?php elseif ($progress < 80): ?>
        <div class="card" style="border-left: 4px solid #00a36c;">
            <h3>📋 Задание на завтра</h3>
            <p>Вы в графике. Продолжайте в том же духе! План на завтра: 3 договора.</p>
        </div>
        <?php else: ?>
        <div class="card" style="border-left: 4px solid #00a36c; background: #e8f5e9;">
            <h3>🎉 Отлично!</h3>
            <p>Вы отлично работаете! Так держать!</p>
        </div>
        <?php endif; ?>
        
        <div class="form-card">
            <h3>📝 Ежедневный отчёт</h3>
            <form method="post">
                <div class="form-group"><label>📞 Звонки</label><input type="number" name="calls" required></div>
                <div class="form-group"><label>✅ Дозвоны</label><input type="number" name="calls_answered" required></div>
                <div class="form-group"><label>📅 Встречи</label><input type="number" name="meetings" required></div>
                <div class="form-group"><label>📄 Договоры</label><input type="number" name="contracts" required></div>
                <div class="form-group"><label>📝 Регистрации</label><input type="number" name="registrations" required></div>
                <div class="form-group"><label>💳 Смарт-кассы</label><input type="number" name="smart_cash" required></div>
                <div class="form-group"><label>🖥️ ПОС-системы</label><input type="number" name="pos_systems" required></div>
                <div class="form-group"><label>🔗 ИНН для чаевых</label><input type="number" name="inn_leads" required></div>
                <div class="form-group"><label>👥 Команды на чаевые</label><input type="number" name="teams" required></div>
                <div class="form-group"><label>💰 Оборот чаевых (руб)</label><input type="number" name="turnover" required></div>
                <button type="submit">Сохранить отчёт</button>
            </form>
        </div>
        
        <div class="card">
            <h3>📋 История отчётов</h3>
            <table>
                <thead><tr><th>Дата</th><th>Звонки</th><th>Дозвоны</th><th>Встречи</th><th>Договоры</th><th>Регистрации</th><th>Статус</th></tr></thead>
                <tbody>
                <?php foreach ($reports as $r): 
                    $percent = min(100, round(($r['contracts'] / 30) * 100));
                    $statusClass = $percent >= 70 ? 'success' : ($percent >= 50 ? 'warning' : 'danger');
                    $statusText = $percent >= 100 ? 'Перевыполнение' : ($percent >= 70 ? 'Выполняется' : ($percent >= 50 ? 'Отставание' : 'Критично'));
                ?>
                    <tr>
                        <td><?= $r['date'] ?></td>
                        <td><?= $r['calls'] ?></td>
                        <td><?= $r['calls_answered'] ?></td>
                        <td><?= $r['meetings'] ?></td>
                        <td><?= $r['contracts'] ?></td>
                        <td><?= $r['registrations'] ?></td>
                        <td><span class="badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($reports)): ?>
                <tr><td colspan="7" style="text-align:center">Нет отчётов</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
