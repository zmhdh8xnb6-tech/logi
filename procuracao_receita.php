<?php
require 'config.php';

$titulo = 'Procuracao Receita Federal';
$subtitulo = 'Acompanhe a situacao e o vencimento das procuracoes Receita Federal';
$tituloTabela = 'Controle de Procuracoes Receita Federal';
$campoStatus = 'procuracao_receita_federal';
$campoVencimento = 'vencimento_procuracao_receita_federal';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
];

include 'includes/pagina_controle_clientes.php';
