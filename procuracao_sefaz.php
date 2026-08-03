<?php
require 'config.php';

$moduloPermissao = 'procuracoes';
$titulo = 'Procuracao SEFAZ';
$subtitulo = 'Acompanhe a situacao das procuracoes SEFAZ';
$tituloTabela = 'Controle de Procuracoes SEFAZ';
$campoStatus = 'procuracao_sefaz';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
    'nao_precisa_momento' => 'Nao precisa no momento',
    'goias' => 'Goias',
];

include 'includes/pagina_controle_clientes.php';
