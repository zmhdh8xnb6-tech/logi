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
    $nome = $_POST['nome'] ?? '';
    $email = $_POST['email'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $numero_empresa = $_POST['numero_empresa'] ?? '';
    $cnpj = $_POST['cnpj'] ?? '';

    if ($id == '') {
        $stmt = $conn->prepare("INSERT INTO clientes (nome, email, telefone, numero_empresa, cnpj) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $nome, $email, $telefone, $numero_empresa, $cnpj);
        if ($stmt->execute()) {
            echo "ok";
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            echo "Erro ao inserir: " . $stmt->error;
        }
    } else {
        $stmt = $conn->prepare("UPDATE clientes SET nome=?, email=?, telefone=?, numero_empresa=?, cnpj=? WHERE id=?");
        $stmt->bind_param("sssssi", $nome, $email, $telefone, $numero_empresa, $cnpj, $id);
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