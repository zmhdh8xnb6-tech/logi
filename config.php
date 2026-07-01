<?php
date_default_timezone_set('America/Sao_Paulo');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auditoria.php';

$ambiente = $_SERVER['SERVER_NAME'] ?? 'localhost';

if ($ambiente === 'localhost' || $ambiente === '127.0.0.1') {
    $host = "localhost";
    $db = "crud_clientes";
    $user = "root";
    $pass = "";
    $baseUrl = "http://localhost/projeto_ph";
} else {
    $host = "localhost";
    $db = "u285798939_logi";
    $user = "u285798939_logi";
    $pass = "Logi@2026#Sistema";
    $baseUrl = "https://sistemalogi.com.br";
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');

    atualizarSessaoUsuario($pdo);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}
