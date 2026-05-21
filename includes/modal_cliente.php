    <!-- Modal -->
    <div class="modal fade" id="clienteModal" tabindex="-1" aria-labelledby="clienteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <form id="clienteForm">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title" id="clienteModalLabel">Novo Cliente</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="id">

                        <!-- DADOS PRINCIPAIS -->
                        <div class="border rounded p-3 mb-3">
                            <h6 class="mb-3 fw-bold">Dados principais</h6>

                            <div class="row">
                                <div class="col-md-2 mb-3">
                                    <label for="codigo" class="form-label">Código</label>
                                    <input type="text" class="form-control" name="codigo" id="codigo">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="documento" class="form-label">CPF / CNPJ</label>
                                    <input type="text" class="form-control" name="documento" id="documento">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="nome" class="form-label">Razão Social</label>
                                    <input type="text" class="form-control" name="nome" id="nome">
                                </div>

                                <div class="col-md-7 mb-3">
                                    <label for="nome_fantasia" class="form-label">Nome Fantasia</label>
                                    <input type="text" class="form-control" name="nome_fantasia" id="nome_fantasia">
                                </div>

                                <div class="col-md-5 mb-3">
                                    <label for="email" class="form-label">E-mail</label>
                                    <input type="email" class="form-control" name="email" id="email">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label for="telefone" class="form-label">Telefone</label>
                                    <input type="text" class="form-control" name="telefone" id="telefone">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label for="inscricao_estadual" class="form-label">Inscrição Estadual</label>
                                    <input type="text" class="form-control" name="inscricao_estadual"
                                        id="inscricao_estadual">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label for="nire" class="form-label">NIRE</label>
                                    <input type="text" class="form-control" name="nire" id="nire">
                                </div>
                                <div class="col-md-3 mb-3">
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

                        <!-- ENDEREÇO -->
                        <div class="border rounded p-3 mb-3">
                            <h6 class="mb-3 fw-bold">Endereço</h6>

                            <div class="col-md-2 mb-3">
                                <label for="cep" class="form-label">CEP</label>
                                <input type="text" class="form-control" name="cep" id="cep">
                                <small id="cepFeedback" class="text-muted"></small>
                            </div>

                            <div class="row">
                                <div class="col-md-7 mb-3">
                                    <label for="endereco" class="form-label">Endereço</label>
                                    <input type="text" class="form-control" name="endereco" id="endereco">
                                </div>

                                <div class="col-md-2 mb-3">
                                    <label for="numero_endereco" class="form-label">Número</label>
                                    <input type="text" class="form-control" name="numero_endereco" id="numero_endereco">
                                </div>

                                <div class="col-md-5 mb-3">
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

                                <div class="col-md-2 mb-3">
                                    <label for="uf" class="form-label">UF</label>
                                    <input type="text" class="form-control text-uppercase" name="uf" id="uf"
                                        maxlength="2">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success" id="btnSalvarCliente">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>