<?php
require 'config.php';

$moduloPermissao = 'contratos';
$titulo = 'Contrato de Prestacao de Servicos';
$subtitulo = 'Acompanhe quais clientes possuem contrato de prestacao de servicos';
$tituloTabela = 'Controle de Contratos';
$campoStatus = 'contrato_prestacao_servicos';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
    'nao_precisa_momento' => 'Nao precisa no momento',
];

include 'includes/pagina_controle_clientes.php';
