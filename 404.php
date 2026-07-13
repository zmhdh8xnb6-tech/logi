<?php
http_response_code(404);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$basePath = $basePath === '.' ? '' : $basePath;
$destinoPrincipal = !empty($_SESSION['usuario_id']) ? 'home.php' : 'login.php';

function erroAsset(string $path): string
{
    global $basePath;

    return $basePath . '/' . ltrim($path, '/');
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página não encontrada - Logi</title>
    <link rel="shortcut icon" href="<?= htmlspecialchars(erroAsset('assets/images/logo.svg')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(erroAsset('assets/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(erroAsset('assets/erro.css')) ?>">
</head>

<body class="erro-page">
    <main class="erro-container">
        <section class="erro-card" aria-labelledby="tituloErro">
            <div class="erro-marca">
                <img src="<?= htmlspecialchars(erroAsset('assets/images/logo.svg')) ?>" alt="Logi">
                <span>Logi</span>
            </div>

            <div class="erro-codigo">404</div>

            <div class="erro-icone" aria-hidden="true">
                <i class="bi bi-compass"></i>
            </div>

            <h1 id="tituloErro">Página não encontrada</h1>
            <p>
                O endereço pode ter mudado, o arquivo pode ter sido removido
                ou o link acessado está incorreto.
            </p>

            <div class="erro-acoes">
                <a class="btn btn-primary" href="<?= htmlspecialchars(erroAsset($destinoPrincipal)) ?>">
                    <i class="bi bi-house"></i>
                    <?= !empty($_SESSION['usuario_id']) ? 'Voltar ao início' : 'Ir para o login' ?>
                </a>
                <button class="btn btn-outline-secondary" type="button" onclick="history.back()">
                    <i class="bi bi-arrow-left"></i>
                    Voltar
                </button>
            </div>

            <div class="erro-ajuda">
                <i class="bi bi-info-circle"></i>
                Se isso aconteceu ao clicar em um botão do sistema, confira se o arquivo foi enviado ao servidor.
            </div>
        </section>
    </main>
</body>

</html>