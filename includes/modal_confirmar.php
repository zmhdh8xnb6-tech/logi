<div class="modal fade" id="modalConfirmarExclusao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Devolver cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <p class="mb-1">Tem certeza que deseja enviar este cliente para devolvidos?</p>
                <small class="text-muted">
                    Ele sairá das listas principais e das pendências, mas poderá ser reativado depois.
                </small>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Não
                </button>

                <button type="button" class="btn btn-warning" id="btnConfirmarExclusao">
                    <i class="bi bi-archive"></i> Sim, devolver
                </button>
            </div>
        </div>
    </div>
</div>