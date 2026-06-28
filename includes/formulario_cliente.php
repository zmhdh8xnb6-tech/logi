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

$alvarasCliente = $alvarasCliente ?? [];
?>

<!-- DADOS PRINCIPAIS -->
<div class="border rounded p-3 mb-3">
    <h6 class="mb-3 fw-bold">Dados principais</h6>

    <div class="row">
        <div class="col-md-1 mb-3">
            <label for="codigo" class="form-label">Código</label>
            <input type="text" class="form-control" name="codigo" id="codigo">
        </div>

        <div class="col-md-3 mb-3">
            <label for="tipo_atendimento" class="form-label">Tipo de atendimento</label>
            <select class="form-select" name="tipo_atendimento" id="tipo_atendimento" required>
                <option value="completo">Cliente completo</option>
                <option value="somente_parcelamento">Somente parcelamento</option>
            </select>
            <div class="invalid-feedback">Informe o tipo de atendimento.</div>
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

        <div class="col-md-2 mb-3 campo-cliente-completo">
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

        <div class="col-md-2 mb-3 campo-cliente-completo">
            <label for="nire" class="form-label">NIRE</label>
            <input type="text" class="form-control" name="nire" id="nire">
        </div>
        <div class="col-md-2 mb-3 campo-cliente-completo">
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

<div class="border rounded p-3 mb-3 secao-cliente-completo">
    <h6 class="mb-3 fw-bold">Controles internos</h6>

    <div class="row">

        <div class="col-md-3 mb-3">
            <label for="cadastro_df_legal" class="form-label">Cadastro DF Legal</label>
            <select class="form-select" name="cadastro_df_legal" id="cadastro_df_legal">
                <option value="">Selecione</option>
                <option value="cadastrado">Cadastrado</option>
                <option value="nao_cadastrado">Não cadastrado</option>
                <option value="goias">Goiás</option>
            </select>
            <input type="hidden" name="df_legal_razao_social_correta" id="df_legal_razao_social_correta" value="sim">
            <input type="hidden" name="df_legal_endereco_correto" id="df_legal_endereco_correto" value="sim">
        </div>

        <div class="col-md-3 mb-3">
            <label for="alvara" class="form-label">Alvará</label>
            <select class="form-select controle-interno-obrigatorio" name="alvara" id="alvara" required>
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
                <option value="goias">Goiás</option>
            </select>
            <div class="invalid-feedback">Informe a situação do alvará.</div>
        </div>

        <div class="col-md-3 mb-3">
            <label for="contador" class="form-label">Contador</label>
            <select class="form-select" name="contador" id="contador">
                <option value="">Selecione</option>
                <option value="sim">Sim</option>
                <option value="nao">Não</option>
            </select>
        </div>

        <div class="col-md-3 mb-3">
            <label for="cadastro_crf" class="form-label">Cadastro CRF</label>
            <select class="form-select" name="cadastro_crf" id="cadastro_crf">
                <option value="">Selecione</option>
                <option value="cadastrado">Cadastrado</option>
                <option value="nao_cadastrado">Não cadastrado</option>
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
            </select>
        </div>

        <div class="col-md-3 mb-3">
            <label for="procuracao_fgts" class="form-label">Procuração FGTS</label>
            <select class="form-select controle-com-vencimento procuracao-obrigatoria" name="procuracao_fgts" id="procuracao_fgts" data-vencimento="vencimento_procuracao_fgts" required>
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
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
                <option value="goias">Goiás</option>
            </select>
        </div>

        <div class="col-md-3 mb-3">
            <label for="contrato_prestacao_servicos" class="form-label">Contrato de Prestação de Serviços</label>
            <select class="form-select" name="contrato_prestacao_servicos" id="contrato_prestacao_servicos">
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
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

<div class="border rounded p-3 mb-3">
    <h6 class="mb-3 fw-bold">Parcelamentos</h6>
    <div class="row">
        <div class="col-md-4 mb-1">
            <label for="possui_parcelamento" class="form-label">O cliente possui parcelamento?</label>
            <select
                class="form-select controle-interno-obrigatorio"
                name="possui_parcelamento"
                id="possui_parcelamento"
                required>
                <option value="">Selecione</option>
                <option value="possui">Possui</option>
                <option value="nao_possui">Não possui</option>
            </select>
            <div class="invalid-feedback">Informe se o cliente possui parcelamento.</div>
        </div>
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
                    <small class="text-muted">Todos os órgãos devem ser preenchidos: informe o vencimento ou marque como dispensado.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger d-none" id="alertaAlvarasObrigatorios">
                    Preencha todos os órgãos. Escolha “Com vencimento” e informe a data, ou marque como “Dispensado”.
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

<!-- ENDEREÇO -->
<div class="border rounded p-3 mb-3 secao-cliente-completo">
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
</div>