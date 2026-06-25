<?php
require 'config.php';

$_SESSION["mensagem"] = "Cadastro público desativado. Solicite acesso ao administrador do sistema.";
$_SESSION["tipoMensagem"] = "warning";

header("Location: login.php");
exit;
