<?php
require 'config.php';

$moduloPermissao = 'procuracoes';
$titulo = 'Procuracao Empregador Web';
$subtitulo = 'Acompanhe a situacao das procuracoes Empregador Web';
$tituloTabela = 'Controle de Procuracoes Empregador Web';
$campoStatus = 'procuracao_empregador_web';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
    'nao_tem_funcionario' => 'Nao tem funcionario',
];

include 'includes/pagina_controle_clientes.php';
