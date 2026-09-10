<?php

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function configuracaoSmtpLogi(): array
{
    $configuracao = [
        'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
        'porta' => (int)(getenv('SMTP_PORT') ?: 587),
        'usuario' => getenv('SMTP_USERNAME') ?: 'phsolucoesemti@gmail.com',
        'senha' => getenv('SMTP_PASSWORD') ?: 'zputfsfvzsdgueqe',
        'remetente_email' => getenv('SMTP_FROM_ADDRESS') ?: '',
        'remetente_nome' => getenv('SMTP_FROM_NAME') ?: 'FECON LOGISTICA',
        'seguranca' => getenv('SMTP_ENCRYPTION') ?: 'tls',
    ];

    $arquivoConfiguracao = __DIR__ . '/storage/email_config.php';
    if (!is_file($arquivoConfiguracao)) {
        return $configuracao;
    }

    $configuracaoLocal = require $arquivoConfiguracao;
    if (!is_array($configuracaoLocal) || empty($configuracaoLocal['ativo'])) {
        return $configuracao;
    }

    foreach (array_keys($configuracao) as $chave) {
        if (array_key_exists($chave, $configuracaoLocal) && $configuracaoLocal[$chave] !== '') {
            $configuracao[$chave] = $chave === 'porta'
                ? (int)$configuracaoLocal[$chave]
                : trim((string)$configuracaoLocal[$chave]);
        }
    }

    if (
        $configuracao['usuario'] === ''
        || $configuracao['senha'] === ''
        || str_contains($configuracao['usuario'], 'COLE_')
        || str_contains($configuracao['senha'], 'COLE_')
    ) {
        throw new RuntimeException('A configuração SMTP do Brevo está incompleta. Informe o login e a chave SMTP em storage/email_config.php.');
    }

    return $configuracao;
}

function mensagemErroEmailAmigavel(?string $erro): string
{
    $erro = trim((string)$erro);
    $erroNormalizado = strtolower($erro);

    if (
        str_contains($erroNormalizado, 'daily user sending limit exceeded')
        || str_contains($erroNormalizado, 'daily smtp relay limit exceeded')
    ) {
        return 'A conta de e-mail do sistema atingiu o limite diário de envios do Gmail. '
            . 'Nenhum e-mail foi enviado. Aguarde a liberação do Google, que normalmente ocorre dentro de 1 a 24 horas, e tente novamente.';
    }

    return 'O servidor de e-mail não confirmou o envio. Tente novamente mais tarde.';
}

function adicionarDestinatariosEmail(PHPMailer $mail, string|array $para, string $nome = ''): void
{
    $destinatarios = is_array($para) ? $para : [$para];
    foreach ($destinatarios as $indice => $destinatario) {
        $endereco = trim((string)$destinatario);
        if (!filter_var($endereco, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Endereço de e-mail inválido: ' . $endereco);
        }

        $mail->addAddress($endereco, $indice === 0 ? $nome : '');
    }
}

function enviarEmailComAnexos($para, $nome, $assunto, $mensagemHtml, array $anexos = [], ?string &$erro = null)
{
    $mail = new PHPMailer(true);

    try {
        $smtp = configuracaoSmtpLogi();
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['usuario'];
        $mail->Password = $smtp['senha'];
        $mail->Port = $smtp['porta'];

        if (strtolower($smtp['seguranca']) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (strtolower($smtp['seguranca']) === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
        }

        $mail->setFrom($smtp['remetente_email'] ?: $mail->Username, $smtp['remetente_nome']);
        adicionarDestinatariosEmail($mail, $para, (string)$nome);

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
