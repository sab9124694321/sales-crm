<?php
if (!isset($_SESSION['user_id'])) return;
require_once 'db.php';

$user_id = $_SESSION['user_id'];

// Автосоздание таблиц (если их нет)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS ai_advice_cache (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        advice_key TEXT UNIQUE,
        advice_text TEXT,
        book_recommendation TEXT,
        generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        valid_until DATETIME
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_advice_assignment (
        user_id INTEGER,
        advice_key TEXT,
        assigned_at DATETIME,
        PRIMARY KEY (user_id, advice_key)
    )
");

// Получаем совет для текущего сотрудника
$stmt = $pdo->prepare("
    SELECT ac.advice_text, ac.book_recommendation
    FROM user_advice_assignment ua
    JOIN ai_advice_cache ac ON ua.advice_key = ac.advice_key
    WHERE ua.user_id = ? AND ac.valid_until > datetime('now')
    ORDER BY ua.assigned_at DESC LIMIT 1
");
$stmt->execute([$user_id]);
$advice = $stmt->fetch();

// Если в кеше нет — выдаём общий совет (автономно)
if (!$advice) {
    $advice = [
        'advice_text' => '📈 Заполняйте отчёты ежедневно. Чем больше данных, тем точнее ИИ-советы. Начните с 10 звонков в день!',
        'book_recommendation' => json_encode(['title' => 'Атомные привычки', 'author' => 'Джеймс Клир', 'why' => 'Маленькие шаги к большим результатам'])
    ];
}

$book = json_decode($advice['book_recommendation'], true);
$book_title = $book['title'] ?? 'Атомные привычки';
$book_author = $book['author'] ?? 'Джеймс Клир';
?>

<div style="background: linear-gradient(135deg,#1e293b,#0f172a); border-radius:16px; padding:20px; margin-bottom:20px; color:#e2e8f0;">
    <div style="display:flex; gap:10px; margin-bottom:15px">
        <span style="font-size:24px">🤖</span>
        <strong style="font-size:18px">ИИ-наставник</strong>
    </div>
    <div style="background:#0f172a; border-radius:12px; padding:15px; margin-bottom:15px; border-left:3px solid #3b82f6">
        <?= htmlspecialchars($advice['advice_text']) ?>
    </div>
    <div style="background:#1e293b; border-radius:12px; padding:12px; font-size:13px">
        📖 <strong>Рекомендуемая книга:</strong> <?= htmlspecialchars($book_title) ?> (<?= htmlspecialchars($book_author) ?>)
    </div>
    <div style="font-size:11px; color:#64748b; margin-top:10px">
        ⏰ Обновляется каждую ночь автоматически
    </div>
</div>
