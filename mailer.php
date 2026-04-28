<?php

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function enviarEmail($para, $nome, $assunto, $mensagemHtml)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'phsolucoesemti@gmail.com';
        $mail->Password = 'zputfsfvzsdgueqe';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('SEU_EMAIL@gmail.com', 'Sistema');
        $mail->addAddress($para, $nome);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $assunto;
        $mail->Body = $mensagemHtml;

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
