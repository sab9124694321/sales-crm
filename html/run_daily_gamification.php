<?php
require_once __DIR__ . '/db.php';

$yesterday = date('Y-m-d', strtotime('-1 day'));
$log_file = '/tmp/gamification_' . date('Y-m-d') . '.log';

file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Запуск анализа за $yesterday\n", FILE_APPEND);

// Получаем отчёты за вчера
$stmt = $pdo->prepare("
    SELECT dr.*, u.full_name, u.id as user_id, u.role 
    FROM daily_reports dr
    JOIN users u ON dr.user_id = u.id
    WHERE dr.report_date = ?
");
$stmt->execute([$yesterday]);
$reports = $stmt->fetchAll();

if (empty($reports)) {
    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Нет отчётов за $yesterday\n", FILE_APPEND);
    exit;
}

file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Найдено " . count($reports) . " отчётов\n", FILE_APPEND);

// Очищаем старые уведомления (старше 7 дней)
$pdo->exec("DELETE FROM game_notifications WHERE created_at < date('now', '-7 days')");

$RANKS = [
    'Новичок' => 0, 'Стажёр' => 100, 'Специалист' => 500,
    'Профи' => 1000, 'Эксперт' => 2000, 'Легенда' => 5000
];

$METRICS = [
    'calls' => ['name' => 'звонкам', 'unit' => 'зв.'],
    'calls_answered' => ['name' => 'дозвонам', 'unit' => 'доз.'],
    'meetings' => ['name' => 'встречам', 'unit' => 'встр.'],
    'contracts' => ['name' => 'договорам', 'unit' => 'дог.'],
    'registrations' => ['name' => 'регистрациям', 'unit' => 'рег.'],
    'turnover' => ['name' => 'обороту', 'unit' => 'руб.']
];

// Функция создания уведомления
function addNotification($pdo, $user_id, $type, $message) {
    $stmt = $pdo->prepare("INSERT INTO game_notifications (user_id, type, message, created_at) VALUES (?, ?, ?, datetime('now'))");
    $stmt->execute([$user_id, $type, $message]);
}

// Анализ по каждой метрике
foreach ($METRICS as $key => $metric) {
    // Сортируем по значению
    usort($reports, function($a, $b) use ($key) {
        return ($b[$key] ?? 0) <=> ($a[$key] ?? 0);
    });
    
    $leader_value = $reports[0][$key] ?? 0;
    if ($leader_value == 0) continue;
    
    foreach ($reports as $report) {
        $user_value = $report[$key] ?? 0;
        if ($user_value == $leader_value && $user_value > 0) {
            addNotification($pdo, $report['user_id'], 'best', 
                "🏆 Лучший по {$metric['name']}! Результат: {$user_value} {$metric['unit']}");
        }
        
        // Поиск тех, кто обогнал
        foreach ($reports as $better) {
            $better_value = $better[$key] ?? 0;
            if ($better_value > $user_value && $better['user_id'] != $report['user_id']) {
                addNotification($pdo, $report['user_id'], 'overtaken',
                    "⚡ {$better['full_name']} обогнал вас по {$metric['name']}! " .
                    "У него {$better_value} {$metric['unit']}, у вас {$user_value} {$metric['unit']}");
                break;
            }
        }
    }
}

// Начисление очков
foreach ($reports as $report) {
    $points = 0;
    $points += ($report['calls'] ?? 0) * 1;
    $points += ($report['calls_answered'] ?? 0) * 2;
    $points += ($report['meetings'] ?? 0) * 5;
    $points += ($report['contracts'] ?? 0) * 10;
    $points += ($report['registrations'] ?? 0) * 15;
    $points += ($report['turnover'] ?? 0) / 1000;
    $points = round($points);
    
    // Обновляем очки
    $stmt = $pdo->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?");
    $stmt->execute([$points, $report['user_id']]);
    
    // Обновляем ранг
    $stmt = $pdo->prepare("SELECT total_points FROM users WHERE id = ?");
    $stmt->execute([$report['user_id']]);
    $total = $stmt->fetchColumn();
    
    $newRank = 'Новичок';
    foreach ($RANKS as $rank => $points_needed) {
        if ($total >= $points_needed) $newRank = $rank;
        else break;
    }
    
    $stmt = $pdo->prepare("UPDATE users SET rank = ? WHERE id = ? AND rank != ?");
    $stmt->execute([$newRank, $report['user_id'], $newRank]);
    
    addNotification($pdo, $report['user_id'], 'points', "📊 +{$points} очков за работу!");
}

file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Анализ завершён\n", FILE_APPEND);
echo "OK - " . date('Y-m-d H:i:s') . "\n";
?>
