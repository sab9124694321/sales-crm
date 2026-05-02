<?php
require_once __DIR__ . '/YandexGPT.php';

// ВАШИ ДАННЫЕ
$YANDEX_API_KEY = 'b1g91gha4gla1b4j80ck';
$YANDEX_FOLDER_ID = 'b1g8sh03gsiola05m9b5';

$yandexGPT = new YandexGPT($YANDEX_API_KEY, $YANDEX_FOLDER_ID);

function getDailyAdvice($pdo, $user_id, $role) 
{
    global $yandexGPT;
    
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
        return "📈 Заполняйте ежедневные отчёты! Чем больше данных, тем точнее будут мои советы.";
    }
    
    $advice = $yandexGPT->getPersonalAdvice($stats);
    if ($advice === false) {
        return getLocalFallback($stats);
    }
    
    return $advice;
}

function getLocalFallback($stats) 
{
    $conversion = round($stats['conversion_rate'] ?? 0);
    $avgMeetings = round($stats['avg_meetings'] ?? 0);
    
    if ($conversion < 25) {
        return "📞 Совет: перед звонком напишите скрипт из 3 вопросов, выявляющих потребности клиента.";
    } elseif ($avgMeetings < 2) {
        return "🤝 Совет: после каждого звонка предлагайте встречу: 'Давайте обсудим это за кофе'.";
    } else {
        return "🌟 Вы на правильном пути! Попробуйте на неделю увеличить звонки на 20%.";
    }
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
