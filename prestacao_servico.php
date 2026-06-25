<?php
require 'config.php';

$titulo = 'Contrato de Prestacao de Servicos';
$subtitulo = 'Acompanhe quais clientes possuem contrato de prestacao de servicos';
$tituloTabela = 'Controle de Contratos';
$campoStatus = 'contrato_prestacao_servicos';
$opcoesStatus = [
    'possui' => 'Possui',
    'nao_possui' => 'Nao possui',
];

include 'includes/pagina_controle_clientes.php';
