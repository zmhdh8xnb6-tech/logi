<?php
$conn = new mysqli("localhost", "root", "", "crud_clientes");

if ($conn->connect_error) {
    die("Erro na conexão: " . $conn->connect_error);
}

$token = $_GET['token'] ?? '';

if ($token === '') {
    die("Token inválido.");
}

$stmt = $conn->prepare("SELECT id FROM usuarios WHERE token_verificacao = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 1) {
    $stmt = $conn->prepare("UPDATE usuarios SET email_verificado = 1, token_verificacao = NULL WHERE token_verificacao = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
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
