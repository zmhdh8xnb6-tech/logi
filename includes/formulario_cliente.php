<?php
$orgaosAlvara = [
    'ibram' => 'INSTITUTO BRASÍLIA AMBIENTAL - IBRAM',
    'cbmdf' => 'CORPO DE BOMBEIROS MILITAR DO DISTRITO FEDERAL - CBMDF',
    'df_legal' => 'SECRETARIA DE ESTADO DE PROTEÇÃO DA ORDEM URBANÍSTICA DO DISTRITO FEDERAL - DF LEGAL',
    'pcdf' => 'POLÍCIA CIVIL DO DISTRITO FEDERAL - PCDF',
    'seagri' => 'SECRETARIA DE ESTADO DE AGRICULTURA, ABASTECIMENTO E DESENVOLVIMENTO RURAL - SEAGRI',
    'seedf' => 'SECRETARIA DE EDUCAÇÃO DO DISTRITO FEDERAL - SEEDF',
    'sudesc' => 'SUBSECRETARIA DO SISTEMA DE DEFESA CIVIL - SUDESC',
    'visadf' => 'VIGILÂNCIA SANITÁRIA DO DISTRITO FEDERAL - VISADF',
];
$orgaosAlvaraGoias = [
    'bombeiros' => 'Bombeiros',
    'vigilancia' => 'Vigilância',
    'prefeitura' => 'Prefeitura',
];

$alvarasCliente = $alvarasCliente ?? [];
$alvarasGoiasCliente = $alvarasGoiasCliente ?? [];
$sociosCliente = $sociosCliente ?? [];
$clienteContabilAtual = (int)($cliente['cliente_contabil'] ?? $clienteContabilPadrao ?? 1);
$servicoParcelamentoAtual = (int)($cliente['servico_parcelamento'] ?? (($cliente['possui_parcelamento'] ?? '') === 'possui'));
$servicoCertificadoAtual = (int)($cliente['servico_certificado'] ?? 0);
$ocultarServicosAcompanhados = isset($pdo)
    && $pdo instanceof PDO
    && function_exists('empresaAtivaNome')
    && strcasecmp(trim(empresaAtivaNome($pdo)), 'MAXWELL') === 0;

if ($ocultarServicosAcompanhados) {
    $clienteContabilAtual = 0;
}
$formatarDataQsa = $formatarDataQsa ?? static function ($data): string {
    return !empty($data) ? date('d/m/Y', strtotime($data)) : '-';
};

if (
    empty($alvarasGoiasCliente)
    && isset($pdo)
    && $pdo instanceof PDO
    && function_exists('logiTabelaExiste')
    && function_exists('empresaFiltroClienteDireto')
    && logiTabelaExiste($pdo, 'cliente_alvaras_goias')
    && !empty($cliente['id'])
) {
    $stmtAlvarasGoiasFormulario = $pdo->prepare("
        SELECT ag.orgao_codigo, ag.situacao, ag.vencimento, ag.taxa, ag.vistoria_previa
        FROM cliente_alvaras_goias ag
        INNER JOIN clientes c ON c.id = ag.cliente_id
        WHERE ag.cliente_id = ?
        " . empresaFiltroClienteDireto($pdo, 'c') . "
    ");
    $stmtAlvarasGoiasFormulario->execute([(int)$cliente['id']]);

    foreach ($stmtAlvarasGoiasFormulario->fetchAll(PDO::FETCH_ASSOC) as $alvaraGoiasCliente) {
        $alvarasGoiasCliente[$alvaraGoiasCliente['orgao_codigo']] = $alvaraGoiasCliente;
    }
}
?>

<input type="hidden" name="qsa_json" id="qsa_json" value="">
<input type="hidden" id="ocultar_servicos_acompanhados" value="<?= $ocultarServicosAcompanhados ? '1' : '0' ?>">

<!-- DADOS PRINCIPAIS -->
<div class="border rounded p-3 mb-3">
    <h6 class="mb-3 fw-bold">Dados principais</h6>

    <div class="row">
        <div class="col-md-1 mb-3">
            <label for="codigo" class="form-label">Código</label>
            <input type="text" class="form-control" name="codigo" id="codigo">
        </div>

        <div class="col-md-3 mb-3">
            <label for="cliente_contabil" class="form-label">É cliente contábil?</label>
            <select class="form-select" name="cliente_contabil" id="cliente_contabil" required>
                <?php if ($ocultarServicosAcompanhados): ?>
                    <option value="0" selected>Não, serviço avulso</option>
                <?php else: ?>
                    <option value="1" <?= $clienteContabilAtual === 1 ? 'selected' : '' ?>>Sim</option>
                    <option value="0" <?= $clienteContabilAtual === 0 ? 'selected' : '' ?>>Não, serviço avulso</option>
                <?php endif; ?>
            </select>
            <div class="invalid-feedback">Informe se é cliente contábil.</div>
        </div>

        <div class="col-md-2 mb-3">
            <label for="documento" class="form-label">CPF / CNPJ</label>
            <input type="text" class="form-control" name="documento" id="documento">
        </div>

        <div class="col-md-5 mb-3">
            <label for="nome" class="form-label">Razão Social</label>
            <input type="text" class="form-control" name="nome" id="nome">
        </div>

        <div class="col-md-4 mb-3">
            <label for="nome_fantasia" class="form-label">Nome Fantasia</label>
            <input type="text" class="form-control" name="nome_fantasia" id="nome_fantasia">
        </div>

        <div class="col-md-4 mb-3">
            <label for="email" class="form-label">E-mail</label>
            <input type="email" class="form-control" name="email" id="email">
        </div>

        <div class="col-md-2 mb-3">
            <label for="telefone" class="form-label">Telefone</label>
            <input type="text" class="form-control" name="telefone" id="telefone">
        </div>

        <div class="col-md-2 mb-3 campo-cliente-contabil">
            <label for="inscricao_estadual" class="form-label">Inscrição Estadual</label>
            <input
                type="text"
                class="form-control"
                name="inscricao_estadual"
                id="inscricao_estadual"
                placeholder="Número ou ISENTO">
            <div class="invalid-feedback" id="inscricaoEstadualFeedback">
                Inscrição Estadual inválida para a UF informada.
            </div>
        </div>

        <div class="col-md-2 mb-3 campo-cliente-contabil">
            <label for="nire" class="form-label">NIRE</label>
            <input type="text" class="form-control" name="nire" id="nire">
        </div>
        <div class="col-md-2 mb-3 campo-servico-certificado">
            <label for="certificado_status" class="form-label">Certificado Digital</label>
            <select class="form-select" name="certificado_status" id="certificado_status">
                <?php
                $certificadoStatusAtual = $cliente['certificado_status'] ?? (!empty($cliente['vencimento_certificado']) ? 'possui' : '');
                ?>
                <option value="" <?= $certificadoStatusAtual === '' ? 'selected' : '' ?>>Selecione</option>
                <option value="possui" <?= $certificadoStatusAtual === 'possui' ? 'selected' : '' ?>>Possui</option>
                <option value="nao_possui" <?= $certificadoStatusAtual === 'nao_possui' ? 'selected' : '' ?>>Não possui</option>
                <option value="nao_precisa_momento" <?= $certificadoStatusAtual === 'nao_precisa_momento' ? 'selected' : '' ?>>Não precisa no momento</option>
            </select>
        </div>

        <div class="col-md-2 mb-3 campo-servico-certificado">
            <label for="vencimento_certificado" class="form-label">
                Vencimento Certificado Digital
            </label>
            <input
                type="date"
                class="form-control"
                name="vencimento_certificado"
                id="vencimento_certificado">
        </div>
    </div>
</div>

<div class="border rounded p-3 mb-3 secao-cliente-contabil">
    <h6 class="mb-3 fw-bold">Controles internos</h6>

    <div class="row">

        <div class="col-md-3 mb-3">
            <label for="cadastro_df_legal" class="form-label">Cadastro DF Legal</label>
            <select class="form-select" name="cadastro_df_legal" id="cadastro_df_legal">
                <option value="">Selecione</option>
                <option value="cadastrado">Cadastrado</option>
                <option value="nao_cadastrado">Não cadastrado</option>
                <option value="nao_precisa_momento">Não precisa no momento</option>
                <option value="goias">Goiás</option>
            </select>
            <input type="hidden" name="df_legal_razao_social_correta" id="df_legal_razao_social_correta" value="sim">
            <input type="hidden" name="df_legal_endereco_correto" id="df_legal_endereco_correto" value="sim">
        </div>

        <div class="col-md-3 mb-3">
            <label for="alvara" class="form-label">Alvará</label>
            <div class="d-flex gap-2">
                <select class="form-select controle-interno-obrigatorio" name="alvara" id="alvara" required>
                    <option value="">Selecione</option>
                    <option value="possui">Possui</option>
                    <option value="nao_possui">Não possui</option>
                    <option value="nao_precisa_momento">Não precisa no momento</option>
                    <option value="goias">Goiás</option>
                </select>
                <button
                    type="button"
                    class="btn btn-outline-primary<?= (($cliente['alvara'] ?? '') === 'possui') ? '' : ' d-none' ?>"
                    id="btnEditarAlvaras"
                    data-bs-toggle="modal"
                    data-bs-target="#modalAlvaras"
                    title="Editar órgãos do alvará">
                    <i class="bi bi-pencil"></i>
                </button>
                <button
                    type="button"
                    class="btn btn-outline-primary<?= (($cliente['alvara'] ?? '') === 'goias') ? '' : ' d-none' ?>"
                    id="btnEditarAlvarasGoias"
                    data-bs-toggle="modal"
                    data-bs-target="#modalAlvarasGoiasCliente"
                    title="Editar alvarás Goiás">
                    <i class="bi bi-pencil"></i>
                </button>
            </div>
            <div class="invalid-feedback">Informe a situação do alvará.</div>
        </div>

        <div class="col-md-3 mb-3">
            <label for="possui_parcelamento" class="form-label">Parcelamento</label>
            <select
                class="form-select controle-interno-obrigatorio"
                name="possui_parcelamento"
                id="possui_parcelamento"
                required>
                <option value="">Selecione</option>
                <option value="possui" <?= (($cliente['possui_parcelamento'] ?? '') === 'possui') ? 'selected' : '' ?>>Possui</option>
                <option value="nao_possui" <?= (($cliente['possui_parcelamento'] ?? '') === 'nao_possui') ? 'selected' : '' ?>>Não possui</option>
                <option value="nao_precisa_momento" <?= (($cliente['possui_parcelamento'] ?? '') === 'nao_precisa_momento') ? 'selected' : '' ?>>Não precisa no momento</option>
            </select>
            <div class="invalid-feedback">Informe se possui parcelamento.</div>
        </div>

        <div class="col-md-3 mb-3">
            <label for="contador" class="form-label">Contador</label>
            <select class="form-select" name="contador" id="contador">
                <option value="">Selecione</option>
                <option value="sim">Contador ativo</option>
                <option value="nao">Sem contador</option>
                <option value="nao_precisa_momento">Não precisa no momento</option>
            </select>
        </div>

        <div class="col-md-3 mb-3">
            <label for="cadastro_crf" class="form-label">Cadastro CRF</label>
            <select class="form-select" name="cadastro_crf" id="cadastro_crf">
                <option value="">Selecione</option>
                <option value="cadastrado">Cadastrado</option>
                <option value="nao_cadastrado">Não cadastrado</option>
                <option value="nao_precisa_momento">Não precisa no momento</option>
            </select>
            <input type="hidden" name="crf_razao_social_correta" id="crf_razao_social_correta" value="sim">
            <input type="hidden" name="crf_endereco_correto" id="crf_endereco_correto" value="sim">
        </div>

        <div class="col-md-3 mb-3">
            <label for="procuracao_receita_federal" class="form-label">Procuração Receita Federal</label>
            <select class="form-select controle-com-vencimento procuracao-obrigatoria" name="procuracao_receita_federal" id="procuracao_receita_federal" data-vencimento="vencimento_procuracao_receita_federal" required>
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
                <option value="nao_precisa_momento">Não precisa no momento</option>
            </select>
        </div>

        <div class="col-md-3 mb-3">
            <label for="vencimento_procuracao_receita_federal" class="form-label">Vencimento Procuração Receita</label>
            <input type="date" class="form-control" name="vencimento_procuracao_receita_federal" id="vencimento_procuracao_receita_federal" disabled>
        </div>

        <div class="col-md-3 mb-3">
            <label for="procuracao_conectividade" class="form-label">Procuração Conectividade</label>
            <select class="form-select controle-com-vencimento procuracao-obrigatoria" name="procuracao_conectividade" id="procuracao_conectividade" data-vencimento="vencimento_procuracao_conectividade" required>
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
                <option value="nao_precisa_momento">Não precisa no momento</option>
            </select>
        </div>

        <div class="col-md-3 mb-3">
            <label for="vencimento_procuracao_conectividade" class="form-label">Vencimento Conectividade</label>
            <input type="date" class="form-control" name="vencimento_procuracao_conectividade" id="vencimento_procuracao_conectividade" disabled>
        </div>

        <div class="col-md-3 mb-3">
            <label for="procuracao_empregador_web" class="form-label">Procuração Empregador Web</label>
            <select class="form-select procuracao-obrigatoria" name="procuracao_empregador_web" id="procuracao_empregador_web" required>
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
                <option value="nao_tem_funcionario">Não tem funcionário</option>
                <option value="nao_precisa_momento">Não precisa no momento</option>
            </select>
        </div>

        <div class="col-md-3 mb-3">
            <label for="procuracao_fgts" class="form-label">Procuração FGTS</label>
            <select class="form-select controle-com-vencimento procuracao-obrigatoria" name="procuracao_fgts" id="procuracao_fgts" data-vencimento="vencimento_procuracao_fgts" required>
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
                <option value="nao_precisa_momento">Não precisa no momento</option>
            </select>
        </div>

        <div class="col-md-3 mb-3">
            <label for="vencimento_procuracao_fgts" class="form-label">Vencimento Procuração FGTS</label>
            <input type="date" class="form-control" name="vencimento_procuracao_fgts" id="vencimento_procuracao_fgts" disabled>
        </div>

        <div class="col-md-3 mb-3">
            <label for="procuracao_particular" class="form-label">Procuração Particular</label>
            <select class="form-select procuracao-obrigatoria" name="procuracao_particular" id="procuracao_particular" required>
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
                <option value="nao_precisa_momento">Não precisa no momento</option>
            </select>
            <input type="hidden" name="procuracao_particular_razao_social_correta" id="procuracao_particular_razao_social_correta" value="sim">
            <input type="hidden" name="procuracao_particular_endereco_correto" id="procuracao_particular_endereco_correto" value="sim">
            <input type="hidden" name="procuracao_particular_socio_correto" id="procuracao_particular_socio_correto" value="sim">
        </div>

        <div class="col-md-3 mb-3">
            <label for="procuracao_sefaz" class="form-label">Procuração SEFAZ</label>
            <select class="form-select procuracao-obrigatoria" name="procuracao_sefaz" id="procuracao_sefaz" required>
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
                <option value="nao_precisa_momento">Não precisa no momento</option>
                <option value="goias">Goiás</option>
            </select>
        </div>

        <div class="col-md-3 mb-3">
            <label for="contrato_prestacao_servicos" class="form-label">Contrato de Prestação de Serviços</label>
            <select class="form-select" name="contrato_prestacao_servicos" id="contrato_prestacao_servicos">
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
                <option value="nao_precisa_momento">Não precisa no momento</option>
            </select>
        </div>

        <div class="col-md-3 mb-3">
            <label for="tributacao" class="form-label">Tributação</label>
            <select class="form-select" name="tributacao" id="tributacao">
                <option value="">Selecione</option>
                <option value="simples_nacional">Simples Nacional</option>
                <option value="lucro_presumido">Lucro Presumido</option>
                <option value="lucro_real">Lucro Real</option>
                <option value="mei">Microempreendedor Individual</option>
            </select>
        </div>

    </div>
</div>

<div
    class="border rounded p-3 mb-3 secao-servicos-avulsos<?= ($ocultarServicosAcompanhados || $clienteContabilAtual === 1) ? ' d-none' : '' ?>"
    <?= ($ocultarServicosAcompanhados || $clienteContabilAtual === 1) ? 'hidden' : '' ?>>
    <h6 class="mb-3 fw-bold">Serviços acompanhados</h6>

    <div class="d-flex flex-wrap gap-4">
        <div class="form-check form-switch">
            <input
                class="form-check-input"
                type="checkbox"
                role="switch"
                name="servico_parcelamento"
                id="servico_parcelamento"
                value="1"
                <?= $ocultarServicosAcompanhados ? 'disabled' : '' ?>
                <?= $servicoParcelamentoAtual ? 'checked' : '' ?>>
            <label class="form-check-label" for="servico_parcelamento">Parcelamento</label>
        </div>

        <div class="form-check form-switch">
            <input
                class="form-check-input"
                type="checkbox"
                role="switch"
                name="servico_certificado"
                id="servico_certificado"
                value="1"
                <?= $ocultarServicosAcompanhados ? 'disabled' : '' ?>
                <?= $servicoCertificadoAtual ? 'checked' : '' ?>>
            <label class="form-check-label" for="servico_certificado">Certificado Digital</label>
        </div>
    </div>
    <div class="invalid-feedback d-none" id="servicosAvulsosFeedback">
        Selecione pelo menos um serviço para o cadastro avulso.
    </div>
</div>

<div class="modal fade" id="modalConferenciaCadastro" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tituloModalConferenciaCadastro">Conferir dados</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" id="prefixoConferenciaCadastro">

                <div class="mb-3">
                    <label class="form-label d-block">Razão social está correta?</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input conferencia-cadastro" type="radio" name="modal_razao_social_correta" id="modal_razao_social_sim" value="sim">
                        <label class="form-check-label" for="modal_razao_social_sim">Sim</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input conferencia-cadastro" type="radio" name="modal_razao_social_correta" id="modal_razao_social_nao" value="nao">
                        <label class="form-check-label" for="modal_razao_social_nao">Não</label>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label d-block">Endereço está correto?</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input conferencia-cadastro" type="radio" name="modal_endereco_correto" id="modal_endereco_sim" value="sim">
                        <label class="form-check-label" for="modal_endereco_sim">Sim</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input conferencia-cadastro" type="radio" name="modal_endereco_correto" id="modal_endereco_nao" value="nao">
                        <label class="form-check-label" for="modal_endereco_nao">Não</label>
                    </div>
                </div>

                <div class="mb-3 d-none" id="grupoModalSocioCorreto">
                    <label class="form-label d-block">Sócio está correto?</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input conferencia-cadastro" type="radio" name="modal_socio_correto" id="modal_socio_sim" value="sim">
                        <label class="form-check-label" for="modal_socio_sim">Sim</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input conferencia-cadastro" type="radio" name="modal_socio_correto" id="modal_socio_nao" value="nao">
                        <label class="form-check-label" for="modal_socio_nao">Não</label>
                    </div>
                    <div class="form-text">Por enquanto o sistema ainda não cadastra sócios, então isso fica como pendência operacional.</div>
                </div>

                <div class="alert alert-danger d-none mb-0" id="alertaConferenciaCadastro">
                    Responda todas as perguntas antes de concluir.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="btnConcluirConferenciaCadastro">Concluir</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAlvaras" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Órgãos e vencimentos dos alvarás</h5>
                    <small class="text-muted">Todos os órgãos devem ser preenchidos: informe o vencimento, marque como dispensado ou em estudo.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger d-none" id="alertaAlvarasObrigatorios">
                    Preencha todos os órgãos. Escolha “Com vencimento” e informe a data, ou marque como “Dispensado”.
                </div>

                <div class="d-flex justify-content-end mb-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnDispensarTodosAlvaras">
                        <i class="bi bi-check2-all"></i> Marcar todos como dispensado
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Órgão</th>
                                <th style="width: 190px;">Situação</th>
                                <th style="width: 180px;">Vencimento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orgaosAlvara as $codigoOrgao => $nomeOrgao):
                                $alvaraAtual = $alvarasCliente[$codigoOrgao] ?? [];
                                $situacaoAtual = $alvaraAtual['situacao'] ?? '';
                                $vencimentoAtual = $alvaraAtual['vencimento'] ?? '';
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($nomeOrgao) ?></td>
                                    <td>
                                        <select
                                            class="form-select alvara-situacao"
                                            name="alvaras[<?= htmlspecialchars($codigoOrgao) ?>][situacao]"
                                            data-vencimento="alvara_vencimento_<?= htmlspecialchars($codigoOrgao) ?>"
                                            <?= (($cliente['alvara'] ?? '') === 'possui') ? 'required' : '' ?>>
                                            <option value="" <?= $situacaoAtual === '' ? 'selected' : '' ?>>Não informado</option>
                                            <option value="com_vencimento" <?= $situacaoAtual === 'com_vencimento' ? 'selected' : '' ?>>Com vencimento</option>
                                            <option value="dispensado" <?= $situacaoAtual === 'dispensado' ? 'selected' : '' ?>>Dispensado</option>
                                            <option value="em_estudo" <?= $situacaoAtual === 'em_estudo' ? 'selected' : '' ?>>Em estudo</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input
                                            type="date"
                                            class="form-control alvara-vencimento"
                                            name="alvaras[<?= htmlspecialchars($codigoOrgao) ?>][vencimento]"
                                            id="alvara_vencimento_<?= htmlspecialchars($codigoOrgao) ?>"
                                            value="<?= htmlspecialchars($vencimentoAtual) ?>"
                                            <?= $situacaoAtual === 'com_vencimento' ? 'required' : '' ?>
                                            <?= $situacaoAtual !== 'com_vencimento' ? 'disabled' : '' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="btnConcluirAlvaras">Concluir</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAlvarasGoiasCliente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Alvarás Goiás</h5>
                    <small class="text-muted">Informe Bombeiros, Vigilância e Prefeitura com vencimento, taxa e vistoria prévia quando existir.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger d-none" id="alertaAlvarasGoiasObrigatorios">
                    Quando marcar “Com vencimento”, informe a data do vencimento.
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Órgão</th>
                                <th style="width: 190px;">Situação</th>
                                <th style="width: 180px;">Vencimento</th>
                                <th style="width: 160px;">Taxa</th>
                                <th style="width: 190px;">Vistoria prévia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orgaosAlvaraGoias as $codigoOrgao => $nomeOrgao):
                                $alvaraGoiasAtual = $alvarasGoiasCliente[$codigoOrgao] ?? [];
                                $situacaoGoiasAtual = $alvaraGoiasAtual['situacao'] ?? 'nao_informado';
                                $vencimentoGoiasAtual = $alvaraGoiasAtual['vencimento'] ?? '';
                                $taxaGoiasAtual = isset($alvaraGoiasAtual['taxa'])
                                    ? number_format((float)$alvaraGoiasAtual['taxa'], 2, ',', '.')
                                    : '0,00';
                                $vistoriaGoiasAtual = $alvaraGoiasAtual['vistoria_previa'] ?? 'sim';
                            ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars($nomeOrgao) ?></td>
                                    <td>
                                        <select
                                            class="form-select alvara-goias-situacao"
                                            name="alvaras_goias[<?= htmlspecialchars($codigoOrgao) ?>][situacao]"
                                            data-vencimento="alvara_goias_vencimento_<?= htmlspecialchars($codigoOrgao) ?>">
                                            <option value="nao_informado" <?= $situacaoGoiasAtual === 'nao_informado' || $situacaoGoiasAtual === '' ? 'selected' : '' ?>>Não informado</option>
                                            <option value="com_vencimento" <?= $situacaoGoiasAtual === 'com_vencimento' ? 'selected' : '' ?>>Com vencimento</option>
                                            <option value="dispensado" <?= $situacaoGoiasAtual === 'dispensado' ? 'selected' : '' ?>>Dispensado</option>
                                            <option value="em_estudo" <?= $situacaoGoiasAtual === 'em_estudo' ? 'selected' : '' ?>>Em estudo</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input
                                            type="date"
                                            class="form-control alvara-goias-vencimento"
                                            name="alvaras_goias[<?= htmlspecialchars($codigoOrgao) ?>][vencimento]"
                                            id="alvara_goias_vencimento_<?= htmlspecialchars($codigoOrgao) ?>"
                                            value="<?= htmlspecialchars($vencimentoGoiasAtual) ?>"
                                            <?= $situacaoGoiasAtual === 'com_vencimento' ? 'required' : '' ?>
                                            <?= $situacaoGoiasAtual !== 'com_vencimento' ? 'disabled' : '' ?>>
                                    </td>
                                    <td>
                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            class="form-control alvara-goias-taxa"
                                            name="alvaras_goias[<?= htmlspecialchars($codigoOrgao) ?>][taxa]"
                                            value="<?= htmlspecialchars($taxaGoiasAtual) ?>"
                                            placeholder="0,00">
                                    </td>
                                    <td>
                                        <select class="form-select" name="alvaras_goias[<?= htmlspecialchars($codigoOrgao) ?>][vistoria_previa]">
                                            <option value="sim" <?= $vistoriaGoiasAtual === 'sim' ? 'selected' : '' ?>>Sim</option>
                                            <option value="nao" <?= $vistoriaGoiasAtual === 'nao' ? 'selected' : '' ?>>Não</option>
                                            <option value="dispensada" <?= $vistoriaGoiasAtual === 'dispensada' ? 'selected' : '' ?>>Dispensada</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="btnConcluirAlvarasGoias">Concluir</button>
            </div>
        </div>
    </div>
</div>

<!-- ENDEREÇO -->
<div class="border rounded p-3 mb-3 secao-cliente-contabil">
    <h6 class="mb-3 fw-bold">Endereço</h6>

    <div class="col-md-1 mb-3">
        <label for="cep" class="form-label">CEP</label>
        <input type="text" class="form-control" name="cep" id="cep">
        <small id="cepFeedback" class="text-muted"></small>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="endereco" class="form-label">Endereço</label>
            <input type="text" class="form-control" name="endereco" id="endereco">
        </div>

        <div class="col-md-1 mb-3">
            <label for="numero_endereco" class="form-label">Número</label>
            <input type="text" class="form-control" name="numero_endereco" id="numero_endereco">
        </div>

        <div class="col-md-3 mb-3">
            <label for="complemento" class="form-label">Complemento</label>
            <input type="text" class="form-control" name="complemento" id="complemento">
        </div>

        <div class="col-md-4 mb-3">
            <label for="bairro" class="form-label">Bairro</label>
            <input type="text" class="form-control" name="bairro" id="bairro">
        </div>

        <div class="col-md-3 mb-3">
            <label for="cidade" class="form-label">Cidade</label>
            <input type="text" class="form-control" name="cidade" id="cidade">
        </div>

        <div class="col-md-1 mb-3">
            <label for="uf" class="form-label">UF</label>
            <input type="text" class="form-control text-uppercase" name="uf" id="uf"
                maxlength="2">
        </div>
    </div>
</div>

<!-- QSA -->
<div class="border rounded p-3 mb-3" id="qsaClienteBox">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h6 class="mb-1 fw-bold">QSA - Quadro Societário</h6>
            <p class="text-muted mb-0">Sócios encontrados pela consulta do CNPJ.</p>
        </div>

        <button type="button" class="btn btn-outline-primary btn-sm" id="btnAtualizarQsaReceita">
            <i class="bi bi-arrow-clockwise"></i> Atualizar pela Receita
        </button>
    </div>

    <div class="table-responsive<?= empty($sociosCliente) ? ' d-none' : '' ?>" id="qsaClienteTabelaWrapper">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Qualificação</th>
                    <th>Documento</th>
                    <th>Entrada</th>
                </tr>
            </thead>
            <tbody id="qsaClienteTabelaCorpo">
                <?php foreach ($sociosCliente as $socioCliente): ?>
                    <tr>
                        <td><?= htmlspecialchars($socioCliente['nome'] ?? '') ?></td>
                        <td><?= htmlspecialchars(trim((string)($socioCliente['qualificacao'] ?? '')) !== '' ? $socioCliente['qualificacao'] : '-') ?></td>
                        <td><?= htmlspecialchars(trim((string)($socioCliente['documento'] ?? '')) !== '' ? $socioCliente['documento'] : '-') ?></td>
                        <td><?= htmlspecialchars($formatarDataQsa($socioCliente['entrada_sociedade'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="text-muted mb-0<?= !empty($sociosCliente) ? ' d-none' : '' ?>" id="qsaClienteVazio">
        Nenhum sócio encontrado ainda. Ao consultar um CNPJ, os sócios aparecerão aqui antes de salvar.
    </p>
    <small class="text-muted d-block mt-2" id="qsaClienteStatus"></small>
</div>
</div>

<div class="modal fade" id="modalPreencherCnpj" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Dados encontrados pelo CNPJ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Encontramos dados desse CNPJ. Deseja preencher o cadastro automaticamente?
                </p>

                <div class="border rounded p-3 bg-light">
                    <div class="fw-semibold" id="cnpjConsultaRazao">-</div>
                    <div class="text-muted small" id="cnpjConsultaDocumento">-</div>
                    <div class="text-muted small mt-2" id="cnpjConsultaEndereco">-</div>
                    <div class="text-muted small mt-2" id="cnpjConsultaQsa">QSA não retornado pela consulta.</div>
                </div>

                <div class="form-text mt-2">
                    Confira os dados antes de salvar, porque informações públicas podem estar desatualizadas.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Não preencher</button>
                <button type="button" class="btn btn-primary" id="btnPreencherDadosCnpj">
                    Preencher cadastro
                </button>
            </div>
        </div>
    </div>
</div>