<?php
session_start();
require_once 'db.php';

$hunters = $pdo->query("SELECT full_name, points, level FROM hunters ORDER BY points DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Рейтинг охотников</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f0f4f0; padding: 16px; }
        .container { max-width: 600px; margin: 0 auto; }
        .card { background: #fff; border-radius: 24px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
        h1 { font-size: 24px; margin-bottom: 16px; color: #1a3b1a; }
        .hunter-item { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #e8f0e8; }
        .hunter-item:last-child { border-bottom: none; }
        .rank { font-weight: 700; color: #2a6a2a; width: 30px; }
        .name { flex: 1; }
        .points { font-weight: 600; color: #1a5e1a; }
        .back { display: block; margin-top: 20px; text-align: center; color: #1a6e1a; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>🏆 Рейтинг охотников</h1>
        <?php $i = 1; foreach ($hunters as $h): ?>
            <div class="hunter-item">
                <span class="rank">#<?= $i++ ?></span>
                <span class="name"><?= htmlspecialchars($h['full_name']) ?></span>
                <span class="points"><?= $h['points'] ?> ⭐</span>
                <span style="color: #888; font-size: 12px;"><?= htmlspecialchars($h['level']) ?></span>
            </div>
        <?php endforeach; ?>
        <a href="hunter_dashboard.php" class="back">← На главную</a>
    </div>
</div>
</body>
</html>
