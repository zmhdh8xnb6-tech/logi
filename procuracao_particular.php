<?php
require 'config.php';

$titulo = 'Procuracao Particular';
$subtitulo = 'Acompanhe a situacao das procuracoes particulares';
$tituloTabela = 'Controle de Procuracoes Particulares';
$campoStatus = 'procuracao_particular';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
];

include 'includes/pagina_controle_clientes.php';
