<?php
require 'config.php';

exigirPermissao('usuarios');

$mensagem = '';
$tipoMensagem = 'success';
$modulos = modulosSistema();
$pdoUsuarios = $authPdo ?? $pdo;

function usuariosTenantColunasDisponiveis(PDO $pdo): bool
{
    static $disponivel = null;

    if ($disponivel !== null) {
        return $disponivel;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'tenant_db'");
        $disponivel = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $disponivel = false;
    }

    return $disponivel;
}

$tenantDisponivel = usuariosTenantColunasDisponiveis($pdoUsuarios);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $usuarioAntesAuditoria = null;

    if ($id > 0) {
        $stmtAuditoria = $pdoUsuarios->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmtAuditoria->execute([$id]);
        $usuarioAntesAuditoria = $stmtAuditoria->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $departamento = trim($_POST['departamento'] ?? '');
    $tipo = ($_POST['tipo'] ?? '') === 'admin' ? 'admin' : 'usuario';
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $senha = $_POST['senha'] ?? '';
    $permissoes = $_POST['permissoes'] ?? [];
    $permissoes = array_values(array_intersect($permissoes, array_keys($modulos)));
    $tenantDb = $tenantDisponivel ? trim($_POST['tenant_db'] ?? '') : '';
    $tenantHost = $tenantDisponivel ? trim($_POST['tenant_host'] ?? '') : '';
    $tenantUser = $tenantDisponivel ? trim($_POST['tenant_user'] ?? '') : '';
    $tenantPass = $tenantDisponivel ? (string)($_POST['tenant_pass'] ?? '') : '';

    if ($tipo === 'admin') {
        $permissoes = array_keys($modulos);
    }

    $permissoesJson = json_encode($permissoes, JSON_UNESCAPED_UNICODE);

    if ($acao === 'excluir') {
        if (!usuarioEhAdmin()) {
            $mensagem = 'Somente administradores podem excluir usuários.';
            $tipoMensagem = 'danger';
        } elseif ($id === (int)($_SESSION['usuario_id'] ?? 0)) {
            $mensagem = 'Você não pode excluir a própria conta enquanto está conectado.';
            $tipoMensagem = 'danger';
        } else {
            $stmtUsuario = $pdoUsuarios->prepare("SELECT * FROM usuarios WHERE id = ?");
            $stmtUsuario->execute([$id]);
            $usuarioExcluir = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

            if (!$usuarioExcluir) {
                $mensagem = 'Usuário não encontrado.';
                $tipoMensagem = 'danger';
            } else {
                $podeExcluir = true;

                if ($usuarioExcluir['tipo'] === 'admin') {
                    $totalAdministradores = (int)$pdoUsuarios
                        ->query("SELECT COUNT(*) FROM usuarios WHERE tipo = 'admin'")
                        ->fetchColumn();

                    if ($totalAdministradores <= 1) {
                        $mensagem = 'Não é possível excluir o último administrador do sistema.';
                        $tipoMensagem = 'danger';
                        $podeExcluir = false;
                    }
                }

                if ($podeExcluir) {
                    try {
                        $stmtExcluir = $pdoUsuarios->prepare("DELETE FROM usuarios WHERE id = ?");
                        $stmtExcluir->execute([$id]);
                        registrarAuditoria(
                            $pdoUsuarios,
                            'Usuários',
                            'excluir',
                            'usuario',
                            $id,
                            'Excluiu o usuário ' . ($usuarioAntesAuditoria['nome'] ?? ('#' . $id)),
                            $usuarioAntesAuditoria,
                            null
                        );
                        $mensagem = 'Usuário excluído com sucesso.';
                    } catch (PDOException $e) {
                        $mensagem = 'Não foi possível excluir o usuário.';
                        $tipoMensagem = 'danger';
                    }
                }
            }
        }
    } elseif ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mensagem = 'Informe nome e e-mail válido.';
        $tipoMensagem = 'danger';
    } elseif ($acao === 'criar') {
        if (strlen($senha) < 6) {
            $mensagem = 'A senha deve ter pelo menos 6 caracteres.';
            $tipoMensagem = 'danger';
        } else {
            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

            if ($tenantDisponivel) {
                $stmt = $pdoUsuarios->prepare("
                    INSERT INTO usuarios (
                        nome, email, telefone, departamento, senha,
                        email_verificado, token_verificacao, tipo, ativo, permissoes,
                        tenant_db, tenant_host, tenant_user, tenant_pass
                    )
                    VALUES (?, ?, ?, ?, ?, 1, NULL, ?, ?, ?, ?, ?, ?, ?)
                ");
                $parametrosCriar = [
                    $nome,
                    $email,
                    $telefone,
                    $departamento,
                    $senhaHash,
                    $tipo,
                    $ativo,
                    $permissoesJson,
                    $tenantDb,
                    $tenantHost,
                    $tenantUser,
                    $tenantPass,
                ];
            } else {
                $stmt = $pdoUsuarios->prepare("
                    INSERT INTO usuarios (
                        nome, email, telefone, departamento, senha,
                        email_verificado, token_verificacao, tipo, ativo, permissoes
                    )
                    VALUES (?, ?, ?, ?, ?, 1, NULL, ?, ?, ?)
                ");
                $parametrosCriar = [
                    $nome,
                    $email,
                    $telefone,
                    $departamento,
                    $senhaHash,
                    $tipo,
                    $ativo,
                    $permissoesJson,
                ];
            }

            try {
                $stmt->execute($parametrosCriar);

                $novoUsuarioId = (int)$pdoUsuarios->lastInsertId();
                $stmtNovoUsuario = $pdoUsuarios->prepare("SELECT * FROM usuarios WHERE id = ?");
                $stmtNovoUsuario->execute([$novoUsuarioId]);
                registrarAuditoria(
                    $pdoUsuarios,
                    'Usuários',
                    'criar',
                    'usuario',
                    $novoUsuarioId,
                    'Cadastrou o usuário ' . $nome,
                    null,
                    $stmtNovoUsuario->fetch(PDO::FETCH_ASSOC) ?: null
                );
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
                $setTenant = $tenantDisponivel
                    ? ', tenant_db = ?, tenant_host = ?, tenant_user = ?, tenant_pass = ?'
                    : '';
                $stmt = $pdoUsuarios->prepare("
                    UPDATE usuarios
                    SET nome = ?, email = ?, telefone = ?, departamento = ?,
                        tipo = ?, ativo = ?, permissoes = ?, senha = ? {$setTenant}
                    WHERE id = ?
                ");
                $parametrosEditar = [
                    $nome,
                    $email,
                    $telefone,
                    $departamento,
                    $tipo,
                    $ativo,
                    $permissoesJson,
                    $senhaHash,
                ];
                if ($tenantDisponivel) {
                    array_push($parametrosEditar, $tenantDb, $tenantHost, $tenantUser, $tenantPass);
                }
                $parametrosEditar[] = $id;
                $stmt->execute($parametrosEditar);
                $mensagem = 'Usuário atualizado com sucesso.';
            }
        } else {
            $setTenant = $tenantDisponivel
                ? ', tenant_db = ?, tenant_host = ?, tenant_user = ?, tenant_pass = ?'
                : '';
            $stmt = $pdoUsuarios->prepare("
                UPDATE usuarios
                SET nome = ?, email = ?, telefone = ?, departamento = ?,
                    tipo = ?, ativo = ?, permissoes = ? {$setTenant}
                WHERE id = ?
            ");
            $parametrosEditar = [
                $nome,
                $email,
                $telefone,
                $departamento,
                $tipo,
                $ativo,
                $permissoesJson,
            ];
            if ($tenantDisponivel) {
                array_push($parametrosEditar, $tenantDb, $tenantHost, $tenantUser, $tenantPass);
            }
            $parametrosEditar[] = $id;
            $stmt->execute($parametrosEditar);
            $mensagem = 'Usuário atualizado com sucesso.';
        }

        if ($mensagem === 'Usuário atualizado com sucesso.' && $usuarioAntesAuditoria) {
            $stmtDepoisAuditoria = $pdoUsuarios->prepare("SELECT * FROM usuarios WHERE id = ?");
            $stmtDepoisAuditoria->execute([$id]);
            $usuarioDepoisAuditoria = $stmtDepoisAuditoria->fetch(PDO::FETCH_ASSOC) ?: [];
            $mudancasAuditoria = auditoriaMudancas($usuarioAntesAuditoria, $usuarioDepoisAuditoria);
            registrarAuditoria(
                $pdoUsuarios,
                'Usuários',
                'editar',
                'usuario',
                $id,
                'Alterou o usuário ' . ($usuarioDepoisAuditoria['nome'] ?? $usuarioAntesAuditoria['nome']),
                $mudancasAuditoria['antes'],
                $mudancasAuditoria['depois']
            );
        }
    }
}

$stmt = $pdoUsuarios->query("
    SELECT id, nome, email, telefone, departamento, tipo, ativo, permissoes
        " . ($tenantDisponivel ? ', tenant_db, tenant_host, tenant_user, tenant_pass' : '') . "
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
                <div class="alert alert-<?= htmlspecialchars($tipoMensagem) ?> alert-auto-dismiss fade show">
                    <?= htmlspecialchars($mensagem) ?>
                </div>
            <?php endif; ?>

            <div class="clientes-box mb-4">
                <h5 class="mb-3">Novo usuário</h5>

                <form method="post" autocomplete="off" novalidate class="usuario-form">
                    <input type="hidden" name="acao" value="criar">
                    <input type="text" name="usuario_fake" autocomplete="username" class="d-none" tabindex="-1">
                    <input type="password" name="senha_fake" autocomplete="new-password" class="d-none" tabindex="-1">

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Nome</label>
                            <input type="text" name="nome" class="form-control" autocomplete="off" required>
                            <div class="invalid-feedback">Informe o nome.</div>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">E-mail</label>
                            <input type="email" name="email" class="form-control" autocomplete="new-email" required>
                            <div class="invalid-feedback">Informe um e-mail válido.</div>
                        </div>

                        <div class="col-md-2 mb-3">
                            <label class="form-label">Telefone</label>
                            <input type="text" name="telefone" class="form-control" autocomplete="off">
                        </div>

                        <div class="col-md-2 mb-3">
                            <label class="form-label">Departamento</label>
                            <input type="text" name="departamento" class="form-control" autocomplete="off">
                        </div>

                        <div class="col-md-2 mb-3 position-relative">
                            <label class="form-label">Senha</label>
                            <input type="password" name="senha" id="senhaNovoUsuario" class="form-control pe-5" autocomplete="new-password" required>
                            <button type="button" class="eye-btn" onclick="toggleSenhaUsuario('senhaNovoUsuario', this)" title="Mostrar senha">
                                <i class="bi bi-eye"></i>
                            </button>
                            <div class="invalid-feedback">Informe uma senha com pelo menos 6 caracteres.</div>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Tipo</label>
                            <select name="tipo" class="form-select">
                                <option value="usuario">Usuário</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="ativo" value="1" class="form-check-input" id="novoAtivo" role="switch" checked>
                                <label class="form-check-label" for="novoAtivo">Usuário ativo</label>
                            </div>
                        </div>
                    </div>

                    <?php if ($tenantDisponivel): ?>
                        <div class="border rounded p-3 mb-3">
                            <h6 class="mb-2">Banco separado</h6>
                            <p class="text-muted small mb-3">
                                Deixe em branco para usar o banco principal. Preencha para esse usuário acessar outra base.
                            </p>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Nome do banco</label>
                                    <input type="text" name="tenant_db" class="form-control" autocomplete="off" placeholder="ex: u285798939_cliente1">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Host</label>
                                    <input type="text" name="tenant_host" class="form-control" autocomplete="off" placeholder="localhost">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Usuário do banco</label>
                                    <input type="text" name="tenant_user" class="form-control" autocomplete="off">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Senha do banco</label>
                                    <input type="password" name="tenant_pass" class="form-control" autocomplete="new-password">
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning py-2">
                            Para usar banco separado por usuário, execute o SQL das colunas <code>tenant_*</code> na tabela <code>usuarios</code>.
                        </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-marcar-todas" data-target="#permissoesNovo">
                            Marcar todas
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-desmarcar-todas" data-target="#permissoesNovo">
                            Desmarcar todas
                        </button>
                    </div>

                    <div class="row" id="permissoesNovo">
                        <?php foreach ($modulos as $chave => $rotulo): ?>
                            <div class="col-md-3 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input permissao-switch" type="checkbox" role="switch" name="permissoes[]" value="<?= htmlspecialchars($chave) ?>" id="novo_<?= htmlspecialchars($chave) ?>">
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
                                <th>Base</th>
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
                                        <?php if ($tenantDisponivel && !empty($usuario['tenant_db'])): ?>
                                            <span class="badge bg-primary">
                                                <?= htmlspecialchars($usuario['tenant_db']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Principal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= (int)$usuario['ativo'] === 1 ? 'bg-success' : 'bg-danger' ?>">
                                            <?= (int)$usuario['ativo'] === 1 ? 'Ativo' : 'Bloqueado' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary btn-sm btn-editar-usuario"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEditarUsuario<?= (int)$usuario['id'] ?>"
                                                title="Editar usuário"
                                                aria-label="Editar usuário">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <?php if (usuarioEhAdmin() && (int)$usuario['id'] !== (int)($_SESSION['usuario_id'] ?? 0)): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm btn-excluir-usuario"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalExcluirUsuario"
                                                    data-usuario-id="<?= (int)$usuario['id'] ?>"
                                                    data-usuario-nome="<?= htmlspecialchars($usuario['nome']) ?>"
                                                    title="Excluir usuário"
                                                    aria-label="Excluir usuário">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <div class="modal fade" id="modalEditarUsuario<?= (int)$usuario['id'] ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered">
                                        <div class="modal-content">
                                            <form method="post" autocomplete="off" novalidate class="usuario-form">
                                                <input type="hidden" name="acao" value="editar">
                                                <input type="hidden" name="id" value="<?= (int)$usuario['id'] ?>">
                                                <input type="text" name="usuario_fake" autocomplete="username" class="d-none" tabindex="-1">
                                                <input type="password" name="senha_fake" autocomplete="new-password" class="d-none" tabindex="-1">

                                                <div class="modal-header">
                                                    <h5 class="modal-title">Editar usuário</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                                </div>

                                                <div class="modal-body">
                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Nome</label>
                                                            <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($usuario['nome']) ?>" autocomplete="off" required>
                                                            <div class="invalid-feedback">Informe o nome.</div>
                                                        </div>

                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">E-mail</label>
                                                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($usuario['email']) ?>" autocomplete="new-email" required>
                                                            <div class="invalid-feedback">Informe um e-mail válido.</div>
                                                        </div>

                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Telefone</label>
                                                            <input type="text" name="telefone" class="form-control" value="<?= htmlspecialchars($usuario['telefone'] ?? '') ?>" autocomplete="off">
                                                        </div>

                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Departamento</label>
                                                            <input type="text" name="departamento" class="form-control" value="<?= htmlspecialchars($usuario['departamento'] ?? '') ?>" autocomplete="off">
                                                        </div>

                                                        <div class="col-md-4 mb-3 position-relative">
                                                            <label class="form-label">Nova senha</label>
                                                            <input type="password" name="senha" id="senhaUsuario<?= (int)$usuario['id'] ?>" class="form-control pe-5" placeholder="Deixe vazio para manter" autocomplete="new-password">
                                                            <button type="button" class="eye-btn" onclick="toggleSenhaUsuario('senhaUsuario<?= (int)$usuario['id'] ?>', this)" title="Mostrar senha">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                            <div class="invalid-feedback">A nova senha deve ter pelo menos 6 caracteres.</div>
                                                        </div>

                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Tipo</label>
                                                            <select name="tipo" class="form-select">
                                                                <option value="usuario" <?= $usuario['tipo'] !== 'admin' ? 'selected' : '' ?>>Usuário</option>
                                                                <option value="admin" <?= $usuario['tipo'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-4 mb-3 d-flex align-items-end">
                                                            <div class="form-check form-switch">
                                                                <input type="checkbox" name="ativo" value="1" class="form-check-input" role="switch" id="ativo<?= (int)$usuario['id'] ?>" <?= (int)$usuario['ativo'] === 1 ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="ativo<?= (int)$usuario['id'] ?>">Usuário ativo</label>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <?php if ($tenantDisponivel): ?>
                                                        <div class="border rounded p-3 mb-3">
                                                            <h6 class="mb-2">Banco separado</h6>
                                                            <p class="text-muted small mb-3">
                                                                Deixe em branco para usar o banco principal. Preencha para esse usuário acessar outra base.
                                                            </p>
                                                            <div class="row">
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label">Nome do banco</label>
                                                                    <input type="text" name="tenant_db" class="form-control" value="<?= htmlspecialchars($usuario['tenant_db'] ?? '') ?>" autocomplete="off">
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label">Host</label>
                                                                    <input type="text" name="tenant_host" class="form-control" value="<?= htmlspecialchars($usuario['tenant_host'] ?? '') ?>" autocomplete="off" placeholder="localhost">
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label">Usuário do banco</label>
                                                                    <input type="text" name="tenant_user" class="form-control" value="<?= htmlspecialchars($usuario['tenant_user'] ?? '') ?>" autocomplete="off">
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label">Senha do banco</label>
                                                                    <input type="password" name="tenant_pass" class="form-control" value="<?= htmlspecialchars($usuario['tenant_pass'] ?? '') ?>" autocomplete="new-password">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>

                                                    <hr>

                                                    <div class="d-flex gap-2 mb-3">
                                                        <button type="button" class="btn btn-sm btn-outline-primary btn-marcar-todas" data-target="#permissoesUsuario<?= (int)$usuario['id'] ?>">
                                                            Marcar todas
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-desmarcar-todas" data-target="#permissoesUsuario<?= (int)$usuario['id'] ?>">
                                                            Desmarcar todas
                                                        </button>
                                                    </div>

                                                    <div class="row" id="permissoesUsuario<?= (int)$usuario['id'] ?>">
                                                        <?php foreach ($modulos as $chave => $rotulo): ?>
                                                            <div class="col-md-4 mb-2">
                                                                <div class="form-check form-switch">
                                                                    <input
                                                                        class="form-check-input permissao-switch"
                                                                        type="checkbox"
                                                                        role="switch"
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

    <?php if (usuarioEhAdmin()): ?>
        <div class="modal fade" id="modalExcluirUsuario" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Excluir usuário</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body">
                        <p class="mb-2">
                            Tem certeza que deseja excluir
                            <strong id="nomeUsuarioExcluir"></strong>?
                        </p>
                        <small class="text-danger">
                            Essa ação apaga o acesso definitivamente e não poderá ser desfeita.
                        </small>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Não
                        </button>

                        <form method="post" id="formExcluirUsuario">
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" id="idUsuarioExcluir">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash"></i> Sim, excluir
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        setTimeout(function() {
            document.querySelectorAll('.alert-auto-dismiss').forEach(function(alerta) {
                alerta.classList.remove('show');
                setTimeout(function() {
                    alerta.remove();
                }, 200);
            });
        }, 4000);

        document.querySelectorAll('.btn-marcar-todas').forEach(function(botao) {
            botao.addEventListener('click', function() {
                document.querySelectorAll(this.dataset.target + ' input[type="checkbox"]').forEach(function(campo) {
                    campo.checked = true;
                });
            });
        });

        document.querySelectorAll('.btn-desmarcar-todas').forEach(function(botao) {
            botao.addEventListener('click', function() {
                document.querySelectorAll(this.dataset.target + ' input[type="checkbox"]').forEach(function(campo) {
                    campo.checked = false;
                });
            });
        });

        document.querySelectorAll('.btn-excluir-usuario').forEach(function(botao) {
            botao.addEventListener('click', function() {
                document.getElementById('idUsuarioExcluir').value = this.dataset.usuarioId;
                document.getElementById('nomeUsuarioExcluir').textContent = this.dataset.usuarioNome;
            });
        });

        window.addEventListener('pageshow', function() {
            const formularioNovo = document.querySelector('form input[name="acao"][value="criar"]')?.closest('form');

            if (!formularioNovo) {
                return;
            }

            ['nome', 'email', 'telefone', 'departamento', 'senha'].forEach(function(nomeCampo) {
                const campo = formularioNovo.querySelector('[name="' + nomeCampo + '"]');

                if (campo) {
                    campo.value = '';
                }
            });
        });

        function emailValido(valor) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(valor);
        }

        function marcarCampoInvalido(campo) {
            campo.classList.add('is-invalid');
            campo.classList.remove('is-valid');
        }

        function limparCampoInvalido(campo) {
            campo.classList.remove('is-invalid');
        }

        document.querySelectorAll('.usuario-form').forEach(function(formulario) {
            formulario.addEventListener('submit', function(e) {
                let valido = true;
                let primeiroInvalido = null;
                const acao = formulario.querySelector('[name="acao"]')?.value || '';
                const nome = formulario.querySelector('[name="nome"]');
                const email = formulario.querySelector('[name="email"]');
                const senha = formulario.querySelector('[name="senha"]');

                [nome, email, senha].forEach(function(campo) {
                    if (campo) {
                        limparCampoInvalido(campo);
                    }
                });

                if (nome && nome.value.trim() === '') {
                    marcarCampoInvalido(nome);
                    valido = false;
                    primeiroInvalido = primeiroInvalido || nome;
                }

                if (email && !emailValido(email.value.trim())) {
                    marcarCampoInvalido(email);
                    valido = false;
                    primeiroInvalido = primeiroInvalido || email;
                }

                if (senha) {
                    const senhaObrigatoria = acao === 'criar';
                    const senhaPreenchida = senha.value.trim() !== '';

                    if ((senhaObrigatoria && !senhaPreenchida) || (senhaPreenchida && senha.value.length < 6)) {
                        marcarCampoInvalido(senha);
                        valido = false;
                        primeiroInvalido = primeiroInvalido || senha;
                    }
                }

                if (!valido) {
                    e.preventDefault();
                    primeiroInvalido?.focus();
                }
            });
        });

        document.querySelectorAll('.usuario-form input, .usuario-form select').forEach(function(campo) {
            campo.addEventListener('input', function() {
                limparCampoInvalido(campo);
            });

            campo.addEventListener('change', function() {
                limparCampoInvalido(campo);
            });
        });

        function toggleSenhaUsuario(id, botao) {
            const campo = document.getElementById(id);
            const icone = botao.querySelector('i');

            if (!campo) {
                return;
            }

            if (campo.type === 'password') {
                campo.type = 'text';
                icone.classList.replace('bi-eye', 'bi-eye-slash');
                botao.title = 'Ocultar senha';
            } else {
                campo.type = 'password';
                icone.classList.replace('bi-eye-slash', 'bi-eye');
                botao.title = 'Mostrar senha';
            }
        }
    </script>
</body>

</html>