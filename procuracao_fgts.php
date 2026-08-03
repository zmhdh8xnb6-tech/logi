<?php
require 'config.php';

$moduloPermissao = 'procuracoes';
$titulo = 'Procuracao FGTS';
$subtitulo = 'Acompanhe a situacao e o vencimento das procuracoes FGTS';
$tituloTabela = 'Controle de Procuracoes FGTS';
$campoStatus = 'procuracao_fgts';
$campoVencimento = 'vencimento_procuracao_fgts';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
    'nao_precisa_momento' => 'Nao precisa no momento',
];

include 'includes/pagina_controle_clientes.php';
