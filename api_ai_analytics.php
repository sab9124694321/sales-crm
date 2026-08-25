<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);

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

// --- 1. Токен GigaChat ---
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

// --- 2. Список менеджеров ---
$subordinates = getSubordinatesByLevel($pdo, $role, $userId, $level, $selectedId);
if (empty($subordinates)) {
    echo json_encode(['response' => 'Нет данных для анализа.']);
    exit;
}

// --- 3. Подготовка данных (daily_reports + inn_records + plans) ---
$allMetrics = [
    'calls'          => ['label' => 'Звонки', 'icon' => '📞', 'source' => 'daily'],
    'calls_answered' => ['label' => 'Дозвоны', 'icon' => '✅', 'source' => 'daily'],
    'meetings'       => ['label' => 'Встречи', 'icon' => '🤝', 'source' => 'daily'],
    'contracts'      => ['label' => 'Договоры', 'icon' => '📄', 'source' => 'daily'],
    'registrations'  => ['label' => 'ТЭ (торговые эквайринговые терминалы)', 'icon' => '📝', 'source' => 'inn'],
    'smart_cash'     => ['label' => 'Смарт-кассы (смарт-терминалы)', 'icon' => '💳', 'source' => 'inn'],
    'pos_systems'    => ['label' => 'ПОС-системы (программно-аппаратные комплексы)', 'icon' => '🖥️', 'source' => 'inn'],
    'inn_leads'      => ['label' => 'ИНН чаевые (кол-во зарегистрированных команд официантов)', 'icon' => '🍵', 'source' => 'inn'],
    'teams'          => ['label' => 'Команды (созданные группы)', 'icon' => '👥', 'source' => 'daily'],
    'turnover'       => ['label' => 'Оборот чаевых (сумма чаевых от гостей в рублях)', 'icon' => '💰', 'source' => 'daily'],
];

$reportLines = [];
foreach ($subordinates as $sub) {
    $line = "👤 {$sub['full_name']}";
    if (!empty($sub['head_name'])) $line .= " (нач.: {$sub['head_name']})";
    if (!empty($sub['territory_name'])) $line .= " [{$sub['territory_name']}]";

    // Данные из daily_reports
    $stmtDaily = $pdo->prepare("SELECT COALESCE(SUM(calls),0) as calls, COALESCE(SUM(calls_answered),0) as calls_answered,
        COALESCE(SUM(meetings),0) as meetings, COALESCE(SUM(contracts),0) as contracts,
        COALESCE(SUM(teams),0) as teams, COALESCE(SUM(turnover),0) as turnover
        FROM daily_reports WHERE user_id = ? AND strftime('%Y-%m', report_date) = ?");
    $stmtDaily->execute([$sub['id'], $selectedMonth]);
    $metricsDaily = $stmtDaily->fetch();

    // Данные из inn_records
    $stmtInn = $pdo->prepare("SELECT 
        SUM(CASE WHEN product = 'ТЭ' THEN 1 ELSE 0 END) as registrations,
        SUM(CASE WHEN product = 'Смарт' THEN 1 ELSE 0 END) as smart_cash,
        SUM(CASE WHEN product = 'ПОС' THEN 1 ELSE 0 END) as pos_systems,
        SUM(CASE WHEN product = 'Чаевые' THEN 1 ELSE 0 END) as inn_leads
        FROM inn_records 
        WHERE employee_tabel = ? AND DATE(sale_date) BETWEEN ? AND ?");
    $monthStart = date('Y-m-01', strtotime($selectedDate));
    $monthEnd = date('Y-m-t', strtotime($selectedDate));
    $stmtInn->execute([$sub['tabel_number'], $monthStart, $monthEnd]);
    $metricsInn = $stmtInn->fetch();

    // Объединяем
    $metrics = array_merge($metricsDaily, $metricsInn);

    // Планы
    $planStmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ? AND period = ?");
    $planStmt->execute([$sub['tabel_number'], $selectedMonth]);
    $plan = $planStmt->fetch();

    $metricParts = [];
    foreach ($allMetrics as $key => $info) {
        $factVal = $metrics[$key] ?? 0;
        $planKey = $key . '_plan';
        $planVal = $plan[$planKey] ?? 0;
        $pct = $planVal > 0 ? round(($factVal / $planVal) * 100) : 0;
        $metricParts[] = "{$info['icon']} {$info['label']}: $factVal / план $planVal ($pct%)";
    }
    $line .= "\n  " . implode(" | ", $metricParts);
    $reportLines[] = $line;
}

$fullReport = implode("\n\n", $reportLines);

if (empty($fullReport)) {
    echo json_encode(['response' => 'Нет данных за выбранный месяц.']);
    exit;
}

// Логирование
file_put_contents(__DIR__ . '/ai_debug.log', date('Y-m-d H:i:s') . "\n" . $fullReport . "\n---\n", FILE_APPEND);

// --- 4. Промпт ---
$prompt = "Ты — точный ассистент. Отвечай СТРОГО на вопрос пользователя. Не делай общий анализ и не называй всех менеджеров подряд.\n";
$prompt .= "Данные за месяц (факт / план / процент выполнения):\n" . $fullReport . "\n\n";
$prompt .= "Вопрос пользователя: $question\n";
$prompt .= "Правила ответа:\n";
$prompt .= "1. Если спрашивают про конкретного менеджера, назови только его цифры.\n";
$prompt .= "2. Планы в данных РЕАЛЬНЫЕ. Если тебя просят сравнить выполнение плана или посчитать процент, используй эти данные.\n";
$prompt .= "3. КРИТИЧЕСКИ ВАЖНО: '🍵 ИНН чаевые' — это количество команд (штуки), а '💰 Оборот' — это сумма в рублях. Никогда не путай эти колонки.\n";
$prompt .= "4. ЗАПРЕЩЕНО использовать Markdown (символы *, #, _). Отвечай только обычным текстом.";

// --- 5. Запрос к GigaChat ---
$result = callGigaChatWithRetry($prompt, $accessToken);

if ($result['success']) {
    echo json_encode(['response' => strip_markdown($result['response'])]);
} else {
    echo json_encode([
        'error' => 'Ошибка GigaChat: ' . $result['error'],
        'debug' => $result['debug'] ?? ''
    ]);
}

// --- Функции ---

function strip_markdown($text) {
    $text = preg_replace('/^#{1,6}\s+/m', '', $text);
    $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text);
    $text = preg_replace('/__(.*?)__/', '$1', $text);
    $text = preg_replace('/\*(.*?)\*/', '$1', $text);
    $text = preg_replace('/_(.*?)_/', '$1', $text);
    return trim($text);
}

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
    
    file_put_contents(__DIR__ . '/gigachat_errors.log', 
        date('Y-m-d H:i:s') . " OAuth error HTTP $httpCode: $response (curl error: $error)\n", 
        FILE_APPEND);
    return null;
}

function callGigaChatWithRetry(string $prompt, string &$accessToken): array {
    $response = callGigaChat($prompt, $accessToken);
    if ($response['http_code'] === 401) {
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
            ['role' => 'system', 'content' => 'Ты — строгий ассистент по работе с таблицами. Никогда не делай общие выводы или отчеты, если об этом не просят. Отвечай простым текстом без Markdown. Используй только данные из запроса.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.1,
        'max_tokens' => 1000,
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

// ==========================================
// ИСПРАВЛЕННАЯ ФУНКЦИЯ ДЛЯ РОЛИ TERMAN
// ==========================================
function getSubordinatesByLevel(PDO $pdo, string $role, int $userId, string $level, int $selectedId): array {
    if ($role === 'admin') {
        $stmt = $pdo->query("SELECT u.*, h.full_name as head_name, t.name as territory_name 
            FROM users u 
            LEFT JOIN users h ON u.head_tabel = h.tabel_number 
            LEFT JOIN territories t ON u.territory_id = t.id 
            WHERE u.role = 'manager' AND u.is_active = 1");
        return $stmt->fetchAll();
    } elseif ($role === 'terman') {
        // Если термен в корне - возвращаем всех менеджеров всех его территорий
        if ($level === 'root') {
            $stmt = $pdo->prepare("SELECT u.*, h.full_name as head_name, t.name as territory_name 
                FROM users u 
                JOIN territory_managers tm ON u.territory_id = tm.territory_id 
                LEFT JOIN users h ON u.head_tabel = h.tabel_number 
                LEFT JOIN territories t ON u.territory_id = t.id 
                WHERE tm.manager_id = ? AND u.role = 'manager' AND u.is_active = 1 ORDER BY u.full_name");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        }
        // Если термен смотрит конкретную территорию
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