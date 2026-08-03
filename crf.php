<?php
require 'config.php';

$moduloPermissao = 'crf';
$titulo = 'Cadastro CRF';
$subtitulo = 'Acompanhe o cadastro CRF dos clientes';
$tituloTabela = 'Controle de Cadastro CRF';
$campoStatus = 'cadastro_crf';
$opcoesStatus = [
    'cadastrado' => 'Cadastrado',
    'nao_cadastrado' => 'Nao cadastrado',
    'nao_precisa_momento' => 'Nao precisa no momento',
];

include 'includes/pagina_controle_clientes.php';
