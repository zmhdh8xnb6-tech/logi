<?php
require 'config.php';

if (usuarioLogado()) {
    registrarAuditoria(
        $pdo,
        'Autenticação',
        'logout',
        'usuario',
        $_SESSION['usuario_id'],
        'Saiu do sistema'
    );
}

session_unset();
session_destroy();

header("Location: login.php");
exit;
