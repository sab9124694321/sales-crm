<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$mode = $input['mode'] ?? 'analyze_call';

if ($mode === 'generate_plan') {
    $task_id = $input['task_id'] ?? '';
    $product = $input['product'] ?? 'Торговый эквайринг';
    $history = $input['history'] ?? [];

    // Формируем микроплан на основе истории
    $plan = "<strong>🎯 Микроплан звонка</strong><br>";
    $plan .= "<ul>";

    if (empty($history)) {
        // Первый звонок
        $plan .= "<li>📞 <strong>Первый контакт</strong> — представьтесь, уточните потребность</li>";
        $plan .= "<li>💰 Расскажите про $product: комиссия от 1,2%, СБП 0%</li>";
        $plan .= "<li>❓ Выявите возражения: цена, конкуренты, не нужен сейчас</li>";
        $plan .= "<li>📅 Назначьте следующий контакт или договоритесь о встрече</li>";
    } else {
        // Повторный звонок — анализируем историю
        $last = $history[0] ?? null;
        $last_text = $last['comment_text'] ?? '';
        $last_status = $last['call_result'] ?? '';

        $plan .= "<li>📞 <strong>Повторный контакт</strong> — напомните о предыдущем разговоре</li>";

        if (stripos($last_text, 'думает') !== false || $last_status === 'think') {
            $plan .= "<li>⏰ Клиент думал — уточните решение, предложите помощь</li>";
        }
        if (stripos($last_text, 'кп') !== false || stripos($last_text, 'коммерческое') !== false) {
            $plan .= "<li>📄 Уточните, получил ли клиент КП, есть ли вопросы</li>";
        }
        if (stripos($last_text, 'встреча') !== false || stripos($last_text, 'демо') !== false) {
            $plan .= "<li>🤝 Подтвердите встречу, уточните время и место</li>";
        }
        if (stripos($last_text, 'дорого') !== false || stripos($last_text, 'цена') !== false) {
            $plan .= "<li>💰 Обсудите тарифы, покажите калькулятор выгоды</li>";
        }
        if (stripos($last_text, 'конкурент') !== false || stripos($last_text, 'тинькофф') !== false || stripos($last_text, 'альфа') !== false) {
            $plan .= "<li>🏦 Отработайте конкурентов: СБП бесплатно, поддержка 24/7</li>";
        }

        $plan .= "<li>📅 Зафиксируйте договорённости, назначьте следующий шаг</li>";
    }

    $plan .= "</ul>";
    $plan .= "<div style='margin-top:8px; font-size:0.8rem; color:#666;'>💡 Совет: запишите разговор, если есть возможность</div>";

    echo json_encode(['response' => $plan]);
    exit;
}

// Режим analyze_call — анализ комментария
if ($mode === 'analyze_call') {
    $text = $input['text'] ?? '';
    $status = $input['status'] ?? '';

    $analysis = [];
    $lower = mb_strtolower($text);

    // Проверяем наличие ключевых элементов
    $has_problem = stripos($lower, 'проблема') !== false || stripos($lower, 'беспокоит') !== false;
    $has_objection = stripos($lower, 'возражение') !== false || stripos($lower, 'дорого') !== false;
    $has_next_step = stripos($lower, 'договорились') !== false || stripos($lower, 'перезвон') !== false;
    $has_decision = stripos($lower, 'реш') !== false || stripos($lower, 'директор') !== false;

    if (!$has_problem) $analysis[] = "❌ Не указана проблема клиента";
    if (!$has_objection) $analysis[] = "⚠️ Не зафиксированы возражения";
    if (!$has_next_step) $analysis[] = "⚠️ Нет чётких договорённостей";
    if (!$has_decision) $analysis[] = "⚠️ Не указано, кто принимает решение";

    if (empty($analysis)) {
        $analysis[] = "✅ Хороший комментарий! Все ключевые элементы на месте.";
    }

    echo json_encode(['response' => implode("<br>", $analysis)]);
    exit;
}

// Режим analyze_answers — анализ ответов на вопросы ИИ
if ($mode === 'analyze_answers') {
    $answers = $input['answers'] ?? [];
    echo json_encode(['response' => '✅ Ответы получены. Продолжайте работу с клиентом.']);
    exit;
}

echo json_encode(['error' => 'Неизвестный режим']);
