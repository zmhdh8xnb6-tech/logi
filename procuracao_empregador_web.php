<?php
require 'config.php';

$titulo = 'Procuracao Empregador Web';
$subtitulo = 'Acompanhe a situacao das procuracoes Empregador Web';
$tituloTabela = 'Controle de Procuracoes Empregador Web';
$campoStatus = 'procuracao_empregador_web';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
];

include 'includes/pagina_controle_clientes.php';
