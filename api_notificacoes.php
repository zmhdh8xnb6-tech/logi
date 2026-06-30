<?php
require 'config.php';
require 'includes/pendencias_contador.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!usuarioLogado()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Sessão expirada.']);
    exit;
}

if (!usuarioPode('pendencias')) {
    http_response_code(403);
    echo json_encode(['erro' => 'Sem permissão para consultar pendências.']);
    exit;
}

try {
    echo json_encode([
        'total' => contarPendenciasSistema($pdo),
        'atualizado_em' => date('c'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Não foi possível consultar as pendências.']);
}
