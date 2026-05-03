<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'manager')) {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$week_start = date('Y-m-d', strtotime('monday this week'));
$categories = [
    'low_calls' => '📞 Низкая активность (звонков < 30)',
    'low_conversion' => '🎯 Низкая конверсия (дозвонов < 25%)',
    'low_meetings' => '🤝 Мало встреч (< 2 в неделю)',
    'low_contracts' => '📄 Нет договоров при встречах',
    'high_performance' => '🏆 Высокая эффективность'
];

$selected_cat = $_GET['cat'] ?? 'all';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Пул ИИ-советов от GigaChat</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; padding: 20px; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #00a36c; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
        .subtitle { color: #94a3b8; margin-bottom: 30px; border-left: 3px solid #00a36c; padding-left: 15px; }
        .stats { background: #1e293b; border-radius: 12px; padding: 15px 20px; margin-bottom: 20px; display: flex; gap: 20px; flex-wrap: wrap; }
        .stat { background: #0f172a; border-radius: 10px; padding: 10px 20px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; color: #00a36c; }
        .stat-label { font-size: 12px; color: #94a3b8; }
        .categories { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 30px; }
        .cat-btn { background: #334155; color: white; border: none; padding: 10px 20px; border-radius: 40px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; transition: all 0.2s; }
        .cat-btn:hover { background: #475569; transform: translateY(-2px); }
        .cat-btn.active { background: #00a36c; }
        .refresh-btn { background: #f59e0b; color: white; border: none; padding: 10px 24px; border-radius: 40px; cursor: pointer; font-size: 14px; margin-left: auto; text-decoration: none; display: inline-block; }
        .advices-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 16px; margin-top: 20px; }
        .advice-card { background: #1e293b; border-radius: 16px; padding: 20px; border-left: 4px solid #00a36c; transition: transform 0.2s; }
        .advice-card:hover { transform: translateX(5px); background: #2d3a4e; }
        .advice-category { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .advice-text { font-size: 14px; line-height: 1.5; color: #e2e8f0; }
        .empty-state { text-align: center; padding: 60px; color: #64748b; }
        .generate-btn { background: #00a36c; color: white; border: none; padding: 12px 30px; border-radius: 40px; font-size: 16px; cursor: pointer; margin-top: 20px; text-decoration: none; display: inline-block; }
        .generate-btn:hover { background: #008a5c; }
        .back-link { display: inline-block; margin-top: 30px; color: #00a36c; text-decoration: none; }
        .info { background: #1e293b; border-radius: 12px; padding: 12px 20px; margin-bottom: 20px; font-size: 13px; color: #94a3b8; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🧠 Пул ИИ-советов от GigaChat</h1>
    <div class="subtitle">Советы сгенерированы нейросетью Сбера • Неделя: <?= date('d.m.Y', strtotime($week_start)) ?></div>
    
    <?php
    // Подсчитываем статистику
    $stats = [];
    $total = 0;
    foreach ($categories as $key => $name) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM weekly_advice_pool WHERE category = ? AND week_start = ?");
        $stmt->execute([$key, $week_start]);
        $cnt = $stmt->fetchColumn();
        $stats[$key] = $cnt;
        $total += $cnt;
    }
    ?>
    
    <div class="stats">
        <div class="stat"><div class="stat-number"><?= $total ?></div><div class="stat-label">Всего советов</div></div>
        <?php foreach ($categories as $key => $name): ?>
            <div class="stat"><div class="stat-number"><?= $stats[$key] ?></div><div class="stat-label"><?= explode(' ', $name)[0] ?></div></div>
        <?php endforeach; ?>
    </div>
    
    <div class="categories">
        <a href="?cat=all" class="cat-btn <?= $selected_cat == 'all' ? 'active' : '' ?>">📋 Все категории</a>
        <?php foreach ($categories as $key => $name): ?>
            <a href="?cat=<?= $key ?>" class="cat-btn <?= $selected_cat == $key ? 'active' : '' ?>"><?= $name ?></a>
        <?php endforeach; ?>
        <a href="generate_weekly_advices.php" class="refresh-btn" onclick="return confirm('Сгенерировать новые советы? Это может занять минуту.')">🔄 Сгенерировать новые советы</a>
    </div>
    
    <div class="info">
        <span>🤖 Модель: GigaChat (Сбер)</span>
        <span>⚡ Советы обновляются раз в неделю и случайно распределяются между сотрудниками</span>
    </div>
    
    <?php if ($total == 0): ?>
        <div class="empty-state">
            <p>📭 Пула советов пока нет</p>
            <a href="generate_weekly_advices.php" class="generate-btn">📦 Сгенерировать пул советов</a>
        </div>
    <?php else: ?>
        <div class="advices-grid">
            <?php
            $sql = "SELECT category, advice_text FROM weekly_advice_pool WHERE week_start = ?";
            if ($selected_cat != 'all') {
                $sql .= " AND category = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$week_start, $selected_cat]);
            } else {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$week_start]);
            }
            
            while ($row = $stmt->fetch()):
                $cat_name = $categories[$row['category']] ?? $row['category'];
            ?>
            <div class="advice-card">
                <div class="advice-category"><?= htmlspecialchars($cat_name) ?></div>
                <div class="advice-text"><?= htmlspecialchars($row['advice_text']) ?></div>
            </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
    
    <a href="dashboard.php" class="back-link">← Вернуться на дашборд</a>
</div>
</body>
</html>
