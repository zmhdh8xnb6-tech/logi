<?php
date_default_timezone_set('America/Sao_Paulo');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$hostServidor = $_SERVER['SERVER_NAME'];

if (
    $hostServidor === 'localhost' ||
    $hostServidor === '127.0.0.1'
) {
    // LOCAL
    $host = "localhost";
    $db = "crud_clientes";
    $user = "root";
    $pass = "";
    $baseUrl = "http://localhost/projeto_ph";
} else {

    // HOSTINGER
    $host = "localhost";
    $db = "u285798939_logi";
    $user = "u285798939_logi";
    $pass = "Logi@2026#Sistema";
    $baseUrl = "https://sistemalogi.com.br";
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}
