<?php
session_start();

$ambiente = $_SERVER['SERVER_NAME'];

if ($ambiente === 'localhost') {
    $host = "localhost";
    $db = "crud_clientes";
    $user = "root";
    $pass = "";
    $baseUrl = "http://localhost/projeto_ph";
} else {
    $host = "SEU_HOST_MYSQL_ONLINE";
    $db = "SEU_BANCO_MYSQL_ONLINE";
    $user = "SEU_USUARIO_MYSQL_ONLINE";
    $pass = "SUA_SENHA_MYSQL_ONLINE";
    $baseUrl = "https://SEU_SITE_ONLINE";
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}
