<?php
require 'config.php';

$moduloPermissao = 'procuracoes';
$titulo = 'Procuracao Particular';
$subtitulo = 'Acompanhe a situacao das procuracoes particulares';
$tituloTabela = 'Controle de Procuracoes Particulares';
$campoStatus = 'procuracao_particular';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
    'nao_precisa_momento' => 'Nao precisa no momento',
];

include 'includes/pagina_controle_clientes.php';
