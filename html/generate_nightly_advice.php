<?php
require_once __DIR__ . '/db.php';

echo "[" . date('Y-m-d H:i:s') . "] Генерация ИИ-советов\n";

$stmt = $pdo->prepare("
    SELECT 
        u.id,
        AVG(dr.calls) as avg_calls,
        AVG(dr.calls_answered) as avg_answered,
        AVG(dr.meetings) as avg_meetings,
        AVG(dr.contracts) as avg_contracts,
        CASE WHEN AVG(dr.calls) > 0 THEN (AVG(dr.calls_answered) * 100.0 / AVG(dr.calls)) ELSE 0 END as conversion_rate
    FROM users u
    LEFT JOIN daily_reports dr ON u.id = dr.user_id
    WHERE dr.report_date >= date('now', '-7 days') AND u.role != 'admin'
    GROUP BY u.id
");
$stmt->execute();
$stats = $stmt->fetchAll();

if (empty($stats)) { echo "Нет данных\n"; exit; }

$clusters = [
    'low_activity' => ['cond' => fn($s) => ($s['avg_calls'] ?? 0) < 30, 'advice' => '🎯 Задание на неделю: увеличьте количество звонков на 20%. Начните с 10 дополнительных звонков в день!', 'book' => ['title' => 'Атомные привычки', 'author' => 'Джеймс Клир']],
    'low_conversion' => ['cond' => fn($s) => ($s['conversion_rate'] ?? 0) > 0 && ($s['conversion_rate'] ?? 0) < 25, 'advice' => '📞 Техника дня: перед звонком составьте скрипт из 3 вопросов, выявляющих потребности. Превращайте звонки во встречи!', 'book' => ['title' => 'Непробиваемые', 'author' => 'Крис Восс']],
    'low_meetings' => ['cond' => fn($s) => ($s['avg_meetings'] ?? 0) < 2, 'advice' => '🤝 Совет: после звонка сразу предлагайте встречу: "Давайте обсудим это за кофе". Увеличьте количество встреч вдвое!', 'book' => ['title' => 'Как завоёвывать друзей', 'author' => 'Дейл Карнеги']],
    'low_contracts' => ['cond' => fn($s) => ($s['avg_contracts'] ?? 0) == 0 && ($s['avg_meetings'] ?? 0) > 2, 'advice' => '📄 Закрытие сделки: используйте технику "Если... то...": "Если я предложу выгодные условия, вы готовы подписать договор сегодня?"', 'book' => ['title' => 'Переговоры без компромиссов', 'author' => 'Крис Восс']],
    'doing_good' => ['cond' => fn($s) => true, 'advice' => '🌟 Вы на правильном пути! Начните обучать коллег — лучший способ закрепить знания.', 'book' => ['title' => 'Думай и богатей', 'author' => 'Наполеон Хилл']]
];

$pdo->beginTransaction();
$pdo->exec("DELETE FROM ai_advice_cache");
$pdo->exec("DELETE FROM user_advice_assignment");

foreach ($clusters as $key => $c) {
    $members = array_filter($stats, $c['cond']);
    if (empty($members)) continue;
    $user_ids = array_column($members, 'id');
    
    $book_json = json_encode($c['book']);
    $stmt = $pdo->prepare("INSERT INTO ai_advice_cache (advice_key, advice_text, book_recommendation, valid_until) VALUES (?, ?, ?, datetime('now', '+1 day'))");
    $stmt->execute([$key, $c['advice'], $book_json]);
    
    foreach ($user_ids as $uid) {
        $stmt2 = $pdo->prepare("INSERT INTO user_advice_assignment (user_id, advice_key, assigned_at) VALUES (?, ?, datetime('now'))");
        $stmt2->execute([$uid, $key]);
    }
    echo "Кластер $key: " . count($user_ids) . " сотрудников\n";
}
$pdo->commit();
echo "Готово\n";
?>
