<?php
require 'config.php';

exigirLogin();

$empresaId = (int)($_POST['empresa_id'] ?? $_GET['empresa_id'] ?? 0);
$retorno = $_POST['retorno'] ?? $_SERVER['HTTP_REFERER'] ?? 'home.php';

if (!multiempresaDisponivel($pdo)) {
    header('Location: home.php');
    exit;
}

$empresa = null;

foreach (empresasDisponiveis($pdo) as $empresaDisponivel) {
    if ((int)$empresaDisponivel['id'] === $empresaId) {
        $empresa = $empresaDisponivel;
        break;
    }
}

if ($empresa) {
    $_SESSION['empresa_id'] = (int)$empresa['id'];
    $_SESSION['empresa_nome'] = (string)$empresa['nome'];
}

$retorno = is_string($retorno) ? $retorno : 'home.php';

if (preg_match('/^https?:\/\//i', $retorno)) {
    $retorno = 'home.php';
}

header('Location: ' . $retorno);
exit;
