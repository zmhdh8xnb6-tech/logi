<?php
require 'config.php';

$titulo = 'Contador';
$subtitulo = 'Acompanhe quais clientes possuem contador informado';
$tituloTabela = 'Controle de Contador';
$campoStatus = 'contador';
$opcoesStatus = [
    'sim' => 'Sim',
    'nao' => 'Nao',
];

include 'includes/pagina_controle_clientes.php';
