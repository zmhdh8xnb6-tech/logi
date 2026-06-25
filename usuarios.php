<?php
require 'config.php';

exigirPermissao('usuarios');

$mensagem = '';
$tipoMensagem = 'success';
$modulos = modulosSistema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $departamento = trim($_POST['departamento'] ?? '');
    $tipo = $_POST['tipo'] === 'admin' ? 'admin' : 'usuario';
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $senha = $_POST['senha'] ?? '';
    $permissoes = $_POST['permissoes'] ?? [];
    $permissoes = array_values(array_intersect($permissoes, array_keys($modulos)));

    if ($tipo === 'admin') {
        $permissoes = array_keys($modulos);
    }

    $permissoesJson = json_encode($permissoes, JSON_UNESCAPED_UNICODE);

    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mensagem = 'Informe nome e e-mail válido.';
        $tipoMensagem = 'danger';
    } elseif ($acao === 'criar') {
        if (strlen($senha) < 6) {
            $mensagem = 'A senha deve ter pelo menos 6 caracteres.';
            $tipoMensagem = 'danger';
        } else {
            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO usuarios (
                    nome, email, telefone, departamento, senha,
                    email_verificado, token_verificacao, tipo, ativo, permissoes
                )
                VALUES (?, ?, ?, ?, ?, 1, NULL, ?, ?, ?)
            ");

            try {
                $stmt->execute([
                    $nome,
                    $email,
                    $telefone,
                    $departamento,
                    $senhaHash,
                    $tipo,
                    $ativo,
                    $permissoesJson,
                ]);

                $mensagem = 'Usuário criado com sucesso.';
            } catch (PDOException $e) {
                $mensagem = 'Não foi possível criar. Verifique se o e-mail já existe.';
                $tipoMensagem = 'danger';
            }
        }
    } elseif ($acao === 'editar' && $id > 0) {
        if ($senha !== '') {
            if (strlen($senha) < 6) {
                $mensagem = 'A nova senha deve ter pelo menos 6 caracteres.';
                $tipoMensagem = 'danger';
            } else {
                $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE usuarios
                    SET nome = ?, email = ?, telefone = ?, departamento = ?,
                        tipo = ?, ativo = ?, permissoes = ?, senha = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $nome,
                    $email,
                    $telefone,
                    $departamento,
                    $tipo,
                    $ativo,
                    $permissoesJson,
                    $senhaHash,
                    $id,
                ]);
                $mensagem = 'Usuário atualizado com sucesso.';
            }
        } else {
            $stmt = $pdo->prepare("
                UPDATE usuarios
                SET nome = ?, email = ?, telefone = ?, departamento = ?,
                    tipo = ?, ativo = ?, permissoes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $nome,
                $email,
                $telefone,
                $departamento,
                $tipo,
                $ativo,
                $permissoesJson,
                $id,
            ]);
            $mensagem = 'Usuário atualizado com sucesso.';
        }
    }
}

$stmt = $pdo->query("
    SELECT id, nome, email, telefone, departamento, tipo, ativo, permissoes
    FROM usuarios
    ORDER BY nome ASC
");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Usuários e Permissões</title>
</head>

<body class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1">Usuários e Permissões</h3>
                    <p class="text-muted mb-0">Cadastre usuários e defina o que cada um pode acessar</p>
                </div>

                <a href="home.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            <?php if ($mensagem): ?>
                <div class="alert alert-<?= htmlspecialchars($tipoMensagem) ?>">
                    <?= htmlspecialchars($mensagem) ?>
                </div>
            <?php endif; ?>

            <div class="clientes-box mb-4">
                <h5 class="mb-3">Novo usuário</h5>

                <form method="post">
                    <input type="hidden" name="acao" value="criar">

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Nome</label>
                            <input type="text" name="nome" class="form-control" required>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">E-mail</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>

                        <div class="col-md-2 mb-3">
                            <label class="form-label">Telefone</label>
                            <input type="text" name="telefone" class="form-control">
                        </div>

                        <div class="col-md-2 mb-3">
                            <label class="form-label">Departamento</label>
                            <input type="text" name="departamento" class="form-control">
                        </div>

                        <div class="col-md-2 mb-3">
                            <label class="form-label">Senha</label>
                            <input type="password" name="senha" class="form-control" required>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Tipo</label>
                            <select name="tipo" class="form-select">
                                <option value="usuario">Usuário</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="ativo" value="1" class="form-check-input" id="novoAtivo" checked>
                                <label class="form-check-label" for="novoAtivo">Usuário ativo</label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <?php foreach ($modulos as $chave => $rotulo): ?>
                            <div class="col-md-3 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="permissoes[]" value="<?= htmlspecialchars($chave) ?>" id="novo_<?= htmlspecialchars($chave) ?>">
                                    <label class="form-check-label" for="novo_<?= htmlspecialchars($chave) ?>">
                                        <?= htmlspecialchars($rotulo) ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn-primary mt-2">
                        <i class="bi bi-person-plus"></i> Criar usuário
                    </button>
                </form>
            </div>

            <div class="clientes-box">
                <h5 class="mb-3">Usuários cadastrados</h5>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>E-mail</th>
                                <th>Tipo</th>
                                <th>Status</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $usuario): ?>
                                <?php
                                $permissoesUsuario = json_decode($usuario['permissoes'] ?? '[]', true) ?: [];
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($usuario['nome']) ?></td>
                                    <td><?= htmlspecialchars($usuario['email']) ?></td>
                                    <td><?= $usuario['tipo'] === 'admin' ? 'Administrador' : 'Usuário' ?></td>
                                    <td>
                                        <span class="badge <?= (int)$usuario['ativo'] === 1 ? 'bg-success' : 'bg-danger' ?>">
                                            <?= (int)$usuario['ativo'] === 1 ? 'Ativo' : 'Bloqueado' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm btn-editar-usuario"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalEditarUsuario<?= (int)$usuario['id'] ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>
                                </tr>

                                <div class="modal fade" id="modalEditarUsuario<?= (int)$usuario['id'] ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered">
                                        <div class="modal-content">
                                            <form method="post">
                                                <input type="hidden" name="acao" value="editar">
                                                <input type="hidden" name="id" value="<?= (int)$usuario['id'] ?>">

                                                <div class="modal-header">
                                                    <h5 class="modal-title">Editar usuário</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                                </div>

                                                <div class="modal-body">
                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Nome</label>
                                                            <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($usuario['nome']) ?>" required>
                                                        </div>

                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">E-mail</label>
                                                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($usuario['email']) ?>" required>
                                                        </div>

                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Telefone</label>
                                                            <input type="text" name="telefone" class="form-control" value="<?= htmlspecialchars($usuario['telefone'] ?? '') ?>">
                                                        </div>

                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Departamento</label>
                                                            <input type="text" name="departamento" class="form-control" value="<?= htmlspecialchars($usuario['departamento'] ?? '') ?>">
                                                        </div>

                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Nova senha</label>
                                                            <input type="password" name="senha" class="form-control" placeholder="Deixe vazio para manter">
                                                        </div>

                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Tipo</label>
                                                            <select name="tipo" class="form-select">
                                                                <option value="usuario" <?= $usuario['tipo'] !== 'admin' ? 'selected' : '' ?>>Usuário</option>
                                                                <option value="admin" <?= $usuario['tipo'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-4 mb-3 d-flex align-items-end">
                                                            <div class="form-check">
                                                                <input type="checkbox" name="ativo" value="1" class="form-check-input" id="ativo<?= (int)$usuario['id'] ?>" <?= (int)$usuario['ativo'] === 1 ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="ativo<?= (int)$usuario['id'] ?>">Usuário ativo</label>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <hr>

                                                    <div class="row">
                                                        <?php foreach ($modulos as $chave => $rotulo): ?>
                                                            <div class="col-md-4 mb-2">
                                                                <div class="form-check">
                                                                    <input
                                                                        class="form-check-input"
                                                                        type="checkbox"
                                                                        name="permissoes[]"
                                                                        value="<?= htmlspecialchars($chave) ?>"
                                                                        id="perm<?= (int)$usuario['id'] ?>_<?= htmlspecialchars($chave) ?>"
                                                                        <?= in_array($chave, $permissoesUsuario, true) || $usuario['tipo'] === 'admin' ? 'checked' : '' ?>>
                                                                    <label class="form-check-label" for="perm<?= (int)$usuario['id'] ?>_<?= htmlspecialchars($chave) ?>">
                                                                        <?= htmlspecialchars($rotulo) ?>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>

                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                    <button type="submit" class="btn btn-success">Salvar</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>