<?php
require 'config.php';

$moduloPermissao = 'contador';
$titulo = 'Contador';
$subtitulo = 'Acompanhe quais clientes possuem contador informado';
$tituloTabela = 'Controle de Contador';
$campoStatus = 'contador';
$opcoesStatus = [
    'sim' => 'Contador ativo',
    'nao' => 'Sem contador',
    'nao_precisa_momento' => 'Nao precisa no momento',
];

include 'includes/pagina_controle_clientes.php';
