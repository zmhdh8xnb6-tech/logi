<?php
require 'config.php';
require 'mailer.php';

function obterUrlBaseAplicacao(): string
{
    $urlConfigurada = getenv('APP_URL');

    if (!empty($urlConfigurada)) {
        return rtrim($urlConfigurada, '/');
    }

    $protocoloEncaminhado = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    $httpsAtivo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $protocoloEncaminhado === 'https';
    $protocolo = $httpsAtivo ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
    $pasta = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

    if ($pasta === '.' || $pasta === '/') {
        $pasta = '';
    }

    return $protocolo . '://' . $host . $pasta;
}

$mensagem = $_SESSION["mensagem"] ?? "";
$tipoMensagem = $_SESSION["tipoMensagem"] ?? "";

unset($_SESSION["mensagem"], $_SESSION["tipoMensagem"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nome = trim($_POST["nome"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $senha = $_POST["senha"] ?? "";
    $confirmarSenha = $_POST["confirmar_senha"] ?? "";
    $telefone = $_POST['telefone'] ?? '';
    $departamento = $_POST['departamento'] ?? '';

    if ($nome === "" || $email === "" || $senha === "" || $confirmarSenha === "") {
        $_SESSION["mensagem"] = "Preencha todos os campos.";
        $_SESSION["tipoMensagem"] = "danger";
        header("Location: cadastro.php");
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION["mensagem"] = "E-mail inválido.";
        $_SESSION["tipoMensagem"] = "danger";
        header("Location: cadastro.php");
        exit;
    }

    if ($senha !== $confirmarSenha) {
        $_SESSION["mensagem"] = "As senhas não coincidem.";
        $_SESSION["tipoMensagem"] = "danger";
        header("Location: cadastro.php");
        exit;
    }

    if (strlen($senha) < 6) {
        $_SESSION["mensagem"] = "A senha deve ter pelo menos 6 caracteres.";
        $_SESSION["tipoMensagem"] = "danger";
        header("Location: cadastro.php");
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $_SESSION["mensagem"] = "Este e-mail já está cadastrado.";
        $_SESSION["tipoMensagem"] = "warning";
        header("Location: cadastro.php");
        exit;
    }

    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(32));

    $stmt = $conn->prepare("INSERT INTO usuarios (nome, email, telefone, departamento, senha, token_verificacao, email_verificado) VALUES (?, ?, ?, ?, ?, ?, 0)");
    $stmt->bind_param("ssssss", $nome, $email, $telefone, $departamento, $senhaHash, $token);

    if ($stmt->execute()) {
        $link = obterUrlBaseAplicacao()
            . '/verificar_email.php?token='
            . rawurlencode($token);
        $linkSeguro = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        $mensagemEmail = "
            <h3>Confirme seu cadastro</h3>
            <p>Olá, " . htmlspecialchars($nome) . "</p>
            <p>Clique no link abaixo para ativar sua conta:</p>
            <p><a href='$linkSeguro'>Ativar conta</a></p>
        ";

        $emailEnviado = enviarEmail($email, $nome, "Confirmação de cadastro", $mensagemEmail);

        if ($emailEnviado) {
            $_SESSION["mensagem"] = "Cadastro realizado! Verifique seu e-mail para ativar sua conta.";
            $_SESSION["tipoMensagem"] = "success";
            header("Location: login.php");
            exit;
        }

        $_SESSION["mensagem"] = "Usuário cadastrado, mas não foi possível enviar o e-mail.";
        $_SESSION["tipoMensagem"] = "warning";
        header("Location: cadastro.php");
        exit;
    }

    $_SESSION["mensagem"] = "Erro ao cadastrar usuário.";
    $_SESSION["tipoMensagem"] = "danger";
    header("Location: cadastro.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <?php include 'includes/head.php'; ?>
    <title>Cadastro</title>
</head>

<body class="login-page cadastro-page">

    <div class="login-container">
        <div class="login-card">

            <div class="text-center mb-4">
                <img src="assets/images/logo.svg" class="logo-img" alt="Logi">
            </div>

            <?php if ($mensagem): ?>
                <div class="alert alert-<?= $tipoMensagem ?> text-center py-2">
                    <?= htmlspecialchars($mensagem) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="cadastroForm">

                <div class="row">

                    <div class="col-md-6 mb-3">
                        <label class="form-label text-primary">NOME</label>
                        <input type="text" name="nome" class="form-control input-custom" placeholder="Digite seu nome" required>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label text-primary">E-MAIL</label>
                        <input type="email" name="email" class="form-control input-custom" placeholder="Digite seu e-mail" required>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label text-primary">TELEFONE</label>
                        <input type="text" name="telefone" id="telefone" class="form-control input-custom" placeholder="(00) 00000-0000">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label text-primary">DEPARTAMENTO</label>
                        <select name="departamento" class="form-control input-custom" required>
                            <option value="">Selecione...</option>
                            <option value="Financeiro">Financeiro</option>
                            <option value="Gerencia">Gerência</option>
                            <option value="Logistica">Logística</option>
                            <option value="Legalizacao">Legalização</option>
                            <option value="Fiscal">Fiscal</option>
                            <option value="Recursos Humanos">Recursos Humanos</option>
                            <option value="Contabil">Contábil</option>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3 position-relative">
                        <label class="form-label text-primary">SENHA</label>
                        <input type="password" name="senha" id="senha" class="form-control input-custom pe-5" required>

                        <button type="button" class="eye-btn" onclick="toggleSenha('senha', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>

                    <div class="col-md-6 mb-3 position-relative">
                        <label class="form-label text-primary">CONFIRMAR SENHA</label>
                        <input type="password" name="confirmar_senha" id="confirmar_senha" class="form-control input-custom pe-5" required>

                        <button type="button" class="eye-btn" onclick="toggleSenha('confirmar_senha', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>

                </div>

                <button type="submit" class="btn btn-primary w-100 btn-login mt-2" id="btnCadastrar">
                    Cadastrar
                </button>

            </form>

            <div class="text-center mt-3">
                <a href="login.php" class="link-cadastro">Voltar para o login</a>
            </div>

        </div>
    </div>

    <script>
        function toggleSenha(id, botao) {
            const campo = document.getElementById(id);
            const icone = botao.querySelector('i');

            if (campo.type === 'password') {
                campo.type = 'text';
                icone.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                campo.type = 'password';
                icone.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }

        document.getElementById('cadastroForm').addEventListener('submit', function() {
            const btn = document.getElementById('btnCadastrar');
            btn.disabled = true;
            btn.textContent = 'Cadastrando...';
        });
    </script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>

    <script>
        $(document).ready(function() {

            // TELEFONE INTELIGENTE
            var maskBehavior = function(val) {
                return val.replace(/\D/g, '').length === 11 ?
                    '(00) 00000-0000' :
                    '(00) 0000-00009';
            };

            var options = {
                onKeyPress: function(val, e, field, options) {
                    field.mask(maskBehavior.apply({}, arguments), options);
                }
            };

            $('#telefone').mask(maskBehavior, options);

        });
    </script>
</body>

</html>