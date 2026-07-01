<?php
require 'config.php';

$token = $_GET['token'] ?? '';

if ($token === '') {
    die("Token inválido.");
}

$stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE token_verificacao = ?");
$stmt->execute([$token]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if ($usuario) {
    $stmt = $pdo->prepare("
        UPDATE usuarios
        SET email_verificado = 1, token_verificacao = NULL
        WHERE token_verificacao = ?
    ");
    $stmt->execute([$token]);
    registrarAuditoria(
        $pdo,
        'Usuários',
        'verificar_email',
        'usuario',
        $usuario['id'],
        'E-mail do usuário ' . $usuario['nome'] . ' foi verificado',
        ['email_verificado' => 0],
        ['email_verificado' => 1],
        (int)$usuario['id'],
        $usuario['nome']
    );
?>
    <!DOCTYPE html>
    <html lang="pt-br">

    <head>
        <meta charset="UTF-8">
        <title>E-mail verificado</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>

    <body class="bg-light">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="alert alert-success text-center">
                        E-mail verificado com sucesso!<br>
                        <a href="login.php" class="btn btn-primary mt-3">Ir para o login</a>
                    </div>
                </div>
            </div>
        </div>
    </body>

    </html>
<?php
} else {
    echo "Token inválido ou expirado.";
}
