<?php
// Функция получения случайного совета из недельного пула
function getAdviceFromPool($pdo, $category) {
    $week_start = date('Y-m-d', strtotime('monday this week'));
    
    // Получаем неиспользованный совет из пула
    $stmt = $pdo->prepare("
        SELECT id, advice_text FROM weekly_advice_pool 
        WHERE category = ? AND week_start = ? AND is_used = 0
        ORDER BY RANDOM() LIMIT 1
    ");
    $stmt->execute([$category, $week_start]);
    $advice = $stmt->fetch();
    
    if ($advice) {
        // Отмечаем как использованный
        $pdo->prepare("UPDATE weekly_advice_pool SET is_used = 1 WHERE id = ?")->execute([$advice['id']]);
        return $advice['advice_text'];
    }
    
    // Если все советы использованы — сбрасываем флаги
    $pdo->prepare("UPDATE weekly_advice_pool SET is_used = 0 WHERE category = ? AND week_start = ?")->execute([$category, $week_start]);
    
    // Повторяем попытку
    $stmt = $pdo->prepare("
        SELECT advice_text FROM weekly_advice_pool 
        WHERE category = ? AND week_start = ?
        ORDER BY RANDOM() LIMIT 1
    ");
    $stmt->execute([$category, $week_start]);
    $advice = $stmt->fetch();
    
    return $advice['advice_text'] ?? getLocalFallbackByCategory($category);
}

function getLocalFallbackByCategory($category) {
    $fallbacks = [
        'low_calls' => "🎯 Увеличьте звонки на 20% на этой неделе! Начните с +5 звонков в день.",
        'low_conversion' => "📞 Техника '3 вопроса': Проблема → Последствия → Решение.",
        'low_meetings' => "🤝 После каждого звонка предлагайте встречу: 'Давайте обсудим это за кофе'.",
        'low_contracts' => "📄 'А что, если мы начнём с небольшого тестового периода?'",
        'high_performance' => "🏆 Вы в топе! Обучайте коллег — растите сами."
    ];
    return $fallbacks[$category] ?? "🌟 Продолжайте в том же духе!";
}

function getDailyAdvice($pdo, $user_id, $role) 
{
    $stmt = $pdo->prepare("
        SELECT 
            AVG(dr.calls) as avg_calls,
            AVG(dr.calls_answered) as avg_answered,
            AVG(dr.meetings) as avg_meetings,
            AVG(dr.contracts) as avg_contracts,
            CASE WHEN AVG(dr.calls) > 0 
                 THEN (AVG(dr.calls_answered) * 100.0 / AVG(dr.calls)) 
                 ELSE 0 END as conversion_rate
        FROM daily_reports dr
        WHERE dr.user_id = ? AND dr.report_date >= date('now', '-7 days')
    ");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch();
    
    if (!$stats || $stats['avg_calls'] == 0) {
        return "📈 Заполните отчёты за последние 7 дней, чтобы получить персональный совет!";
    }
    
    // Определяем категорию по статистике
    $avgCalls = round($stats['avg_calls'] ?? 0);
    $avgMeetings = round($stats['avg_meetings'] ?? 0);
    $avgContracts = round($stats['avg_contracts'] ?? 0);
    $conversion = round($stats['conversion_rate'] ?? 0);
    
    if ($avgCalls < 30) {
        $category = 'low_calls';
    } elseif ($conversion < 25 && $avgCalls > 0) {
        $category = 'low_conversion';
    } elseif ($avgMeetings < 2) {
        $category = 'low_meetings';
    } elseif ($avgContracts == 0 && $avgMeetings > 2) {
        $category = 'low_contracts';
    } else {
        $category = 'high_performance';
    }
    
    // Берём совет из недельного пула
    return getAdviceFromPool($pdo, $category);
}

function getBookRecommendation() 
{
    $books = [
        ['title' => 'Атомные привычки', 'author' => 'Джеймс Клир', 'tag' => 'Дисциплина'],
        ['title' => 'Непробиваемые', 'author' => 'Крис Восс', 'tag' => 'Переговоры'],
        ['title' => 'Как завоёвывать друзей', 'author' => 'Дейл Карнеги', 'tag' => 'Коммуникации']
    ];
    $book = $books[array_rand($books)];
    return "📖 <strong>{$book['title']}</strong><br>Автор: {$book['author']}<br>🏷️ {$book['tag']}";
}
?>
