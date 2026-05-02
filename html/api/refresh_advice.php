<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$user_id = $_SESSION['user_id'];
$cache_file = "/tmp/ai_advice_{$user_id}.cache";
if (file_exists($cache_file)) {
    unlink($cache_file);
}
echo json_encode(['success' => true]);
?>
