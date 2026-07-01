<?php
require 'config.php';

if (isset($_SESSION["usuario_id"])) {
    header("Location: home.php");
    exit;
}

$mensagem = $_SESSION["mensagem"] ?? "";
$tipoMensagem = $_SESSION["tipoMensagem"] ?? "";

unset($_SESSION["mensagem"], $_SESSION["tipoMensagem"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $senha = $_POST["senha"] ?? "";

    if ($email === "" || $senha === "") {
        $_SESSION["mensagem"] = "Preencha e-mail e senha.";
        $_SESSION["tipoMensagem"] = "danger";
        header("Location: login.php");
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows === 1) {
        $usuario = $resultado->fetch_assoc();

        if (!password_verify($senha, $usuario["senha"])) {
            registrarAuditoria(
                $pdo,
                'Autenticação',
                'login_falhou',
                'usuario',
                $usuario['id'],
                'Tentativa de login inválida para ' . $usuario['email'],
                null,
                null,
                (int)$usuario['id'],
                $usuario['nome']
            );
            $_SESSION["mensagem"] = "E-mail ou senha inválidos.";
            $_SESSION["tipoMensagem"] = "danger";
            header("Location: login.php");
            exit;
        }

        if ((int)$usuario["email_verificado"] !== 1) {
            $_SESSION["mensagem"] = "Seu e-mail ainda não foi verificado.";
            $_SESSION["tipoMensagem"] = "warning";
            header("Location: login.php");
            exit;
        }

        $usuarioAtivo = array_key_exists("ativo", $usuario) ? (int)$usuario["ativo"] : 1;

        if ($usuarioAtivo !== 1) {
            $_SESSION["mensagem"] = "Seu usuário ainda não foi liberado pelo administrador.";
            $_SESSION["tipoMensagem"] = "warning";
            header("Location: login.php");
            exit;
        }

        $_SESSION["usuario_id"] = $usuario["id"];
        $_SESSION["usuario_nome"] = $usuario["nome"];
        $_SESSION["usuario_email"] = $usuario["email"];
        $_SESSION["usuario_tipo"] = $usuario["tipo"] ?? "usuario";
        $_SESSION["usuario_permissoes"] = json_decode($usuario["permissoes"] ?? "[]", true) ?: [];

        registrarAuditoria(
            $pdo,
            'Autenticação',
            'login',
            'usuario',
            $usuario['id'],
            'Entrou no sistema'
        );

        header("Location: home.php");
        exit;
    }

    registrarAuditoria(
        $pdo,
        'Autenticação',
        'login_falhou',
        'usuario',
        null,
        'Tentativa de login com e-mail não cadastrado: ' . $email,
        null,
        null,
        0,
        'Não identificado'
    );
    $_SESSION["mensagem"] = "E-mail ou senha inválidos.";
    $_SESSION["tipoMensagem"] = "danger";
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Login</title>
</head>

<body class="login-page">

    <div class="login-container">

        <div class="login-card">

            <?php if ($mensagem): ?>
                <div class="alert alert-<?= $tipoMensagem ?> text-center py-2">
                    <?= htmlspecialchars($mensagem) ?>
                </div>
            <?php endif; ?>

            <!-- LOGO -->
            <div class="text-center mb-4">
                <img src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/images/logo.svg" class="logo-img" alt="Logi">
            </div>

            <!-- FORM -->

            <form method="POST" id="loginForm">

                <div class="mb-3">
                    <label class="form-label text-primary">E-MAIL</label>
                    <input autofocus="email" name="email" class="form-control input-custom campo-obrigatorio" placeholder="Digite seu e-mail">
                    <div class="invalid-feedback">Preencha o e-mail.</div>
                </div>

                <div class="mb-4 position-relative">
                    <label class="form-label text-primary">SENHA</label>
                    <input type="password" name="senha" id="senha" class="form-control input-custom pe-5 campo-obrigatorio" placeholder="Digite sua senha">
                    <div class="invalid-feedback">Preencha a senha.</div>

                    <button type="button" class="eye-btn" onclick="toggleSenha('senha', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>


                <button type="submit" class="btn btn-primary w-100 btn-login">
                    Entrar
                </button>

                <div class="text-center mt-3 text-muted small">
                    Acesso liberado apenas pelo administrador.
                </div>

            </form>

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
    </script>

    <script>
        document.querySelector('form').addEventListener('submit', function(e) {
            let valido = true;

            document.querySelectorAll('.campo-obrigatorio').forEach(function(campo) {
                if (campo.value.trim() === '') {
                    campo.classList.add('is-invalid');
                    valido = false;
                } else {
                    campo.classList.remove('is-invalid');
                }
            });

            if (!valido) {
                e.preventDefault();
            }
        });
    </script>

    <script>
        document.querySelectorAll('.campo-obrigatorio').forEach(function(campo) {
            campo.addEventListener('input', function() {
                if (campo.value.trim() !== '') {
                    campo.classList.remove('is-invalid');
                }
            });
        });
    </script>

</body>

</html>