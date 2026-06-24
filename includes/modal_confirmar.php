<div class="modal fade" id="modalConfirmarExclusao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Excluir cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <p class="mb-1">Tem certeza que deseja excluir este cliente?</p>
                <small class="text-danger">
                    Essa ação apaga o cadastro definitivamente e não poderá ser desfeita.
                </small>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Não
                </button>

                <button type="button" class="btn btn-danger" id="btnConfirmarExclusao">
                    <i class="bi bi-trash"></i> Sim, excluir
                </button>
            </div>
        </div>
    </div>
</div>