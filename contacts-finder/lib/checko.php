<?php
// lib/checko.php – интеграция с Checko API v2

function fetchFromChecko(string $inn): array {
    $cfg = require __DIR__ . '/../config.php';
    $key = $cfg['checko_api_key'] ?? null;

    if (!$key) {
        return ['error' => 'Checko API key не задан'];
    }

    $url = 'https://api.checko.ru/v2/company?' . http_build_query([
        'key' => $key,
        'inn' => $inn,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['error' => 'Checko: сетевая ошибка'];
    }
    if ($httpCode === 429) {
        return ['error' => 'Checko: превышен лимит 100 запросов/сутки'];
    }
    if ($httpCode === 403 || $httpCode === 401) {
        return ['error' => "Checko: доступ запрещён (HTTP {$httpCode})"];
    }
    if ($httpCode !== 200) {
        return ['error' => "Checko: HTTP {$httpCode}"];
    }

    $data = json_decode($response, true);
    if (isset($data['error'])) {
        return ['error' => 'Checko: ' . $data['error']];
    }

    $d = $data['data'] ?? null;
    if (!$d) {
        return ['error' => 'Checko: пустой ответ'];
    }

    $phones = null;
    if (!empty($d['Контакты']['Тел']) && is_array($d['Контакты']['Тел'])) {
        $phones = implode('; ', array_unique($d['Контакты']['Тел']));
    }

    $emails = null;
    if (!empty($d['Контакты']['Емэйл']) && is_array($d['Контакты']['Емэйл'])) {
        $emails = implode('; ', array_unique($d['Контакты']['Емэйл']));
    }

    $websites = null;
    if (!empty($d['Контакты']['ВебСайт'])) {
        $websites = is_array($d['Контакты']['ВебСайт'])
            ? implode('; ', array_unique($d['Контакты']['ВебСайт']))
            : (string)$d['Контакты']['ВебСайт'];
    }

    $director = $d['Руковод']['ФИО'] ?? $d['Руковод']['ФИОПолн'] ?? null;

    return [
        'company_name'  => $d['НаимПолн'] ?? $d['НаимСокр'] ?? null,
        'status'        => $d['Статус']['Наим'] ?? null,
        'director_name' => $director,
        'okved_main'    => $d['ОКВЭД']['Наим'] ?? null,
        'address'       => $d['ЮрАдрес']['АдресРФ'] ?? null,
        'phones'        => $phones,
        'emails'        => $emails,
        'websites'      => $websites,
        'source'        => 'Checko',
        'raw'           => $response,
        'error'         => null,
    ];
}
