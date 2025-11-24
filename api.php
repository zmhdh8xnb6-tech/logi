<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "crud_clientes";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Conexão falhou: ' . $conn->connect_error]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action == 'read') {
    $result = $conn->query("SELECT * FROM clientes ORDER BY id DESC");
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if ($action == 'create' || $action == 'update') {
    $id = $_POST['id'] ?? '';
    $numero = $_POST['numero'] ?? '';
    $cnpj = $_POST['cnpj'] ?? '';
    $nome = $_POST['nome'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $email = $_POST['email'] ?? '';

    if ($id == '') {
        $stmt = $conn->prepare("INSERT INTO clientes (numero, cnpj, nome, endereco, email) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $numero, $cnpj, $nome, $endereco, $email);
        if ($stmt->execute()) {
            echo "ok";
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            echo "Erro ao inserir: " . $stmt->error;
        }
    } else {
        $stmt = $conn->prepare("UPDATE clientes SET numero=?, cnpj=?, nome=?, endereco=?, email=? WHERE id=?");
        $stmt->bind_param("sssssi", $numero, $cnpj, $nome, $endereco, $email, $id);
        if ($stmt->execute()) {
            echo "ok";
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            echo "Erro ao atualizar: " . $stmt->error;
        }
    }
    exit;
}

if ($action == 'delete') {
    $id = $_POST['id'] ?? '';
    $stmt = $conn->prepare("DELETE FROM clientes WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        echo "ok";
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo "Erro ao excluir: " . $stmt->error;
    }
    exit;
}

header('HTTP/1.1 400 Bad Request');
echo json_encode(['error' => 'Ação inválida']);
exit;
?>