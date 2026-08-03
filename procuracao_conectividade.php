<?php
require 'config.php';

$moduloPermissao = 'procuracoes';
$titulo = 'Procuracao Conectividade Social';
$subtitulo = 'Acompanhe a situacao e o vencimento das procuracoes Conectividade';
$tituloTabela = 'Controle de Procuracoes Conectividade';
$campoStatus = 'procuracao_conectividade';
$campoVencimento = 'vencimento_procuracao_conectividade';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
    'nao_precisa_momento' => 'Nao precisa no momento',
];

include 'includes/pagina_controle_clientes.php';
