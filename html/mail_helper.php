<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendCredentialsEmail($toEmail, $fullName, $tabelNumber, $password) {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/mail_config.php';
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        
        if (!empty(SMTP_USER)) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
        }
        if (!empty(SMTP_SECURE)) {
            $mail->SMTPSecure = SMTP_SECURE;
        }
        
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $fullName);
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = '🌿 Добро пожаловать в SalesCRM! Учетные данные доступа';
        
        $body = "
        <html>
        <head>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 0; background: #f0f4f0; }
                .container { max-width: 550px; margin: 30px auto; background: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #1e5631 0%, #4c9a2a 100%); color: white; padding: 30px 25px; text-align: center; }
                .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
                .header p { margin: 8px 0 0; opacity: 0.9; font-size: 14px; }
                .content { padding: 30px 25px 25px; }
                .greeting { font-size: 22px; font-weight: 600; color: #1e5631; margin-bottom: 10px; }
                .message { color: #3b5240; line-height: 1.5; margin-bottom: 25px; }
                .credentials { background: #f2f8f0; border-left: 4px solid #4c9a2a; padding: 20px; border-radius: 12px; margin: 20px 0; }
                .cred-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e0e8dc; }
                .cred-label { font-weight: 600; color: #2e5c2e; }
                .cred-value { font-family: monospace; font-size: 15px; background: white; padding: 3px 10px; border-radius: 20px; color: #1e5631; }
                .warning { background: #fff9e6; border-left: 4px solid #f5b042; padding: 15px; border-radius: 10px; margin: 20px 0; font-size: 13px; color: #8a6e2b; }
                .btn { display: inline-block; background: #4c9a2a; color: white; text-decoration: none; padding: 12px 28px; border-radius: 30px; font-weight: 500; margin-top: 10px; transition: 0.3s; }
                .btn:hover { background: #1e5631; transform: translateY(-2px); }
                .footer { text-align: center; padding: 20px; background: #f8faf7; font-size: 11px; color: #8ba888; border-top: 1px solid #e0e8dc; }
                .brand { font-weight: 600; color: #4c9a2a; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🌿 Sales CRM</h1>
                    <p>Система управления продажами</p>
                </div>
                <div class='content'>
                    <div class='greeting'>Здравствуйте, $fullName!</div>
                    <div class='message'>Рады приветствовать вас в нашей системе! Учётная запись успешно создана. Ниже ваши данные для входа.</div>
                    
                    <div class='credentials'>
                        <div class='cred-item'>
                            <span class='cred-label'>👤 Табельный номер:</span>
                            <span class='cred-value'>$tabelNumber</span>
                        </div>
                        <div class='cred-item'>
                            <span class='cred-label'>🔐 Пароль:</span>
                            <span class='cred-value'>$password</span>
                        </div>
                        <div class='cred-item'>
                            <span class='cred-label'>🌐 Портал:</span>
                            <span class='cred-value'><a href='http://5.129.248.239' target='_blank' style='color:#1e5631;'>http://5.129.248.239</a></span>
                        </div>
                    </div>
                    
                    <div class='warning'>
                        📌 <strong>Важно!</strong> Рекомендуем сменить пароль после первого входа в личном кабинете.
                    </div>
                    
                    <div style='text-align: center;'>
                        <a href='http://5.129.248.239' class='btn'>🚀 Войти в CRM</a>
                    </div>
                </div>
                <div class='footer'>
                    <p>© 2026 <span class='brand'>Sales CRM</span> — Умные продажи. Все права защищены.</p>
                    <p>Это письмо сгенерировано автоматически, отвечать на него не нужно.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->Body = $body;
        $mail->AltBody = "Здравствуйте, $fullName!\n\nВаша учётная запись в Sales CRM создана.\n\nТабельный номер: $tabelNumber\nПароль: $password\nСсылка: http://5.129.248.239\n\nРекомендуем сменить пароль после входа.\n\n---\nSales CRM - Система управления продажами";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mail sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

function sendNotification($toEmail, $subject, $message) {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/mail_config.php';
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        
        if (!empty(SMTP_USER)) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
        }
        if (!empty(SMTP_SECURE)) {
            $mail->SMTPSecure = SMTP_SECURE;
        }
        
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("Notification failed: " . $mail->ErrorInfo);
        return false;
    }
}
?>
