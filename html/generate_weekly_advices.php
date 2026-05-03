#!/usr/bin/env php
<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/GigaChat.php';

$AUTH_KEY = 'NzA0OTMxMWYtMTJkNy00OTQ5LWI2MzUtN2ZhYjZiNWRjMzY3OmIyYWJhMDQxLWRiYzgtNGY4ZC1hZDEwLTBhOTY2ZDQ4ZTc3OA==';
$gigachat = new GigaChat($AUTH_KEY);

$categories = [
    'low_calls' => [
        'name' => 'Низкая активность',
        'prompt' => 'Сгенерируй ровно 20 коротких мотивирующих советов для менеджера по продажам, который делает мало звонков (менее 30 в неделю). Каждый совет должен быть уникальным, 1 предложение, с эмодзи. Отвечай строго в формате: 1. текст... 2. текст... и так до 20. Без лишних слов. Только список.'
    ],
    'low_conversion' => [
        'name' => 'Низкая конверсия',
        'prompt' => 'Сгенерируй ровно 20 коротких советов для менеджера по продажам, у которого низкий процент дозвонов (менее 25%). Советы по технике звонков и работе с возражениями. 1 предложение, с эмодзи. Отвечай строго в формате: 1. текст... 2. текст... и так до 20.'
    ],
    'low_meetings' => [
        'name' => 'Мало встреч',
        'prompt' => 'Сгенерируй ровно 20 коротких советов для менеджера по продажам, у которого мало встреч (менее 2 в неделю). Как назначать больше встреч. 1 предложение, с эмодзи. Отвечай строго в формате: 1. текст... 2. текст... и так до 20.'
    ],
    'low_contracts' => [
        'name' => 'Нет договоров',
        'prompt' => 'Сгенерируй ровно 20 коротких советов для менеджера по продажам, который ходит на встречи, но не заключает договоры. Техники закрытия сделок. 1 предложение, с эмодзи. Отвечай строго в формате: 1. текст... 2. текст... и так до 20.'
    ],
    'high_performance' => [
        'name' => 'Высокая эффективность',
        'prompt' => 'Сгенерируй ровно 20 коротких советов для успешного менеджера по продажам. Как выйти на новый уровень, масштабировать успех. 1 предложение, с эмодзи. Отвечай строго в формате: 1. текст... 2. текст... и так до 20.'
    ]
];

$week_start = date('Y-m-d', strtotime('monday this week'));
echo "📅 Неделя начинается: $week_start\n";
echo "🤖 Генерация пула советов через GigaChat...\n\n";

// Очищаем старые советы за эту неделю
$pdo->prepare("DELETE FROM weekly_advice_pool WHERE week_start = ?")->execute([$week_start]);
$total = 0;

foreach ($categories as $key => $cat) {
    echo "📝 Категория: {$cat['name']}\n";
    echo "   ⏳ Отправляю запрос к GigaChat...\n";
    
    $response = $gigachat->generateAdvice($cat['prompt']);
    $count = 0;
    
    if ($response && !str_contains($response, 'Ошибка')) {
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\d+[\.\)]\s*(.+)$/u', $line, $matches)) {
                $advice = trim($matches[1]);
                if (!empty($advice) && mb_strlen($advice) > 10) {
                    $stmt = $pdo->prepare("INSERT INTO weekly_advice_pool (category, advice_text, week_start) VALUES (?, ?, ?)");
                    $stmt->execute([$key, $advice, $week_start]);
                    $count++;
                    $total++;
                }
            } elseif (!empty($line) && mb_strlen($line) > 15 && !preg_match('/^(Вот|Сгенерируй|Список|совет)/ui', $line)) {
                $stmt = $pdo->prepare("INSERT INTO weekly_advice_pool (category, advice_text, week_start) VALUES (?, ?, ?)");
                $stmt->execute([$key, $line, $week_start]);
                $count++;
                $total++;
            }
        }
        
        $percentage = round($count / 20 * 100);
        echo "   ✅ Сгенерировано: $count / 20 советов ($percentage%)\n";
    } else {
        echo "   ❌ Ошибка генерации: " . ($response ?: 'таймаут') . "\n";
    }
    
    echo "\n";
    sleep(2); // Пауза между запросами
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 ИТОГО: $total советов сохранено в пул\n";

if ($total < 50) {
    echo "⚠️ Рекомендуется запустить генерацию повторно для заполнения пула\n";
    echo "   (GigaChat иногда выдаёт неполный список с первого раза)\n";
} else {
    echo "✅ Пул достаточно заполнен! Советы будут случайно распределяться между сотрудниками.\n";
}
?>
