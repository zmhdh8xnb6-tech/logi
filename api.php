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

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 10;

    $offset = ($page - 1) * $limit;

    $totalResult = $conn->query("SELECT COUNT(*) as total FROM clientes");
    $totalRow = $totalResult->fetch_assoc();
    $total = $totalRow['total'];

    $stmt = $conn->prepare("SELECT * FROM clientes ORDER BY CAST(codigo AS UNSIGNED) ASC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "data" => $data,
        "total" => $total,
        "page" => $page,
        "limit" => $limit
    ]);
    exit;
}

if ($action == 'create' || $action == 'update') {
    $id = $_POST['id'] ?? '';
    $codigo = $_POST['codigo'] ?? '';
    $documento = $_POST['documento'] ?? '';
    $nome = $_POST['nome'] ?? '';
    $nome_fantasia = $_POST['nome_fantasia'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $numero_endereco = $_POST['numero_endereco'] ?? '';
    $complemento = $_POST['complemento'] ?? '';
    $bairro = $_POST['bairro'] ?? '';
    $cidade = $_POST['cidade'] ?? '';
    $uf = $_POST['uf'] ?? '';
    $cep = $_POST['cep'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $inscricao_estadual = $_POST['inscricao_estadual'] ?? '';
    $nire = $_POST['nire'] ?? '';
    $email = $_POST['email'] ?? '';

    $documento = $_POST['documento'] ?? '';

    if ($id == '') {
        $stmt = $conn->prepare("SELECT id FROM clientes WHERE documento = ?");
        $stmt->bind_param("s", $documento);
    } else {
        $stmt = $conn->prepare("SELECT id FROM clientes WHERE documento = ? AND id <> ?");
        $stmt->bind_param("si", $documento, $id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo "duplicado";
        exit;
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo "duplicado";
        exit;
    }

    if ($id == '') {
        $stmt = $conn->prepare("
            INSERT INTO clientes (
                codigo, documento, nome, nome_fantasia, endereco, numero_endereco,
                complemento, bairro, cidade, uf, cep, telefone,
                inscricao_estadual, nire, email
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sssssssssssssss",
            $codigo,
            $documento,
            $nome,
            $nome_fantasia,
            $endereco,
            $numero_endereco,
            $complemento,
            $bairro,
            $cidade,
            $uf,
            $cep,
            $telefone,
            $inscricao_estadual,
            $nire,
            $email
        );

        if ($stmt->execute()) {
            echo "ok";
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            echo "Erro ao inserir: " . $stmt->error;
        }
    } else {
        $stmt = $conn->prepare("
            UPDATE clientes SET
                codigo=?,
                documento=?,
                nome=?,
                nome_fantasia=?,
                endereco=?,
                numero_endereco=?,
                complemento=?,
                bairro=?,
                cidade=?,
                uf=?,
                cep=?,
                telefone=?,
                inscricao_estadual=?,
                nire=?,
                email=?
            WHERE id=?
        ");
        $stmt->bind_param(
            "sssssssssssssssi",
            $codigo,
            $documento,
            $nome,
            $nome_fantasia,
            $endereco,
            $numero_endereco,
            $complemento,
            $bairro,
            $cidade,
            $uf,
            $cep,
            $telefone,
            $inscricao_estadual,
            $nire,
            $email,
            $id
        );

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
