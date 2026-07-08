<?php
date_default_timezone_set('America/Sao_Paulo');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auditoria.php';

$ambiente = $_SERVER['SERVER_NAME'] ?? 'localhost';

if ($ambiente === 'localhost' || $ambiente === '127.0.0.1') {
    $centralHost = "localhost";
    $centralDb = "crud_clientes";
    $centralUser = "root";
    $centralPass = "";
    $baseUrl = "http://localhost/projeto_ph";
} else {
    $centralHost = "localhost";
    $centralDb = "u285798939_logi";
    $centralUser = "u285798939_logi";
    $centralPass = "Logi@2026#Sistema";
    $baseUrl = "https://sistemalogi.com.br";
}

try {
    $authPdo = new PDO("mysql:host=$centralHost;dbname=$centralDb;charset=utf8mb4", $centralUser, $centralPass);
    $authPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $authPdo->exec("SET time_zone = '-03:00'");

    $authConn = new mysqli($centralHost, $centralUser, $centralPass, $centralDb);
    $authConn->set_charset('utf8mb4');
    $authConn->query("SET time_zone = '-03:00'");

    $host = $centralHost;
    $db = $centralDb;
    $user = $centralUser;
    $pass = $centralPass;
    $pdo = $authPdo;
    $conn = $authConn;

    atualizarSessaoUsuario($authPdo);

    if (!empty($_SESSION['tenant_db'])) {
        $host = $_SESSION['tenant_host'] ?: $centralHost;
        $db = $_SESSION['tenant_db'];
        $user = $_SESSION['tenant_user'] ?: $centralUser;
        $pass = array_key_exists('tenant_pass', $_SESSION) && !empty($_SESSION['tenant_user'])
            ? $_SESSION['tenant_pass']
            : $centralPass;
    }

    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '-03:00'");

    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '-03:00'");
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}
