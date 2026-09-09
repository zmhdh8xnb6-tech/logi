<?php

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function enviarEmailComAnexos($para, $nome, $assunto, $mensagemHtml, array $anexos = [], ?string &$erro = null)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USERNAME') ?: 'phsolucoesemti@gmail.com';
        $mail->Password = getenv('SMTP_PASSWORD') ?: 'zputfsfvzsdgueqe';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);

        $mail->setFrom(getenv('SMTP_FROM_ADDRESS') ?: $mail->Username, getenv('SMTP_FROM_NAME') ?: 'Sistema Logi');
        $mail->addAddress($para, $nome);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $assunto;
        $mail->Body = $mensagemHtml;
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $mensagemHtml))));

        foreach ($anexos as $anexo) {
            $nomeArquivo = trim((string)($anexo['nome'] ?? 'anexo.pdf'));
            $tipo = trim((string)($anexo['tipo'] ?? 'application/octet-stream'));

            if (array_key_exists('conteudo', $anexo)) {
                $mail->addStringAttachment((string)$anexo['conteudo'], $nomeArquivo, PHPMailer::ENCODING_BASE64, $tipo);
                continue;
            }

            $caminho = (string)($anexo['caminho'] ?? '');
            if ($caminho !== '' && is_file($caminho)) {
                $mail->addAttachment($caminho, $nomeArquivo, PHPMailer::ENCODING_BASE64, $tipo);
            }
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        $erro = $mail->ErrorInfo ?: $e->getMessage();
        return false;
    }
}

function enviarEmail($para, $nome, $assunto, $mensagemHtml)
{
    return enviarEmailComAnexos($para, $nome, $assunto, $mensagemHtml);
}
