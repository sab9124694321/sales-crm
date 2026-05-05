<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];

// Получаем статистику за сегодня
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(registrations, 0) as registrations,
        COALESCE(smart_cash, 0) as smart_cash,
        COALESCE(pos_systems, 0) as pos_systems,
        COALESCE(inn_leads, 0) as inn_leads,
        COALESCE(calls, 0) as calls,
        COALESCE(calls_answered, 0) as calls_answered,
        COALESCE(meetings, 0) as meetings,
        COALESCE(contracts, 0) as contracts
    FROM daily_reports 
    WHERE user_id = ? AND report_date = date('now')
");
$stmt->execute([$user_id]);
$today = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$today) {
    $today = [
        'registrations' => 0, 'smart_cash' => 0, 'pos_systems' => 0, 'inn_leads' => 0,
        'calls' => 0, 'calls_answered' => 0, 'meetings' => 0, 'contracts' => 0
    ];
}

// Получаем статистику за неделю
$stmt = $pdo->prepare("
    SELECT 
        SUM(registrations) as week_reg,
        SUM(smart_cash) as week_smart,
        SUM(pos_systems) as week_pos,
        SUM(inn_leads) as week_inn,
        SUM(calls) as week_calls,
        SUM(calls_answered) as week_answered,
        SUM(meetings) as week_meetings,
        SUM(contracts) as week_contracts,
        COUNT(*) as days_reported
    FROM daily_reports 
    WHERE user_id = ? AND report_date >= date('now', '-7 days')
");
$stmt->execute([$user_id]);
$week = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$week) {
    $week = [
        'week_reg' => 0, 'week_smart' => 0, 'week_pos' => 0, 'week_inn' => 0,
        'week_calls' => 0, 'week_answered' => 0, 'week_meetings' => 0,
        'week_contracts' => 0, 'days_reported' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>📊 Дашборд - Sales CRM</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
            margin-left: 10px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #333;
        }
        .inn-row {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .inn-input {
            flex: 2;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .product-select {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .add-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            cursor: pointer;
        }
        .add-btn:hover { background: #218838; }
        .section {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .section h2 {
            font-size: 18px;
            margin-bottom: 15px;
        }
        .stats-mini {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
        }
        .stat-mini {
            text-align: center;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .stat-mini-value {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }
        .ai-widget {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div><h1>📊 Дашборд</h1><p>Привет, <?= htmlspecialchars($user_name) ?>!</p></div>
        <div class="nav-links">
            <a href="export_inn.php">📊 Выгрузка ИНН</a>
            <a href="logout.php">🚪 Выйти</a>
        </div>
    </div>

    <div class="ai-widget">
        <div style="font-size: 32px;">🤖</div>
        <div><strong>AI Наставник</strong><br>Заполняй отчёты каждый день для получения советов!</div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>📝 Регистрации ТЭ</h3>
            <div class="stat-value" id="val_reg"><?= $today['registrations'] ?></div>
            <div class="inn-row">
                <input type="text" id="inn_reg" class="inn-input" placeholder="ИНН">
                <select id="prod_reg" class="product-select"><option value="ТЭ">ТЭ</option><option value="ЭН">ЭН</option></select>
                <button class="add-btn" onclick="addInn('reg')">+1</button>
            </div>
        </div>
        <div class="stat-card">
            <h3>💳 Smart-кассы</h3>
            <div class="stat-value" id="val_smart"><?= $today['smart_cash'] ?></div>
            <div class="inn-row">
                <input type="text" id="inn_smart" class="inn-input" placeholder="ИНН">
                <select id="prod_smart" class="product-select"><option value="Smart-касса">Smart-касса</option></select>
                <button class="add-btn" onclick="addInn('smart')">+1</button>
            </div>
        </div>
        <div class="stat-card">
            <h3>🖥️ POS-системы</h3>
            <div class="stat-value" id="val_pos"><?= $today['pos_systems'] ?></div>
            <div class="inn-row">
                <input type="text" id="inn_pos" class="inn-input" placeholder="ИНН">
                <select id="prod_pos" class="product-select"><option value="POS-система">POS-система</option></select>
                <button class="add-btn" onclick="addInn('pos')">+1</button>
            </div>
        </div>
        <div class="stat-card">
            <h3>🍵 ИНН по чаевым</h3>
            <div class="stat-value" id="val_inn"><?= $today['inn_leads'] ?></div>
            <div class="inn-row">
                <input type="text" id="inn_tea" class="inn-input" placeholder="ИНН">
                <select id="prod_tea" class="product-select"><option value="Чаевые">Чаевые</option></select>
                <button class="add-btn" onclick="addInn('tea')">+1</button>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>📊 Статистика за сегодня</h2>
        <div class="stats-mini">
            <div class="stat-mini"><div class="stat-mini-value"><?= $today['calls'] ?></div><div>📞 Звонки</div></div>
            <div class="stat-mini"><div class="stat-mini-value"><?= $today['calls_answered'] ?></div><div>✅ Дозвоны</div></div>
            <div class="stat-mini"><div class="stat-mini-value"><?= $today['meetings'] ?></div><div>🤝 Встречи</div></div>
            <div class="stat-mini"><div class="stat-mini-value"><?= $today['contracts'] ?></div><div>📄 Договоры</div></div>
        </div>
    </div>

    <div class="section">
        <h2>📈 Статистика за неделю</h2>
        <div class="stats-mini">
            <div class="stat-mini"><div class="stat-mini-value"><?= $week['week_calls'] ?></div><div>📞 Звонки</div></div>
            <div class="stat-mini"><div class="stat-mini-value"><?= $week['week_answered'] ?></div><div>✅ Дозвоны</div></div>
            <div class="stat-mini"><div class="stat-mini-value"><?= $week['week_meetings'] ?></div><div>🤝 Встречи</div></div>
            <div class="stat-mini"><div class="stat-mini-value"><?= $week['week_contracts'] ?></div><div>📄 Договоры</div></div>
            <div class="stat-mini"><div class="stat-mini-value"><?= $week['week_reg'] ?></div><div>📝 Регистрации ТЭ</div></div>
            <div class="stat-mini"><div class="stat-mini-value"><?= $week['week_smart'] ?></div><div>💳 Smart-кассы</div></div>
            <div class="stat-mini"><div class="stat-mini-value"><?= $week['week_pos'] ?></div><div>🖥️ POS-системы</div></div>
            <div class="stat-mini"><div class="stat-mini-value"><?= $week['week_inn'] ?></div><div>🍵 ИНН по чаевым</div></div>
        </div>
        <div style="text-align: center; margin-top: 15px;">📅 Дней с отчётами: <?= $week['days_reported'] ?> из 7</div>
    </div>
</div>

<script>
function addInn(type) {
    let inn = '', product = '';
    if (type === 'reg') { inn = document.getElementById('inn_reg').value; product = document.getElementById('prod_reg').value; }
    else if (type === 'smart') { inn = document.getElementById('inn_smart').value; product = document.getElementById('prod_smart').value; }
    else if (type === 'pos') { inn = document.getElementById('inn_pos').value; product = document.getElementById('prod_pos').value; }
    else if (type === 'tea') { inn = document.getElementById('inn_tea').value; product = document.getElementById('prod_tea').value; }
    
    if (!inn) { alert('Введите ИНН'); return; }
    
    fetch('/api/add_inn.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ inn: inn, product: product, field_type: type })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            let el = document.getElementById('val_' + (type === 'reg' ? 'reg' : type === 'smart' ? 'smart' : type === 'pos' ? 'pos' : 'inn'));
            if (el) el.innerText = parseInt(el.innerText) + 1;
            if (type === 'reg') document.getElementById('inn_reg').value = '';
            else if (type === 'smart') document.getElementById('inn_smart').value = '';
            else if (type === 'pos') document.getElementById('inn_pos').value = '';
            else if (type === 'tea') document.getElementById('inn_tea').value = '';
            alert('✅ ИНН добавлен!');
        } else { alert('Ошибка: ' + data.error); }
    })
    .catch(e => { alert('Ошибка соединения: ' + e.message); });
}
</script>
</body>
</html>
