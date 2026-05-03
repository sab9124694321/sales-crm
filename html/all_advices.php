<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'manager')) {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$week_start = date('Y-m-d', strtotime('monday this week'));

// Получаем все советы из пула
$stmt = $pdo->prepare("
    SELECT category, advice_text 
    FROM weekly_advice_pool 
    WHERE week_start = ? 
    ORDER BY category, id
");
$stmt->execute([$week_start]);
$advices = $stmt->fetchAll();

// Группируем по категориям
$grouped = [];
foreach ($advices as $a) {
    $grouped[$a['category']][] = $a['advice_text'];
}

$category_names = [
    'low_calls' => '📞 Низкая активность (звонков < 30)',
    'low_conversion' => '🎯 Низкая конверсия (дозвонов < 25%)',
    'low_meetings' => '🤝 Мало встреч (< 2 в неделю)',
    'low_contracts' => '📄 Нет договоров при встречах',
    'high_performance' => '🏆 Высокая эффективность'
];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Все советы ИИ-наставника</title>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; background: #f5f7fb; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #00a36c; margin-bottom: 20px; }
        .card { background: white; border-radius: 16px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h2 { margin-bottom: 15px; font-size: 18px; }
        .advice-list { list-style: none; }
        .advice-list li { padding: 8px 12px; margin: 5px 0; background: #f8f9fa; border-radius: 8px; border-left: 3px solid #00a36c; }
        .back-btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #00a36c; color: white; text-decoration: none; border-radius: 8px; }
        .badge { background: #e9ecef; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 10px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🤖 Все советы ИИ-наставника (из пула GigaChat)</h1>
    <p>Неделя: <strong><?= $week_start ?></strong> | Всего советов: <strong><?= count($advices) ?></strong></p>
    
    <?php foreach ($grouped as $cat => $items): ?>
    <div class="card">
        <h2><?= $category_names[$cat] ?? $cat ?> <span class="badge"><?= count($items) ?> советов</span></h2>
        <ul class="advice-list">
            <?php foreach ($items as $item): ?>
            <li><?= htmlspecialchars($item) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>
    
    <a href="dashboard.php" class="back-btn">← Вернуться на дашборд</a>
</div>
</body>
</html>
