<?php
require_once 'YandexGPT.php';

// Данные из вашего аккаунта Yandex Cloud
$API_KEY = 'b1g91gha4gla1b4j80ck';
$FOLDER_ID = 'b1g8sh03gsiola05m9b5';

echo "🤖 Тестируем YandexGPT API...\n";
echo str_repeat("=", 50) . "\n";

$yandex = new YandexGPT($API_KEY, $FOLDER_ID);

// Тестовые запросы
$test_queries = [
    "Дай короткий мотивирующий совет менеджеру по продажам на сегодня. 2-3 предложения.",
    "Как увеличить количество встреч с клиентами? Дай 2 коротких совета.",
    "Что делать, если клиент говорит 'мне нужно подумать'? Дай короткий ответ."
];

foreach ($test_queries as $i => $query) {
    echo "\n📝 Вопрос " . ($i+1) . ": " . substr($query, 0, 60) . "...\n";
    echo str_repeat("-", 50) . "\n";
    
    $response = $yandex->generate($query);
    
    if ($response === false) {
        echo "❌ Ошибка: YandexGPT не отвечает\n";
        echo "Проверьте API-ключ и интернет-соединение\n";
    } else {
        echo "✅ Ответ YandexGPT:\n";
        echo $response . "\n";
    }
    echo "\n";
}

echo str_repeat("=", 50) . "\n";
echo "✅ Тест завершён\n";
?>
