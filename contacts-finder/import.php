<?php
require __DIR__ . '/db.php';
require __DIR__ . '/gigachat.php';
require __DIR__ . '/lib/checko.php';

$config = require __DIR__ . '/config.php';
$db = getContactsDb();

$inns = [];
if (!empty($_POST['inns'])) {
    $inns = array_merge($inns, preg_split('/[\s,;]+/', trim($_POST['inns'])));
}
if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $inns = array_merge($inns, preg_split('/[\s,;]+/', trim(file_get_contents($_FILES['file']['tmp_name']))));
}
$inns = array_filter(array_map('trim', $inns), fn($v) => preg_match('/^\d{10}$|^\d{12}$/', $v));
$inns = array_values(array_unique($inns));

if (empty($inns)) {
    header('Location: index.php?err=' . urlencode('Не найдено валидных ИНН'));
    exit;
}

$processed = 0;
$errors = [];
$gigaAvailable = true;

foreach ($inns as $inn) {
    // DaData
    $payload = json_encode(['query' => $inn], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://dadata.ru/api/v2/suggest/party');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Token ' . $config['dadata_api_key'],
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        $errors[] = "{$inn}: DaData HTTP {$httpCode}";
        continue;
    }
    $data = json_decode($response, true);
    $s = $data['suggestions'][0] ?? null;
    if (!$s) {
        $errors[] = "{$inn}: не найдено в DaData";
        continue;
    }

    $companyName  = $s['data']['name']['short_with_opf'] ?? $s['value'] ?? null;
    $status       = $s['data']['state']['status'] ?? null;
    $directorName = $s['data']['management']['name'] ?? null;
    $okvedMain    = $s['data']['okved'] ?? null;
    $address      = $s['data']['address']['value'] ?? null;
    $sources      = ['DaData'];

    // Checko
    $checkoPhones = $checkoEmails = $checkoWebsites = null;
    $checkoRaw = null;
    $checko = fetchFromChecko($inn);
    if (!empty($checko['error'])) {
        error_log("Checko {$inn}: {$checko['error']}");
    } else {
        $checkoPhones   = $checko['phones'];
        $checkoEmails   = $checko['emails'];
        $checkoWebsites = $checko['websites'];
        if (empty($address) && !empty($checko['address']))              $address = $checko['address'];
        if (empty($okvedMain) && !empty($checko['okved_main']))         $okvedMain = $checko['okved_main'];
        if (empty($directorName) && !empty($checko['director_name']))   $directorName = $checko['director_name'];
        $checkoRaw = $checko['raw'];
        if ($checkoPhones || $checkoEmails || $checkoWebsites) {
            $sources[] = 'Checko';
        }
    }

    // GigaChat
    $okvedDescription = null;
    $activityNote     = null;
    if ($gigaAvailable) {
        $enriched = gigaChatEnrichCompany([
            'name'    => $companyName ?? '',
            'inn'     => $inn,
            'okved'   => $okvedMain ?? '',
            'status'  => $status ?? '',
            'address' => $address ?? '',
        ]);
        if ($enriched['okved_description'] === null && $enriched['activity_note'] === null) {
            $gigaAvailable = false;
            error_log("GigaChat недоступен, отключаю для оставшихся ИНН");
        } else {
            $okvedDescription = $enriched['okved_description'];
            $activityNote     = $enriched['activity_note'];
            $sources[]        = 'GigaChat';
        }
    }

    // Сохранение
    $stmt = $db->prepare("
        INSERT INTO contacts (
            inn, company_name, status, director_name, okved_main,
            okved_description, activity_note, address,
            phones, emails, websites, sources, raw_json
        ) VALUES (
            :inn, :cn, :st, :dn, :ok,
            :okd, :act, :ad,
            :ph, :em, :wb, :src, :raw
        )
        ON CONFLICT(inn) DO UPDATE SET
            company_name      = excluded.company_name,
            status            = excluded.status,
            director_name     = excluded.director_name,
            okved_main        = excluded.okved_main,
            okved_description = COALESCE(excluded.okved_description, contacts.okved_description),
            activity_note     = COALESCE(excluded.activity_note, contacts.activity_note),
            address           = excluded.address,
            phones            = COALESCE(excluded.phones, contacts.phones),
            emails            = COALESCE(excluded.emails, contacts.emails),
            websites          = COALESCE(excluded.websites, contacts.websites),
            sources           = excluded.sources,
            raw_json          = excluded.raw_json
    ");
    $stmt->execute([
        ':inn' => $inn,
        ':cn'  => $companyName,
        ':st'  => $status,
        ':dn'  => $directorName,
        ':ok'  => $okvedMain,
        ':okd' => $okvedDescription,
        ':act' => $activityNote,
        ':ad'  => $address,
        ':ph'  => $checkoPhones,
        ':em'  => $checkoEmails,
        ':wb'  => $checkoWebsites,
        ':src' => implode(', ', $sources),
        ':raw' => $response . ($checkoRaw ? "\n---\n" . $checkoRaw : ''),
    ]);
    $processed++;
}

$msg = 'ok=' . $processed;
if ($errors) {
    $msg .= '&err=' . urlencode('Проблемы: ' . implode('; ', array_slice($errors, 0, 5)));
}
header('Location: index.php?' . $msg);
exit;
