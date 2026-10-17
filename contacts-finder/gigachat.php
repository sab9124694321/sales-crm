<?php
// gigachat.php – получение токена и запросы к GigaChat

function getGigaChatToken(): ?string {
    $cfg = require __DIR__ . '/config.php';
    $tokenFile = __DIR__ . '/gigachat_token.json';

    if (is_file($tokenFile)) {
        $cached = json_decode(file_get_contents($tokenFile), true);
        if (!empty($cached['access_token']) && ($cached['expires_at'] ?? 0) > time() + 120) {
            return $cached['access_token'];
        }
    }

    $rqUid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => 'scope=' . $cfg['gigachat_scope'],
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'RqUID: ' . $rqUid,
            'Authorization: Basic ' . $cfg['gigachat_auth'],
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("GigaChat OAuth error: HTTP {$httpCode} / {$response}");
        return null;
    }

    $data = json_decode($response, true);
    if (empty($data['access_token'])) {
        return null;
    }

    file_put_contents($tokenFile, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => $data['expires_at'] ?? (time() + 1800),
    ]));

    return $data['access_token'];
}

function gigaChatComplete(string $prompt, ?string $system = null): ?string {
    $cfg = require __DIR__ . '/config.php';
    $token = getGigaChatToken();
    if (!$token) {
        return null;
    }

    $messages = [];
    if ($system) {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => $cfg['gigachat_model'],
            'messages'    => $messages,
            'temperature' => 0.1,
            'max_tokens'  => 600,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("GigaChat complete error: HTTP {$httpCode} / {$response}");
        return null;
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

function gigaChatEnrichCompany(array $company): array {
    $prompt = "Компания: {$company['name']}\n"
        . "ИНН: {$company['inn']}\n"
        . "ОКВЭД: {$company['okved']}\n"
        . "Статус: {$company['status']}\n"
        . "Адрес: {$company['address']}\n\n"
        . "Верни строго JSON без markdown и без пояснений:\n"
        . "{\n"
        . "  \"okved_description\": \"краткое описание рода деятельности (1-2 предложения)\",\n"
        . "  \"activity_note\": \"краткая заметка для менеджера по продажам: какие каналы связи обычно актуальны для таких компаний\"\n"
        . "}\n\n"
        . "Если данных недостаточно — верни null в соответствующих полях. Не выдумывай конкретные телефоны или email.";

    $raw = gigaChatComplete($prompt, 'Ты аналитик B2B. Отвечаешь строго валидным JSON.');
    if (!$raw) {
        return ['okved_description' => null, 'activity_note' => null];
    }

    $raw = trim($raw);
    $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['okved_description' => null, 'activity_note' => null];
    }

    return [
        'okved_description' => $data['okved_description'] ?? null,
        'activity_note'     => $data['activity_note'] ?? null,
    ];
}
