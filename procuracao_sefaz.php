<?php
require 'config.php';

$titulo = 'Procuracao SEFAZ';
$subtitulo = 'Acompanhe a situacao das procuracoes SEFAZ';
$tituloTabela = 'Controle de Procuracoes SEFAZ';
$campoStatus = 'procuracao_sefaz';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
    'goias' => 'Goias',
];

include 'includes/pagina_controle_clientes.php';
