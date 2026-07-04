<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Auth']);
    exit;
}

require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true);
$csv_text = $input['csv'] ?? '';

if (empty($csv_text)) {
    echo json_encode(['success' => false, 'error' => 'Empty CSV']);
    exit;
}

$tabel = $_SESSION['tabel'];
$csv_file = __DIR__ . '/data/tasks_' . $tabel . '.csv';

// Создаём папку если нет
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0777, true);
}

// Конвертируем кодировку если нужно
$csv_text = mb_convert_encoding($csv_text, 'UTF-8', 'UTF-8,Windows-1251,CP1251');

file_put_contents($csv_file, $csv_text);

// Парсим и сохраняем в БД
$lines = explode("\n", $csv_text);
$headers = str_getcsv(array_shift($lines));

$stmt = $pdo->prepare("
    INSERT OR REPLACE INTO epk_tasks 
    (task_id, epk_id, inn, product, result_sale, result_deal, reg_date, sale_date, tb, gosb, campaign, status, channel, created_date, done_date, manager_name, user_tabel, imported_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
");

$count = 0;
foreach ($lines as $line) {
    if (trim($line) === '') continue;
    $row = str_getcsv($line);
    if (count($row) < 16) continue;

    $stmt->execute([
        $row[1] ?? '',  // task_id
        $row[0] ?? '',  // epk_id
        $row[2] ?? '',  // inn
        $row[3] ?? '',  // product
        $row[4] ?? '',  // result_sale
        $row[5] ?? '',  // result_deal
        $row[6] ?? '',  // reg_date
        $row[7] ?? '',  // sale_date
        $row[8] ?? '',  // tb
        $row[9] ?? '',  // gosb
        $row[10] ?? '', // campaign
        $row[11] ?? '', // status
        $row[12] ?? '', // channel
        $row[13] ?? '', // created_date
        $row[14] ?? '', // done_date
        $row[15] ?? '', // manager_name
        $tabel
    ]);
    $count++;
}

echo json_encode(['success' => true, 'count' => $count]);
