<?php
/**
 * ИИ-наставник — выдаёт ПЕРСОНАЛЬНЫЕ советы на основе статистики сотрудника
 */

function getDailyAdvice($pdo, $user_id, $role) 
{
    // 1. Получаем статистику ТОЛЬКО этого сотрудника за 7 дней
    $stmt = $pdo->prepare("
        SELECT 
            AVG(calls) as avg_calls,
            AVG(calls_answered) as avg_answered,
            AVG(meetings) as avg_meetings,
            AVG(contracts) as avg_contracts,
            AVG(registrations) as avg_registrations,
            CASE WHEN AVG(calls) > 0 
                 THEN (AVG(calls_answered) * 100.0 / AVG(calls)) 
                 ELSE 0 END as conversion_rate
        FROM daily_reports
        WHERE user_id = ? AND report_date >= date('now', '-7 days')
    ");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch();
    
    // 2. Если нет отчётов — просим заполнить
    if (!$stats || $stats['avg_calls'] == 0) {
        return "📈 Заполните отчёты за последние 7 дней, чтобы получить персональный совет!";
    }
    
    // 3. Определяем КАТЕГОРИЮ строго по показателям сотрудника
    $avgCalls = round($stats['avg_calls']);
    $avgMeetings = round($stats['avg_meetings']);
    $avgContracts = round($stats['avg_contracts']);
    $conversion = round($stats['conversion_rate']);
    
    // Логика определения категории
    if ($avgCalls < 30) {
        $category = 'low_calls';
        $reason = "мало звонков ($avgCalls в неделю)";
    } elseif ($conversion < 25 && $avgCalls > 0) {
        $category = 'low_conversion';
        $reason = "низкая конверсия дозвонов ($conversion%)";
    } elseif ($avgMeetings < 2) {
        $category = 'low_meetings';
        $reason = "мало встреч ($avgMeetings в неделю)";
    } elseif ($avgContracts == 0 && $avgMeetings > 2) {
        $category = 'low_contracts';
        $reason = "нет договоров при $avgMeetings встречах";
    } else {
        $category = 'high_performance';
        $reason = "отличные показатели!";
    }
    
    // 4. Получаем случайный совет из пула для этой категории
    $advice = getAdviceFromPool($pdo, $category);
    
    // 5. Добавляем персональную метку
    return "🎯 [$reason] " . $advice;
}

function getAdviceFromPool($pdo, $category) 
{
    $week_start = date('Y-m-d', strtotime('monday this week'));
    
    // Получаем НЕиспользованный совет для этой категории
    $stmt = $pdo->prepare("
        SELECT id, advice_text FROM weekly_advice_pool 
        WHERE category = ? AND week_start = ? AND is_used = 0
        ORDER BY RANDOM() LIMIT 1
    ");
    $stmt->execute([$category, $week_start]);
    $advice = $stmt->fetch();
    
    if ($advice) {
        // Помечаем как использованный
        $pdo->prepare("UPDATE weekly_advice_pool SET is_used = 1 WHERE id = ?")->execute([$advice['id']]);
        return $advice['advice_text'];
    }
    
    // Если все советы использованы — сбрасываем и берём случайный
    $pdo->prepare("UPDATE weekly_advice_pool SET is_used = 0 WHERE category = ? AND week_start = ?")->execute([$category, $week_start]);
    $stmt = $pdo->prepare("SELECT advice_text FROM weekly_advice_pool WHERE category = ? AND week_start = ? ORDER BY RANDOM() LIMIT 1");
    $stmt->execute([$category, $week_start]);
    $advice = $stmt->fetchColumn();
    
    return $advice ?: fallbackAdvice($category);
}

function fallbackAdvice($category) 
{
    $fallbacks = [
        'low_calls' => "🎯 Цель на неделю: +5 звонков в день! Вы сможете!",
        'low_conversion' => "📞 Техника '3 вопроса': Проблема → Последствия → Решение.",
        'low_meetings' => "🤝 После каждого звонка предлагайте встречу!",
        'low_contracts' => "📄 'А что, если мы начнём с тестового периода?'",
        'high_performance' => "🏆 Вы в топе! Обучайте коллег — растите сами."
    ];
    return $fallbacks[$category] ?? "🌟 Продолжайте в том же духе!";
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
