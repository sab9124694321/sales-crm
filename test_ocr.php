<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_image'])) {
    $tmpPath = $_FILES['test_image']['tmp_name'];
    $pythonScript = '/var/www/html/ocr_parser.py';
    $cmd = "/opt/ocr-venv/bin/python3 " . escapeshellarg($pythonScript) . " " . escapeshellarg($tmpPath) . " 2>&1";
    $output = shell_exec($cmd);
    echo "<pre>OUTPUT:\n" . htmlspecialchars($output) . "</pre>";
    exit;
}
?>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="test_image" accept="image/*">
    <button type="submit">Тест OCR</button>
</form>
