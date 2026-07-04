<?php
$phpmailer_url = 'https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v6.9.1.zip';
$zip_file = '/tmp/phpmailer.zip';
$extract_to = '/var/www/html/';

file_put_contents($zip_file, file_get_contents($phpmailer_url));
$zip = new ZipArchive;
if ($zip->open($zip_file) === TRUE) {
    $zip->extractTo('/tmp/');
    $zip->close();
    if (is_dir('/tmp/PHPMailer-6.9.1')) {
        rename('/tmp/PHPMailer-6.9.1', $extract_to . 'PHPMailer');
    }
    if (!is_dir($extract_to . 'vendor')) {
        mkdir($extract_to . 'vendor', 0777, true);
    }
    $autoload = "<?php\nrequire_once __DIR__ . '/../PHPMailer/src/Exception.php';\nrequire_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';\nrequire_once __DIR__ . '/../PHPMailer/src/SMTP.php';\n";
    file_put_contents($extract_to . 'vendor/autoload.php', $autoload);
    echo "OK: PHPMailer установлен\n";
} else {
    echo "ERROR: не удалось распаковать\n";
}
unlink($zip_file);
