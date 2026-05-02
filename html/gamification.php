<?php
$RANKS = [
    'Новичок' => ['points' => 0, 'icon' => '🌱'],
    'Стажёр' => ['points' => 100, 'icon' => '📚'],
    'Специалист' => ['points' => 500, 'icon' => '⭐'],
    'Профи' => ['points' => 1000, 'icon' => '💎'],
    'Эксперт' => ['points' => 2000, 'icon' => '🏆'],
    'Легенда' => ['points' => 5000, 'icon' => '👑']
];

$METRICS = [
    'calls' => ['name' => 'звонкам', 'unit' => 'зв.', 'icon' => '📞'],
    'calls_answered' => ['name' => 'дозвонам', 'unit' => 'доз.', 'icon' => '✅'],
    'meetings' => ['name' => 'встречам', 'unit' => 'встр.', 'icon' => '🤝'],
    'contracts' => ['name' => 'договорам', 'unit' => 'дог.', 'icon' => '📄'],
    'registrations' => ['name' => 'регистрациям', 'unit' => 'рег.', 'icon' => '📝'],
    'turnover' => ['name' => 'обороту', 'unit' => 'руб.', 'icon' => '💰']
];

function calculateDailyPoints($report) {
    $points = 0;
    $points += ($report['calls'] ?? 0) * 1;
    $points += ($report['calls_answered'] ?? 0) * 2;
    $points += ($report['meetings'] ?? 0) * 5;
    $points += ($report['contracts'] ?? 0) * 10;
    $points += ($report['registrations'] ?? 0) * 15;
    $points += ($report['turnover'] ?? 0) / 1000;
    return round($points);
}

function updateUserRank($pdo, $user_id, $total_points) {
    global $RANKS;
    $newRank = 'Новичок';
    foreach ($RANKS as $rank => $data) {
        if ($total_points >= $data['points']) {
            $newRank = $rank;
        } else {
            break;
        }
    }
    
    $stmt = $pdo->prepare("SELECT rank FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $currentRank = $stmt->fetchColumn();
    
    if ($newRank != $currentRank && $currentRank) {
        $stmt = $pdo->prepare("UPDATE users SET rank = ?, total_points = ? WHERE id = ?");
        $stmt->execute([$newRank, $total_points, $user_id]);
        createNotification($pdo, $user_id, 'rank_up', "🎉 Поздравляем! Новый ранг: {$RANKS[$newRank]['icon']} {$newRank}!");
        return true;
    } else {
        $stmt = $pdo->prepare("UPDATE users SET total_points = ? WHERE id = ?");
        $stmt->execute([$total_points, $user_id]);
    }
    return false;
}

function createNotification($pdo, $user_id, $type, $message) {
    $stmt = $pdo->prepare("INSERT INTO game_notifications (user_id, type, message, created_at) VALUES (?, ?, ?, datetime('now'))");
    $stmt->execute([$user_id, $type, $message]);
}

function analyzeYesterdayReports($pdo) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    $stmt = $pdo->prepare("
        SELECT dr.*, u.full_name, u.id as user_id, u.role, u.manager_id 
        FROM daily_reports dr
        JOIN users u ON dr.user_id = u.id
        WHERE dr.report_date = ?
    ");
    $stmt->execute([$yesterday]);
    $reports = $stmt->fetchAll();
    
    if (empty($reports)) {
        return ['success' => false, 'message' => "Нет отчётов за $yesterday"];
    }
    
    global $METRICS;
    foreach ($METRICS as $metric_key => $metric_info) {
        usort($reports, function($a, $b) use ($metric_key) {
            return ($b[$metric_key] ?? 0) <=> ($a[$metric_key] ?? 0);
        });
        
        $leader_value = $reports[0][$metric_key] ?? 0;
        
        foreach ($reports as $report) {
            $user_value = $report[$metric_key] ?? 0;
            
            foreach ($reports as $better) {
                $better_value = $better[$metric_key] ?? 0;
                if ($better_value > $user_value && $better['user_id'] != $report['user_id']) {
                    $diff = $better_value - $user_value;
                    $message = "⚡ {$better['full_name']} обогнал вас по {$metric_info['name']}! " .
                               "У него {$better_value} {$metric_info['unit']}, " .
                               "у вас {$user_value} {$metric_info['unit']} (+{$diff})";
                    createNotification($pdo, $report['user_id'], 'overtaken', $message);
                }
            }
            
            if ($user_value == $leader_value && $user_value > 0) {
                $message = "🏆 Вы лучший по {$metric_info['name']} за вчера! " .
                           "Результат: {$user_value} {$metric_info['unit']}";
                createNotification($pdo, $report['user_id'], 'best', $message);
            }
        }
    }
    
    foreach ($reports as $report) {
        $points = calculateDailyPoints($report);
        $stmt = $pdo->prepare("SELECT total_points FROM users WHERE id = ?");
        $stmt->execute([$report['user_id']]);
        $current = $stmt->fetchColumn() ?: 0;
        $total_points = $current + $points;
        updateUserRank($pdo, $report['user_id'], $total_points);
        createNotification($pdo, $report['user_id'], 'points', "📊 За $yesterday +{$points} очков!");
    }
    
    return ['success' => true, 'count' => count($reports)];
}

function getUnreadNotifications($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM game_notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function markNotificationsAsRead($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE game_notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
}

function getUserRankInfo($pdo, $user_id) {
    global $RANKS;
    $stmt = $pdo->prepare("SELECT full_name, rank, total_points FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if (!$user) return null;
    
    $total_points = $user['total_points'] ?: 0;
    $nextRank = null;
    $nextPoints = null;
    foreach ($RANKS as $rank => $data) {
        if ($data['points'] > $total_points) {
            $nextRank = $rank;
            $nextPoints = $data['points'];
            break;
        }
    }
    
    return [
        'name' => $user['full_name'],
        'rank' => $user['rank'] ?: 'Новичок',
        'points' => $total_points,
        'icon' => $RANKS[$user['rank']]['icon'] ?? '🌱',
        'next_rank' => $nextRank,
        'next_points' => $nextPoints,
        'progress' => $nextPoints ? round(($total_points / $nextPoints) * 100) : 100
    ];
}

function getLeaderboard($pdo, $limit = 10) {
    $stmt = $pdo->prepare("SELECT full_name, rank, total_points, role FROM users WHERE role != 'admin' ORDER BY total_points DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function runDailyAnalysis($pdo) {
    $result = analyzeYesterdayReports($pdo);
    if ($result['success']) {
        file_put_contents('/tmp/gamification.log', date('Y-m-d H:i:s') . " - OK - " . $result['count'] . " reports\n", FILE_APPEND);
    } else {
        file_put_contents('/tmp/gamification.log', date('Y-m-d H:i:s') . " - " . $result['message'] . "\n", FILE_APPEND);
    }
    return $result;
}
?>
