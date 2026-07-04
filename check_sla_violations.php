#!/usr/bin/env php
<?php
require_once 'db.php';
$now = date('Y-m-d H:i:s');

// Просрочка первого ответа
$violations = $pdo->prepare("SELECT id FROM support_requests 
    WHERE first_response_deadline < ? AND first_response_at IS NULL AND status != 'closed'
    AND id NOT IN (SELECT request_id FROM sla_violations WHERE violation_type='first_response')");
$violations->execute([$now]);
$rows = $violations->fetchAll();
foreach ($rows as $r) {
    $pdo->prepare("INSERT INTO sla_violations (request_id, violation_type) VALUES (?, 'first_response')")->execute([$r['id']]);
    echo "Нарушение первого ответа: request_id {$r['id']}\n";
}

// Просрочка решения
$violations = $pdo->prepare("SELECT id FROM support_requests 
    WHERE resolution_deadline < ? AND resolved_at IS NULL AND status != 'closed'
    AND id NOT IN (SELECT request_id FROM sla_violations WHERE violation_type='resolution')");
$violations->execute([$now]);
$rows = $violations->fetchAll();
foreach ($rows as $r) {
    $pdo->prepare("INSERT INTO sla_violations (request_id, violation_type) VALUES (?, 'resolution')")->execute([$r['id']]);
    echo "Нарушение решения: request_id {$r['id']}\n";
}
echo "Готово\n";
