<?php
require 'config.php';
require_once __DIR__ . '/includes/assinaturas_email_funcoes.php';

exigirPermissao('outros_servicos');

$estruturaErro = '';
$estruturaDisponivel = assinaturasEmailEstruturaDisponivel($pdo);
if (!$estruturaDisponivel) {
    try {
        assinaturasEmailInstalarEstrutura($pdo);
        $estruturaDisponivel = assinaturasEmailEstruturaDisponivel($pdo);
    } catch (Throwable $e) {
        $estruturaErro = $e->getMessage();
    }
}

$erro = '';
$empresaId = assinaturasEmailEmpresaId($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!assinaturasEmailTokenValido($_POST['csrf_token'] ?? null)) {
        assinaturasEmailRedirecionar('A sessão do formulário expirou. Tente novamente.', 'danger');
    }
    if (!$estruturaDisponivel) {
        assinaturasEmailRedirecionar('A estrutura das assinaturas de e-mail ainda não está disponível.', 'danger');
    }

    $acao = (string)($_POST['acao'] ?? '');

    try {
        if ($acao === 'salvar') {
            $id = (int)($_POST['id'] ?? 0);
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $provedor = trim((string)($_POST['provedor'] ?? ''));
            $espacoContratado = trim((string)($_POST['espaco_contratado'] ?? ''));
            $cartaoFinal = assinaturasEmailNormalizarCartaoFinal((string)($_POST['cartao_final'] ?? ''));
            $diaDesconto = (int)($_POST['dia_desconto'] ?? 0);
            $observacoes = trim((string)($_POST['observacoes'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
                throw new RuntimeException('Informe um e-mail válido.');
            }
            if ($provedor === '' || mb_strlen($provedor) > 80) {
                throw new RuntimeException('Informe o provedor com até 80 caracteres.');
            }
            if ($espacoContratado === '' || mb_strlen($espacoContratado) > 80) {
                throw new RuntimeException('Informe o espaço contratado com até 80 caracteres.');
            }
            if (strlen($cartaoFinal) !== 4) {
                throw new RuntimeException('Informe exatamente os quatro últimos dígitos do cartão.');
            }
            if ($diaDesconto < 1 || $diaDesconto > 31) {
                throw new RuntimeException('Informe um dia de desconto entre 1 e 31.');
            }
            if (mb_strlen($observacoes) > 2000) {
                throw new RuntimeException('As observações devem ter no máximo 2.000 caracteres.');
            }

            $antes = $id > 0 ? assinaturasEmailBuscar($pdo, $id) : null;
            if ($id > 0 && !$antes) {
                throw new RuntimeException('Assinatura de e-mail não encontrada.');
            }

            $pdo->beginTransaction();
            if ($antes) {
                $stmt = $pdo->prepare("
                    UPDATE assinaturas_email
                    SET email = ?, provedor = ?, espaco_contratado = ?, cartao_final = ?,
                        dia_desconto = ?, observacoes = ?, atualizado_em = NOW()
                    WHERE id = ? AND empresa_id = ?
                ");
                $stmt->execute([
                    $email,
                    $provedor,
                    $espacoContratado,
                    $cartaoFinal,
                    $diaDesconto,
                    $observacoes !== '' ? $observacoes : null,
                    $id,
                    $empresaId,
                ]);

                if ((string)$antes['cartao_final'] !== $cartaoFinal) {
                    $stmt = $pdo->prepare("
                        INSERT INTO assinaturas_email_cartao_historico (
                            empresa_id, assinatura_id, cartao_final_anterior, cartao_final_novo,
                            alterado_por, alterado_por_nome, alterado_em
                        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $empresaId,
                        $id,
                        (string)$antes['cartao_final'],
                        $cartaoFinal,
                        (int)($_SESSION['usuario_id'] ?? 0) ?: null,
                        trim((string)($_SESSION['usuario_nome'] ?? '')) ?: null,
                    ]);
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO assinaturas_email (
                        empresa_id, email, provedor, espaco_contratado, cartao_final,
                        dia_desconto, observacoes, criado_em
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $empresaId,
                    $email,
                    $provedor,
                    $espacoContratado,
                    $cartaoFinal,
                    $diaDesconto,
                    $observacoes !== '' ? $observacoes : null,
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            $depois = assinaturasEmailBuscar($pdo, $id) ?: [
                'email' => $email,
                'provedor' => $provedor,
                'espaco_contratado' => $espacoContratado,
                'cartao_final' => $cartaoFinal,
                'dia_desconto' => $diaDesconto,
                'observacoes' => $observacoes,
            ];
            $mudancas = auditoriaMudancas($antes, $depois);
            registrarAuditoria(
                $pdo,
                'Outros Serviços',
                $antes ? 'editar_assinatura_email' : 'cadastrar_assinatura_email',
                'assinatura_email',
                $id,
                ($antes ? 'Alterou' : 'Cadastrou') . ' assinatura de espaço do e-mail ' . $email,
                $antes ? $mudancas['antes'] : null,
                $antes ? $mudancas['depois'] : $depois
            );
            $pdo->commit();

            assinaturasEmailRedirecionar($antes ? 'Cadastro atualizado com sucesso.' : 'E-mail cadastrado com sucesso.');
        }

        if ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);
            $antes = assinaturasEmailBuscar($pdo, $id);
            if (!$antes) {
                throw new RuntimeException('Assinatura de e-mail não encontrada.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM assinaturas_email WHERE id = ? AND empresa_id = ?');
            $stmt->execute([$id, $empresaId]);
            registrarAuditoria(
                $pdo,
                'Outros Serviços',
                'excluir_assinatura_email',
                'assinatura_email',
                $id,
                'Excluiu assinatura de espaço do e-mail ' . $antes['email'],
                $antes,
                null
            );
            $pdo->commit();
            assinaturasEmailRedirecionar('Cadastro excluído com sucesso.');
        }

        throw new RuntimeException('Ação inválida.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erro = $e instanceof PDOException && (string)$e->getCode() === '23000'
            ? 'Este e-mail já está cadastrado.'
            : $e->getMessage();
    }
}

$flash = $_SESSION['assinaturas_email_flash'] ?? null;
unset($_SESSION['assinaturas_email_flash']);

$opcoesPorPagina = [15, 30, 60];
$porPagina = 15;
$totalRegistros = 0;
$registros = [];
$historicosPorAssinatura = [];

if ($estruturaDisponivel) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM assinaturas_email
        WHERE empresa_id = ?
        ORDER BY dia_desconto, email
    ");
    $stmt->execute([$empresaId]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalRegistros = count($registros);

    if ($registros !== []) {
        $ids = array_map(static fn(array $registro): int => (int)$registro['id'], $registros);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT assinatura_id, cartao_final_anterior, cartao_final_novo, alterado_por_nome, alterado_em
            FROM assinaturas_email_cartao_historico
            WHERE empresa_id = ? AND assinatura_id IN ({$marcadores})
            ORDER BY alterado_em DESC, id DESC
        ");
        $stmt->execute(array_merge([$empresaId], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $historico) {
            $historico['data_formatada'] = assinaturasEmailDataHora($historico['alterado_em'] ?? null);
            $historicosPorAssinatura[(int)$historico['assinatura_id']][] = $historico;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <?php include 'includes/head.php'; ?>
    <title>Assinaturas de E-mail</title>
    <link rel="stylesheet" href="<?= assetUrl('assets/assinaturas_email.css') ?>">
</head>

<body class="app-layout assinaturas-email-page">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h3 class="mb-1">Assinaturas de E-mail</h3>
                    <p class="text-muted mb-0">Espaço contratado, cartão utilizado e dia do desconto</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary" id="btnNovaAssinatura" data-bs-toggle="modal" data-bs-target="#modalAssinaturaEmail" <?= !$estruturaDisponivel ? 'disabled' : '' ?>>
                        <i class="bi bi-plus-lg"></i> Novo e-mail
                    </button>
                    <a href="outros_servicos.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>

            <?php if (is_array($flash)): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?> alert-dismissible alert-auto-dismiss fade show" role="alert">
                    <?= htmlspecialchars($flash['mensagem']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>

            <?php if ($erro !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($erro) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>

            <?php if (!$estruturaDisponivel): ?>
                <div class="alert alert-warning">
                    <strong>Não foi possível criar a estrutura automaticamente.</strong>
                    <p class="mb-2"><?= htmlspecialchars($estruturaErro ?: 'Execute o SQL abaixo no banco de dados.') ?></p>
                    <details>
                        <summary>Ver SQL para instalação manual</summary>
                        <pre class="assinaturas-email-sql mt-2"><?= htmlspecialchars((string)@file_get_contents(__DIR__ . '/sql/assinaturas_email.sql')) ?></pre>
                    </details>
                </div>
            <?php else: ?>
                <section class="assinaturas-email-controle">
                    <div class="assinaturas-email-filtros">
                        <div class="input-group assinaturas-email-busca">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="buscaAssinaturas" placeholder="Buscar por e-mail, provedor, espaço ou cartão..." autocomplete="off">
                        </div>
                        <select class="form-select" id="porPaginaAssinaturas" aria-label="Quantidade por página">
                            <?php foreach ($opcoesPorPagina as $opcao): ?>
                                <option value="<?= $opcao ?>" <?= $porPagina === $opcao ? 'selected' : '' ?>>Mostrar <?= $opcao ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="assinaturas-email-total" id="totalAssinaturasEmail"><?= $totalRegistros ?> <?= $totalRegistros === 1 ? 'e-mail' : 'e-mails' ?></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>E-mail</th>
                                    <th>Provedor</th>
                                    <th>Espaço</th>
                                    <th>Cartão</th>
                                    <th>Dia do desconto</th>
                                    <th>Observações</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="listaAssinaturasEmail">
                                <tr id="assinaturasEmailVazio" <?= $registros !== [] ? 'hidden' : '' ?>>
                                    <td colspan="7" class="text-center text-muted py-5">Nenhum e-mail encontrado.</td>
                                </tr>
                                <?php foreach ($registros as $registro): ?>
                                    <?php
                                    $historicoJson = json_encode(
                                        $historicosPorAssinatura[(int)$registro['id']] ?? [],
                                        JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
                                    );
                                    ?>
                                    <tr class="assinatura-email-linha">
                                        <td><strong><?= htmlspecialchars($registro['email']) ?></strong></td>
                                        <td><?= htmlspecialchars($registro['provedor']) ?></td>
                                        <td><?= htmlspecialchars($registro['espaco_contratado']) ?></td>
                                        <td><span class="assinaturas-email-cartao"><?= htmlspecialchars(assinaturasEmailCartaoRotulo($registro['cartao_final'])) ?></span></td>
                                        <td><?= htmlspecialchars(assinaturasEmailDiaRotulo($registro['dia_desconto'])) ?></td>
                                        <td class="assinaturas-email-observacao" title="<?= htmlspecialchars((string)($registro['observacoes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($registro['observacoes'] ?: '-') ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary btn-historico-cartao" data-bs-toggle="offcanvas" data-bs-target="#historicoCartao" data-email="<?= htmlspecialchars($registro['email'], ENT_QUOTES, 'UTF-8') ?>" data-historico="<?= htmlspecialchars($historicoJson ?: '[]', ENT_QUOTES, 'UTF-8') ?>" title="Histórico do cartão" aria-label="Histórico do cartão">
                                                    <i class="bi bi-clock-history"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-primary btn-editar-assinatura" data-bs-toggle="modal" data-bs-target="#modalAssinaturaEmail" data-id="<?= (int)$registro['id'] ?>" data-email="<?= htmlspecialchars($registro['email'], ENT_QUOTES, 'UTF-8') ?>" data-provedor="<?= htmlspecialchars($registro['provedor'], ENT_QUOTES, 'UTF-8') ?>" data-espaco="<?= htmlspecialchars($registro['espaco_contratado'], ENT_QUOTES, 'UTF-8') ?>" data-cartao="<?= htmlspecialchars($registro['cartao_final'], ENT_QUOTES, 'UTF-8') ?>" data-dia="<?= (int)$registro['dia_desconto'] ?>" data-observacoes="<?= htmlspecialchars((string)($registro['observacoes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" title="Editar" aria-label="Editar">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-excluir-assinatura" data-bs-toggle="modal" data-bs-target="#modalExcluirAssinatura" data-id="<?= (int)$registro['id'] ?>" data-email="<?= htmlspecialchars($registro['email'], ENT_QUOTES, 'UTF-8') ?>" title="Excluir" aria-label="Excluir">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <nav class="assinaturas-email-paginacao" id="paginacaoAssinaturasEmail" aria-label="Paginação das assinaturas de e-mail">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="assinaturasEmailAnterior">Anterior</button>
                        <span id="assinaturasEmailPagina">Página 1 de 1</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="assinaturasEmailProxima">Próxima</button>
                    </nav>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($estruturaDisponivel): ?>
        <div class="modal fade" id="modalAssinaturaEmail" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" id="formAssinaturaEmail" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(assinaturasEmailToken()) ?>">
                        <input type="hidden" name="acao" value="salvar">
                        <input type="hidden" name="id" id="assinaturaEmailId">
                        <div class="modal-header">
                            <h5 class="modal-title" id="tituloModalAssinatura">Novo e-mail</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="assinaturaEmail">E-mail</label>
                                <input type="email" class="form-control" name="email" id="assinaturaEmail" maxlength="254" required>
                                <div class="invalid-feedback">Informe um e-mail válido.</div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="assinaturaProvedor">Provedor</label>
                                    <input type="text" class="form-control" name="provedor" id="assinaturaProvedor" maxlength="80" list="provedoresEmail" placeholder="Ex.: Google" required>
                                    <datalist id="provedoresEmail">
                                        <option value="Google">
                                        <option value="Microsoft">
                                        <option value="Hostinger">
                                        <option value="Outro">
                                    </datalist>
                                    <div class="invalid-feedback">Informe o provedor.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="assinaturaEspaco">Espaço contratado</label>
                                    <input type="text" class="form-control" name="espaco_contratado" id="assinaturaEspaco" maxlength="80" placeholder="Ex.: 100 GB" required>
                                    <div class="invalid-feedback">Informe o espaço contratado.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="assinaturaCartao">Final do cartão</label>
                                    <div class="input-group has-validation">
                                        <span class="input-group-text">••••</span>
                                        <input type="text" class="form-control" name="cartao_final" id="assinaturaCartao" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="0000" required>
                                        <div class="invalid-feedback">Informe os quatro últimos dígitos.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="assinaturaDia">Dia do desconto</label>
                                    <input type="number" class="form-control" name="dia_desconto" id="assinaturaDia" min="1" max="31" step="1" placeholder="Ex.: 15" required>
                                    <div class="invalid-feedback">Informe um dia entre 1 e 31.</div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label" for="assinaturaObservacoes">Observações</label>
                                <textarea class="form-control" name="observacoes" id="assinaturaObservacoes" rows="3" maxlength="2000"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="modalExcluirAssinatura" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(assinaturasEmailToken()) ?>">
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="id" id="excluirAssinaturaId">
                        <div class="modal-header">
                            <h5 class="modal-title">Excluir e-mail</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">Excluir o controle de <strong id="excluirAssinaturaEmail"></strong>?</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Excluir</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="offcanvas offcanvas-end" tabindex="-1" id="historicoCartao" aria-labelledby="historicoCartaoTitulo">
            <div class="offcanvas-header">
                <div>
                    <h5 class="offcanvas-title" id="historicoCartaoTitulo">Histórico do cartão</h5><small class="text-muted" id="historicoCartaoEmail"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
            </div>
            <div class="offcanvas-body" id="historicoCartaoConteudo"></div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('formAssinaturaEmail');
            const campoId = document.getElementById('assinaturaEmailId');
            const campoEmail = document.getElementById('assinaturaEmail');
            const campoProvedor = document.getElementById('assinaturaProvedor');
            const campoEspaco = document.getElementById('assinaturaEspaco');
            const campoCartao = document.getElementById('assinaturaCartao');
            const campoDia = document.getElementById('assinaturaDia');
            const campoObservacoes = document.getElementById('assinaturaObservacoes');
            const tituloModal = document.getElementById('tituloModalAssinatura');

            document.getElementById('btnNovaAssinatura')?.addEventListener('click', () => {
                form.reset();
                form.classList.remove('was-validated');
                campoId.value = '';
                tituloModal.textContent = 'Novo e-mail';
            });

            document.querySelectorAll('.btn-editar-assinatura').forEach((botao) => {
                botao.addEventListener('click', () => {
                    form.reset();
                    form.classList.remove('was-validated');
                    campoId.value = botao.dataset.id || '';
                    campoEmail.value = botao.dataset.email || '';
                    campoProvedor.value = botao.dataset.provedor || '';
                    campoEspaco.value = botao.dataset.espaco || '';
                    campoCartao.value = botao.dataset.cartao || '';
                    campoDia.value = botao.dataset.dia || '';
                    campoObservacoes.value = botao.dataset.observacoes || '';
                    tituloModal.textContent = 'Editar e-mail';
                });
            });

            campoCartao?.addEventListener('input', () => {
                campoCartao.value = campoCartao.value.replace(/\D/g, '').slice(0, 4);
            });

            form?.addEventListener('submit', (evento) => {
                campoCartao.setCustomValidity(campoCartao.value.length === 4 ? '' : 'Informe quatro dígitos.');
                if (!form.checkValidity()) {
                    evento.preventDefault();
                    evento.stopPropagation();
                }
                form.classList.add('was-validated');
            });

            document.querySelectorAll('.btn-excluir-assinatura').forEach((botao) => {
                botao.addEventListener('click', () => {
                    document.getElementById('excluirAssinaturaId').value = botao.dataset.id || '';
                    document.getElementById('excluirAssinaturaEmail').textContent = botao.dataset.email || '';
                });
            });

            document.querySelectorAll('.btn-historico-cartao').forEach((botao) => {
                botao.addEventListener('click', () => {
                    const conteudo = document.getElementById('historicoCartaoConteudo');
                    document.getElementById('historicoCartaoEmail').textContent = botao.dataset.email || '';
                    let historico = [];
                    try {
                        historico = JSON.parse(botao.dataset.historico || '[]');
                    } catch (erro) {
                        historico = [];
                    }
                    conteudo.replaceChildren();

                    if (!historico.length) {
                        const vazio = document.createElement('p');
                        vazio.className = 'text-muted';
                        vazio.textContent = 'Nenhuma troca de cartão registrada.';
                        conteudo.appendChild(vazio);
                        return;
                    }

                    historico.forEach((item) => {
                        const registro = document.createElement('div');
                        registro.className = 'assinaturas-email-historico-item';
                        const data = document.createElement('small');
                        data.textContent = item.data_formatada || '-';
                        const troca = document.createElement('strong');
                        troca.textContent = `•••• ${item.cartao_final_anterior} → •••• ${item.cartao_final_novo}`;
                        const usuario = document.createElement('span');
                        usuario.textContent = `Alterado por ${item.alterado_por_nome || 'Usuário'}`;
                        registro.append(data, troca, usuario);
                        conteudo.appendChild(registro);
                    });
                });
            });

            const busca = document.getElementById('buscaAssinaturas');
            const seletorPorPagina = document.getElementById('porPaginaAssinaturas');
            const linhas = Array.from(document.querySelectorAll('.assinatura-email-linha'));
            const linhaVazia = document.getElementById('assinaturasEmailVazio');
            const resumo = document.getElementById('totalAssinaturasEmail');
            const paginacao = document.getElementById('paginacaoAssinaturasEmail');
            const paginaTexto = document.getElementById('assinaturasEmailPagina');
            const botaoAnterior = document.getElementById('assinaturasEmailAnterior');
            const botaoProxima = document.getElementById('assinaturasEmailProxima');
            let paginaAtual = 1;

            const normalizarBusca = (valor) => valor
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim();

            linhas.forEach((linha) => {
                linha.dataset.busca = normalizarBusca(linha.textContent || '');
            });

            function renderizarAssinaturas() {
                const termo = normalizarBusca(busca?.value || '');
                const porPaginaAtual = Number(seletorPorPagina?.value || 15);
                const filtradas = linhas.filter((linha) => linha.dataset.busca.includes(termo));
                const totalPaginas = Math.max(1, Math.ceil(filtradas.length / porPaginaAtual));
                paginaAtual = Math.min(paginaAtual, totalPaginas);
                const inicio = (paginaAtual - 1) * porPaginaAtual;
                const fim = inicio + porPaginaAtual;
                const visiveis = new Set(filtradas.slice(inicio, fim));

                linhas.forEach((linha) => {
                    linha.hidden = !visiveis.has(linha);
                });

                if (linhaVazia) {
                    linhaVazia.hidden = filtradas.length !== 0;
                }
                if (resumo) {
                    resumo.textContent = filtradas.length === 0 ?
                        'Nenhum e-mail' :
                        `Mostrando ${Math.min(porPaginaAtual, filtradas.length - inicio)} de ${filtradas.length}`;
                }
                if (paginaTexto) {
                    paginaTexto.textContent = `Página ${paginaAtual} de ${totalPaginas}`;
                }
                if (botaoAnterior) {
                    botaoAnterior.disabled = paginaAtual <= 1;
                }
                if (botaoProxima) {
                    botaoProxima.disabled = paginaAtual >= totalPaginas;
                }
                paginacao?.classList.toggle('d-none', totalPaginas <= 1);
            }

            busca?.addEventListener('input', () => {
                paginaAtual = 1;
                renderizarAssinaturas();
            });
            seletorPorPagina?.addEventListener('change', () => {
                paginaAtual = 1;
                renderizarAssinaturas();
            });
            botaoAnterior?.addEventListener('click', () => {
                paginaAtual = Math.max(1, paginaAtual - 1);
                renderizarAssinaturas();
            });
            botaoProxima?.addEventListener('click', () => {
                paginaAtual += 1;
                renderizarAssinaturas();
            });

            renderizarAssinaturas();
        });
    </script>
</body>

</html>