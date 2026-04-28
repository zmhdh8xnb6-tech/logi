<?php
require 'mailer.php';

$enviado = enviarEmail(
    'seuemail@gmail.com',
    'Teste',
    'Teste do sistema',
    '<b>Funcionou!</b>'
);

if ($enviado) {
    echo "Email enviado com sucesso!";
} else {
    echo "Erro ao enviar email.";
}
