<?php
require_once 'db.php';
require_once 'gamification.php';

echo "Запускаем анализ отчётов за вчера...\n";
$result = runDailyAnalysis($pdo);
echo "Результат: " . json_encode($result, JSON_UNICODE | JSON_PRETTY_PRINT) . "\n";
?>
