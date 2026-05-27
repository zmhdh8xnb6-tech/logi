<?php
require 'config.php';

$action = $_GET['action'] ?? '';

if ($action === 'read') {

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 10;

    $offset = ($page - 1) * $limit;

    $stmtTotal = $pdo->query("SELECT COUNT(*) FROM clientes");
    $total = $stmtTotal->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT * 
        FROM clientes
        ORDER BY CAST(codigo AS UNSIGNED) ASC
        LIMIT :limit OFFSET :offset
    ");

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        "data" => $data,
        "total" => $total,
        "page" => $page,
        "limit" => $limit
    ]);

    exit;
}

if ($action === 'create' || $action === 'update') {

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
    $cadastro_df_legal = $_POST['cadastro_df_legal'] ?? '';
    $alvara = $_POST['alvara'] ?? '';
    $contador = $_POST['contador'] ?? '';
    $cadastro_crf = $_POST['cadastro_crf'] ?? '';

    $vencimento_certificado = !empty($_POST['vencimento_certificado'])
        ? $_POST['vencimento_certificado']
        : null;

    if ($id == '') {
        $stmt = $pdo->prepare("
            SELECT id 
            FROM clientes 
            WHERE documento = ?
        ");

        $stmt->execute([$documento]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id 
            FROM clientes
            WHERE documento = ?
            AND id <> ?
        ");

        $stmt->execute([$documento, $id]);
    }

    if ($stmt->rowCount() > 0) {
        echo "duplicado";
        exit;
    }

    if ($id == '') {

        $stmt = $pdo->prepare("
            INSERT INTO clientes (
                codigo,
                documento,
                nome,
                nome_fantasia,
                endereco,
                numero_endereco,
                complemento,
                bairro,
                cidade,
                uf,
                cep,
                telefone,
                inscricao_estadual,
                nire,
                email,
                vencimento_certificado,
                cadastro_df_legal,
                alvara,
                contador,
                cadastro_crf
            )
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $ok = $stmt->execute([
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
            $vencimento_certificado,
            $cadastro_df_legal,
            $alvara,
            $contador,
            $cadastro_crf
        ]);
    } else {

        $stmt = $pdo->prepare("
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
                email=?,
                vencimento_certificado=?,
                cadastro_df_legal=?,
                alvara=?,
                contador=?,
                cadastro_crf=?
            WHERE id=?
        ");

        $ok = $stmt->execute([
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
            $vencimento_certificado,
            $cadastro_df_legal,
            $alvara,
            $contador,
            $cadastro_crf,
            $id
        ]);
    }

    echo $ok ? 'ok' : 'erro';
    exit;
}

if ($action === 'delete') {

    $id = $_POST['id'] ?? '';

    $stmt = $pdo->prepare("
        DELETE FROM clientes
        WHERE id = ?
    ");

    $ok = $stmt->execute([$id]);

    echo $ok ? 'ok' : 'erro';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'error' => 'Ação inválida'
]);

exit;
