<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== ДИАГНОСТИКА СЕРВЕРА ===\n";
echo "Время: " . date('Y-m-d H:i:s') . "\n";
echo "Сервер: " . php_uname() . "\n";
echo "Корень сайта: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "Текущий файл: " . __FILE__ . "\n\n";

echo "=== 1. TESSERACT ===\n";
$which_tesseract = shell_exec('which tesseract 2>&1');
echo "which tesseract: " . trim($which_tesseract) . "\n";
$version = shell_exec('tesseract --version 2>&1');
echo "tesseract --version:\n" . ($version ?: "НЕ НАЙДЕН") . "\n";
$langs = shell_exec('tesseract --list-langs 2>&1');
echo "Языки: " . ($langs ?: "Не найдены") . "\n\n";

echo "=== 2. PYTHON ===\n";
$which_python = shell_exec('which python3 2>&1');
echo "which python3: " . trim($which_python) . "\n";
$py_version = shell_exec('python3 --version 2>&1');
echo "python3 --version: " . ($py_version ?: "НЕ НАЙДЕН") . "\n";

echo "\n=== 3. PYTHON ПАКЕТЫ ===\n";
$pytesseract = shell_exec('python3 -c "import pytesseract; print(\'pytesseract: OK, version:\', pytesseract.get_tesseract_version())" 2>&1');
echo "pytesseract: " . ($pytesseract ?: "НЕ УСТАНОВЛЕН") . "\n";
$pil = shell_exec('python3 -c "from PIL import Image; print(\'Pillow: OK\')" 2>&1');
echo "Pillow: " . ($pil ?: "НЕ УСТАНОВЛЕН") . "\n";

echo "\n=== 4. ФАЙЛЫ OCR ===\n";
$doc_root = $_SERVER['DOCUMENT_ROOT'];
$files_to_check = ['ocr_parser.py', 'ocr_hybrid.php', 'ocr_upload_form.php', 'ocr_debug.log'];
foreach ($files_to_check as $file) {
    $path = $doc_root . '/' . $file;
    echo "$file: " . (file_exists($path) ? "ЕСТЬ (" . filesize($path) . " bytes)" : "НЕТ") . "\n";
}

echo "\n=== 5. ПОИСК ocr_parser.py ВЕЗДЕ ===\n";
$find_parser = shell_exec('find /var /home /root /tmp -name "ocr_parser.py" -type f 2>/dev/null | head -5');
echo ($find_parser ?: "Не найден") . "\n";

echo "\n=== 6. PHP НАСТРОЙКИ ===\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";

echo "\n=== 7. ТЕСТ TESSERACT НА PHP ===\n";
$test_text = "ИНН 1234567890\nТел: +7 900 123-45-67\nг. Москва, ул. Ленина, д. 1";
$tmp_txt = tempnam(sys_get_temp_dir(), 'ocr_test_') . '.txt';
file_put_contents($tmp_txt, $test_text);
$tmp_out = tempnam(sys_get_temp_dir(), 'ocr_test_');
$cmd = 'tesseract ' . escapeshellarg($tmp_txt) . ' ' . escapeshellarg($tmp_out) . ' -l rus 2>&1';
$output = shell_exec($cmd);
echo "Команда: $cmd\n";
echo "Результат: " . ($output ?: "OK (пусто)") . "\n";
$result_file = $tmp_out . '.txt';
if (file_exists($result_file)) {
    echo "Распознано: " . file_get_contents($result_file) . "\n";
    unlink($result_file);
} else {
    echo "Файл результата не создан — tesseract не работает\n";
}
unlink($tmp_txt);

echo "\n=== ГОТОВО ===\n";
