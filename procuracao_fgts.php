<?php
require 'config.php';

$titulo = 'Procuracao FGTS';
$subtitulo = 'Acompanhe a situacao e o vencimento das procuracoes FGTS';
$tituloTabela = 'Controle de Procuracoes FGTS';
$campoStatus = 'procuracao_fgts';
$campoVencimento = 'vencimento_procuracao_fgts';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
];

include 'includes/pagina_controle_clientes.php';
