<?php
/**
 * Универсальный модуль отправки email через Gmail SMTP
 * Использование: require_once 'mailer.php'; send_email($to, $subject, $body, $attachments = []);
 */

// Прямой require файлов PHPMailer (без Composer)
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Отправка email
 * 
 * @param string $to Адрес получателя
 * @param string $subject Тема письма
 * @param string $body HTML-тело письма
 * @param array $attachments Вложения [['path' => '/path/to/file.jpg', 'name' => 'cert.jpg']]
 * @return array ['success' => bool, 'message' => string]
 */
function send_email($to, $subject, $body, $attachments = []) {
    $mail = new PHPMailer(true);

    try {
        // Настройки SMTP (Gmail)
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 's9124694321@gmail.com';  // Gmail
        $mail->Password = 'mvvk qypp jbsc mkvu';      // App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        // Кодировка
        $mail->CharSet = 'UTF-8';

        // Отправитель
        $mail->setFrom('s9124694321@gmail.com', 'SZB-Sales CRM');
        $mail->addReplyTo('s9124694321@gmail.com', 'SZB-Sales Поддержка');

        // Получатель
        $mail->addAddress($to);

        // Контент
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

        // Вложения
        foreach ($attachments as $att) {
            if (file_exists($att['path'])) {
                $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
            }
        }

        $mail->send();
        return ['success' => true, 'message' => 'Письмо отправлено'];

    } catch (Exception $e) {
        return ['success' => false, 'message' => $mail->ErrorInfo];
    }
}

/**
 * Шаблон письма для охотника
 */
function hunter_email_template($title, $content, $hunter_name = '') {
    $name = $hunter_name ? htmlspecialchars($hunter_name) : 'Охотник';
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); padding: 30px; text-align: center; }
        .header h1 { color: white; margin: 0; font-size: 22px; }
        .header .logo { font-size: 40px; margin-bottom: 10px; }
        .body { padding: 30px; }
        .body h2 { color: #1e293b; font-size: 18px; margin-bottom: 15px; }
        .body p { color: #4b5563; line-height: 1.6; margin-bottom: 15px; }
        .highlight { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 15px; margin: 15px 0; border-radius: 0 8px 8px 0; }
        .code { background: #1e293b; color: #22c55e; font-family: 'Courier New', monospace; font-size: 24px; font-weight: bold; padding: 20px; text-align: center; border-radius: 12px; letter-spacing: 4px; margin: 15px 0; }
        .footer { background: #f8fafc; padding: 20px; text-align: center; font-size: 12px; color: #9ca3af; }
        .btn { display: inline-block; background: #3b82f6; color: white; padding: 12px 30px; border-radius: 8px; text-decoration: none; font-weight: 500; margin: 10px 0; }
        .xp { color: #f59e0b; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🎯</div>
            <h1>Программа «Охотник»</h1>
        </div>
        <div class="body">
            <h2>Привет, {$name}! 👋</h2>
            {$content}
        </div>
        <div class="footer">
            <p>© 2026 SZB-Sales · Программа лояльности «Охотник»</p>
            <p>Есть вопросы? Пишите на <a href="mailto:s9124694321@gmail.com">s9124694321@gmail.com</a></p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Отправка письма охотнику
 */
function send_hunter_email($to, $subject, $title, $content, $hunter_name = '', $attachments = []) {
    $body = hunter_email_template($title, $content, $hunter_name);
    return send_email($to, $subject, $body, $attachments);
}

/**
 * Отправка сертификата охотнику
 */
function send_certificate_email($to, $hunter_name, $cert_name, $cert_code, $cert_image_path) {
    $subject = "🎁 Ваш сертификат: {$cert_name}";

    $content = <<<HTML
<p>Поздравляем с покупкой! 🎉</p>

<div class="highlight">
    <strong>{$cert_name}</strong><br>
    Стоимость: <span class="xp">списано с баланса XP</span>
</div>

<p>Ваш уникальный код сертификата:</p>
<div class="code">{$cert_code}</div>

<p>📎 Картинка сертификата во вложении к письму.</p>

<p><strong>Как использовать:</strong></p>
<ol>
    <li>Сохраните картинку сертификата</li>
    <li>Покажите код при оплате</li>
    <li>Или используйте онлайн-код при заказе</li>
</ol>

<p style="text-align:center;">
    <a href="https://szb-sales.ru/hunter_dashboard.php" class="btn">Перейти в личный кабинет</a>
</p>
HTML;

    $attachments = [];
    if ($cert_image_path && file_exists($cert_image_path)) {
        $attachments[] = ['path' => $cert_image_path, 'name' => basename($cert_image_path)];
    }

    return send_hunter_email($to, $subject, "Ваш сертификат готов!", $content, $hunter_name, $attachments);
}

/**
 * Отправка уведомления о статусе лида
 */
function send_lead_status_email($to, $hunter_name, $lead_name, $status, $bonus = 0) {
    $status_text = [
        'new' => '⏳ На проверке',
        'assigned' => '🔵 В работе',
        'converted' => '✅ Одобрен!',
        'rejected' => '❌ Отклонён'
    ][$status] ?? $status;

    $subject = "🎯 Статус лида обновлён: {$lead_name}";

    $bonus_html = $bonus > 0 ? "<p>Начислено: <span class='xp'>+{$bonus} XP</span></p>" : '';

    $content = <<<HTML
<p>Статус вашего лида <strong>«{$lead_name}»</strong> изменился:</p>

<div class="highlight">
    <strong>{$status_text}</strong>
</div>

{$bonus_html}

<p style="text-align:center;">
    <a href="https://szb-sales.ru/hunter_dashboard.php" class="btn">Проверить в кабинете</a>
</p>
HTML;

    return send_hunter_email($to, $subject, "Обновление статуса лида", $content, $hunter_name);
}

/**
 * Отправка пароля (регистрация / сброс)
 */
function send_password_email($to, $hunter_name, $password, $is_reset = false) {
    $action = $is_reset ? 'Сброс пароля' : 'Регистрация';
    $subject = $is_reset ? "🔐 Новый пароль" : "🎯 Добро пожаловать в программу «Охотник»!";

    $content = <<<HTML
<p>{$action} прошла успешно!</p>

<div class="highlight">
    <strong>Ваш временный пароль:</strong>
</div>
<div class="code">{$password}</div>

<p><strong>⚠️ Важно:</strong> смените пароль при первом входе в личный кабинет.</p>

<p style="text-align:center;">
    <a href="https://szb-sales.ru/hunter_login.php" class="btn">Войти в кабинет</a>
</p>
HTML;

    return send_hunter_email($to, $subject, $action, $content, $hunter_name);
}
