<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . "/vendor/autoload.php";
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
