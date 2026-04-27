<?php

require 'db.php';
$config = require __DIR__ . '/app_config.php';
$message = '';

$email = trim($_POST["email"] ?? "");

if ($email === "") {
    die("Email is required.");
}

$token = bin2hex(random_bytes(16));

$token_hash = hash("sha256", $token);

$expiry = date("Y-m-d H:i:s", time() + 60 * 30);

$sql = "UPDATE doctors
        SET reset_token_hash = ?,
            reset_token_expires_at = ?
        WHERE email = ?";

$stmt = $conn->prepare($sql);

$stmt->bind_param("sss", $token_hash, $expiry, $email);

$stmt->execute();

if ($conn->affected_rows) {

    $base_url = rtrim($config['app']['base_url'], '/');
    $reset_link = $base_url . '/reset-password.php?token=' . urlencode($token);
    try {
        $mail = require __DIR__ . "/mailer.php";
        $mail->setFrom($config['mail']['from_email'], $config['mail']['from_name']);
        $mail->addAddress($email);
        $mail->Subject = "Password Reset";
        $mail->Body = <<<END

        Click <a href="$reset_link">here</a>
        to reset your password.

        END;

        $mail->send();
        $message = "Message sent, please check your inbox.";

    } catch (Throwable $e) {
        $message = "Password reset email could not be sent. " . $e->getMessage();

    }

} else {
    $message = "If that email is registered, a password reset link has been prepared.";
}

echo $message;
