<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Показываем ошибки для отладки
error_reporting(E_ALL);
ini_set('display_errors', 0); // не показывать на экран, но писать в лог

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Требуется авторизация']);
    exit;
}

require_once 'db.php';
require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true);
$question = $input['question'] ?? '';
$level = $input['level'] ?? 'root';
$selectedId = (int)($input['id'] ?? 0);
$selectedDate = $input['date'] ?? date('Y-m-d');
$selectedMonth = date('Y-m', strtotime($selectedDate));

if (empty($question)) {
    echo json_encode(['error' => 'Пустой вопрос']);
    exit;
}

$role = $_SESSION['role'];
$userId = $_SESSION['user_id'];

// --------------------------------------------------------------------
// 1. Токен GigaChat (с обработкой ошибки 401)
// --------------------------------------------------------------------
$accessToken = $_SESSION['gigachat_token'] ?? null;
if (!$accessToken) {
    $accessToken = getAccessToken();
    if ($accessToken) {
        $_SESSION['gigachat_token'] = $accessToken;
    } else {
        echo json_encode(['error' => 'Не удалось авторизоваться в GigaChat (ошибка OAuth)']);
        exit;
    }
}

// --------------------------------------------------------------------
// 2. Список менеджеров
// --------------------------------------------------------------------
$subordinates = getSubordinatesByLevel($pdo, $role, $userId, $level, $selectedId);
if (empty($subordinates)) {
    echo json_encode(['response' => 'Нет данных для анализа.']);
    exit;
}

// --------------------------------------------------------------------
// 3. Подготовка данных
// --------------------------------------------------------------------
$allMetrics = [
    'calls'          => 'Звонки',
    'calls_answered' => 'Дозвоны',
    'meetings'       => 'Встречи',
    'contracts'      => 'Договоры',
    'registrations'  => 'ТЭ',
    'smart_cash'     => 'Смарт-кассы',
    'pos_systems'    => 'ПОС-системы',
    'inn_leads'      => 'ИНН чаевые',
    'teams'          => 'Команды',
    'turnover'       => 'Оборот чаевых',
];

$reportLines = [];
foreach ($subordinates as $sub) {
    $line = "👤 {$sub['full_name']}";
    if (!empty($sub['head_name'])) $line .= " (нач.: {$sub['head_name']})";
    if (!empty($sub['territory_name'])) $line .= " [{$sub['territory_name']}]";

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(calls),0) as calls, COALESCE(SUM(calls_answered),0) as calls_answered,
        COALESCE(SUM(meetings),0) as meetings, COALESCE(SUM(contracts),0) as contracts,
        COALESCE(SUM(registrations),0) as registrations, COALESCE(SUM(smart_cash),0) as smart_cash,
        COALESCE(SUM(pos_systems),0) as pos_systems, COALESCE(SUM(inn_leads),0) as inn_leads,
        COALESCE(SUM(teams),0) as teams, COALESCE(SUM(turnover),0) as turnover
        FROM daily_reports WHERE tabel_number = ? AND strftime('%Y-%m', report_date) = ?");
    $stmt->execute([$sub['tabel_number'], $selectedMonth]);
    $metrics = $stmt->fetch();

    $planStmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ? AND period = ?");
    $planStmt->execute([$sub['tabel_number'], $selectedMonth]);
    $plan = $planStmt->fetch();

    $metricParts = [];
    foreach ($allMetrics as $key => $label) {
        $factVal = $metrics[$key] ?? 0;
        $planKey = $key . '_plan';
        $planVal = $plan[$planKey] ?? 0;
        $pct = $planVal > 0 ? round(($factVal / $planVal) * 100) : 0;
        $metricParts[] = "$label: $factVal / план $planVal ($pct%)";
    }
    $line .= "\n  " . implode(" | ", $metricParts);
    $reportLines[] = $line;
}

$fullReport = implode("\n\n", $reportLines);

// --------------------------------------------------------------------
// 4. Запрос к GigaChat с повторной авторизацией при 401
// --------------------------------------------------------------------
$prompt = "Ты — AI-аналитик CRM. Вот полный список менеджеров с их показателями за месяц:\n\n$fullReport\n\n";
$prompt .= "Ответь на вопрос пользователя: «$question».\n";
$prompt .= "При ответе обязательно используй имена менеджеров из списка и конкретные цифры. Если нужно сравнить — сравнивай по плану или между сотрудниками. Не придумывай данные, бери только из предоставленного списка.";

$result = callGigaChatWithRetry($prompt, $accessToken);

if ($result['success']) {
    echo json_encode(['response' => $result['response']]);
} else {
    echo json_encode([
        'error' => 'Ошибка GigaChat: ' . $result['error'],
        'debug' => $result['debug'] ?? ''
    ]);
}

// --------------------------------------------------------------------
// Функции
// --------------------------------------------------------------------
function getAccessToken(): ?string {
    $authBase64 = GIGACHAT_AUTH;
    $rquid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['scope' => 'GIGACHAT_API_PERS']));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $authBase64,
        'Content-Type: application/x-www-form-urlencoded',
        'RqUID: ' . $rquid,
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($httpCode === 200) {
        $json = json_decode($response, true);
        return $json['access_token'] ?? null;
    }
    
    // Логируем ошибку
    file_put_contents(__DIR__ . '/gigachat_errors.log', 
        date('Y-m-d H:i:s') . " OAuth error HTTP $httpCode: $response (curl error: $error)\n", 
        FILE_APPEND);
    return null;
}

function callGigaChatWithRetry(string $prompt, string &$accessToken): array {
    $response = callGigaChat($prompt, $accessToken);
    if ($response['http_code'] === 401) {
        // Токен истек, получаем новый
        $newToken = getAccessToken();
        if ($newToken) {
            $_SESSION['gigachat_token'] = $newToken;
            $accessToken = $newToken;
            $response = callGigaChat($prompt, $newToken);
        } else {
            return ['success' => false, 'error' => 'Ошибка авторизации (401) и не удалось обновить токен'];
        }
    }
    
    if ($response['http_code'] === 200) {
        return ['success' => true, 'response' => $response['body']];
    }
    
    // Любая другая ошибка
    return [
        'success' => false,
        'error' => "HTTP {$response['http_code']}",
        'debug' => $response['body'] ?? $response['curl_error']
    ];
}

function callGigaChat(string $prompt, string $accessToken): array {
    $data = [
        'model' => 'GigaChat',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.2,
        'max_tokens' => 800,
    ];

    $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    if ($response === false) {
        return ['http_code' => 0, 'body' => null, 'curl_error' => $curlError];
    }
    
    $body = json_decode($response, true);
    $text = $body['choices'][0]['message']['content'] ?? null;
    return ['http_code' => $httpCode, 'body' => $text];
}

function getSubordinatesByLevel(PDO $pdo, string $role, int $userId, string $level, int $selectedId): array {
    // ... (без изменений, как в предыдущей версии)
    if ($role === 'admin') {
        $stmt = $pdo->query("SELECT u.*, h.full_name as head_name, t.name as territory_name 
            FROM users u 
            LEFT JOIN users h ON u.head_tabel = h.tabel_number 
            LEFT JOIN territories t ON u.territory_id = t.id 
            WHERE u.role = 'manager' AND u.is_active = 1");
        return $stmt->fetchAll();
    } elseif ($role === 'terman') {
        if ($level === 'territory' && $selectedId > 0) {
            $stmt = $pdo->prepare("SELECT u.*, h.full_name as head_name, t.name as territory_name 
                FROM users u 
                LEFT JOIN users h ON u.head_tabel = h.tabel_number 
                LEFT JOIN territories t ON u.territory_id = t.id 
                WHERE u.role = 'manager' AND u.territory_id = ? AND u.is_active = 1 ORDER BY u.full_name");
            $stmt->execute([$selectedId]);
            return $stmt->fetchAll();
        }
        return [];
    } elseif (in_array($role, ['head', 'territory_head'])) {
        $stmt = $pdo->prepare("SELECT u.*, h.full_name as head_name, t.name as territory_name 
            FROM users u 
            LEFT JOIN users h ON u.head_tabel = h.tabel_number 
            LEFT JOIN territories t ON u.territory_id = t.id 
            WHERE u.manager_id = ? AND u.role = 'manager' AND u.is_active = 1 ORDER BY u.full_name");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    return [];
}