<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$autoload_path = __DIR__ . "/vendor/autoload.php";

if (!file_exists($autoload_path)) {
    throw new RuntimeException(
        'PHPMailer is not installed. Run "composer install" in the project folder to create vendor/autoload.php.'
    );
}

require $autoload_path;
$config = require __DIR__ . "/app_config.php";
$mail_config = $config['mail'];

$mail = new PHPMailer(true);

// $mail->SMTPDebug = SMTP::DEBUG_SERVER;

$mail->isSMTP();
$mail->SMTPAuth = true;

$mail->Host = $mail_config['host'];
$mail->SMTPSecure = $mail_config['encryption'] === 'tls'
    ? PHPMailer::ENCRYPTION_STARTTLS
    : PHPMailer::ENCRYPTION_SMTPS;
$mail->Port = $mail_config['port'];
$mail->Username = $mail_config['username'];
$mail->Password = $mail_config['password'];
$mail->CharSet = "UTF-8";

$mail->isHtml(true);

return $mail;
