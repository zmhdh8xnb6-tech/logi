<?php

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';

function legalizacaoPdfEscapar($valor): string
{
    return htmlspecialchars(trim((string)$valor), ENT_QUOTES, 'UTF-8');
}

function legalizacaoPdfValor($valor): string
{
    $valor = trim((string)$valor);
    return $valor !== '' ? legalizacaoPdfEscapar($valor) : '-';
}

function legalizacaoPdfEnderecoCliente(array $cliente): string
{
    $logradouro = trim(implode(', ', array_filter([
        trim((string)($cliente['endereco'] ?? '')),
        trim((string)($cliente['numero_endereco'] ?? '')),
    ])));
    $cidadeUf = trim(implode(' / ', array_filter([
        trim((string)($cliente['cidade'] ?? '')),
        trim((string)($cliente['uf'] ?? '')),
    ])));

    return implode(' - ', array_filter([
        $logradouro,
        trim((string)($cliente['complemento'] ?? '')),
        trim((string)($cliente['bairro'] ?? '')),
        $cidadeUf,
        trim((string)($cliente['cep'] ?? '')),
    ]));
}

function legalizacaoPdfHtml(
    array $processo,
    array $cliente,
    array $etapas,
    array $checklist,
    array $historico,
    string $empresaNome,
    string $usuarioNome
): string {
    $clienteCodigo = $processo['cliente_codigo_atual'] ?? $processo['cliente_codigo'] ?? $cliente['codigo'] ?? '';
    $clienteNome = $processo['cliente_nome_atual'] ?? $processo['cliente_nome'] ?? $cliente['nome'] ?? '';
    $clienteDocumento = $processo['cliente_documento_atual'] ?? $processo['cliente_documento'] ?? $cliente['documento'] ?? '';
    $clienteTitulo = trim(($clienteCodigo !== '' ? $clienteCodigo . ' - ' : '') . $clienteNome);
    $localidade = trim(implode(' / ', array_filter([
        trim((string)($cliente['cidade'] ?? '')),
        trim((string)($cliente['uf'] ?? $processo['cliente_uf_atual'] ?? '')),
    ])));
    $empresaNome = trim($empresaNome) !== '' ? $empresaNome : 'Logi';

    $linhasEtapas = '';
    foreach ($etapas as $etapa) {
        $status = (string)($etapa['status'] ?? 'pendente');
        $statusTexto = ['concluida' => 'Concluída', 'atual' => 'Em andamento', 'pendente' => 'Pendente'][$status] ?? ucfirst($status);
        $classe = $status === 'concluida' ? 'ok' : ($status === 'atual' ? 'atual' : 'pendente');
        $linhasEtapas .= '<tr><td class="numero">' . (int)($etapa['ordem'] ?? 0) . '</td><td>'
            . legalizacaoPdfValor(legalizacaoNormalizarNomeEtapa((string)($etapa['nome'] ?? ''))) . '</td><td class="status ' . $classe . '">'
            . legalizacaoPdfEscapar($statusTexto) . '</td></tr>';
    }

    $linhasChecklist = '';
    foreach ($checklist as $item) {
        $status = (string)($item['status'] ?? 'pendente');
        $recebido = $status === 'recebido';
        $linhasChecklist .= '<tr><td class="numero">' . ($recebido ? '&#10003;' : '&#9633;') . '</td><td>'
            . legalizacaoPdfValor($item['item'] ?? '') . '</td><td class="status ' . ($recebido ? 'ok' : 'pendente') . '">'
            . legalizacaoPdfEscapar(legalizacaoStatusChecklist($status)) . '</td></tr>';
    }

    $linhasHistorico = '';
    foreach ($historico as $evento) {
        $linhasHistorico .= '<tr><td class="data">' . legalizacaoPdfEscapar(legalizacaoFormatarDataHora($evento['criado_em'] ?? null))
            . '</td><td class="usuario">' . legalizacaoPdfValor($evento['usuario_nome'] ?? '') . '</td><td>'
            . legalizacaoPdfValor($evento['descricao'] ?? '') . '</td></tr>';
    }

    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><style>
        @page { margin: 18mm 13mm 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 9.4px; line-height: 1.35; }
        .cabecalho { border-bottom: 3px solid #1368f5; padding-bottom: 10px; margin-bottom: 14px; }
        .marca { color: #1368f5; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        h1 { margin: 3px 0 1px; font-size: 20px; }
        .subtitulo { margin: 0; color: #64748b; font-size: 9px; }
        .protocolo { float: right; margin-top: -34px; text-align: right; color: #475569; }
        .protocolo strong { display: block; color: #172033; font-size: 13px; }
        .secao { margin-top: 13px; page-break-inside: avoid; }
        .quebravel { page-break-inside: auto; }
        .documentacao { page-break-before: always; }
        h2 { margin: 0 0 6px; padding-bottom: 4px; border-bottom: 1px solid #cbd5e1; color: #1e3a5f; font-size: 11px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        .dados td { width: 50%; padding: 5px 7px; border: 1px solid #dbe3ed; vertical-align: top; }
        .rotulo { display: block; margin-bottom: 2px; color: #64748b; font-size: 7.5px; font-weight: bold; text-transform: uppercase; }
        .lista th { padding: 5px 6px; background: #eef3f8; border: 1px solid #dbe3ed; color: #475569; font-size: 7.5px; text-align: left; text-transform: uppercase; }
        .lista td { padding: 5px 6px; border: 1px solid #dbe3ed; vertical-align: top; }
        .lista tr { page-break-inside: avoid; }
        .numero { width: 28px; text-align: center; }
        .status { width: 82px; font-size: 8px; font-weight: bold; }
        .ok { color: #15803d; } .atual { color: #1368f5; } .pendente { color: #b45309; }
        .data { width: 92px; white-space: nowrap; } .usuario { width: 110px; }
        .observacoes { min-height: 42px; padding: 8px; border: 1px solid #dbe3ed; background: #f8fafc; white-space: pre-wrap; }
        .assinaturas { margin-top: 30px; page-break-inside: avoid; }
        .assinaturas td { width: 46%; padding-top: 24px; border-top: 1px solid #94a3b8; text-align: center; }
        .assinaturas .espaco { width: 8%; border: 0; }
        .rodape { position: fixed; right: 0; bottom: -10mm; left: 0; color: #94a3b8; font-size: 7.5px; text-align: center; }
    </style></head><body>
        <div class="cabecalho"><div class="marca">' . legalizacaoPdfEscapar($empresaNome) . ' | Sistema Logi</div>
            <h1>Ficha de acompanhamento - Legalização</h1><p class="subtitulo">Controle impresso do processo e da documentação exigida</p>
            <div class="protocolo"><span>PROCESSO</span><strong>#' . (int)($processo['id'] ?? 0) . '</strong></div></div>

        <section class="secao"><h2>Dados do cliente</h2><table class="dados">
            <tr><td><span class="rotulo">Cliente</span>' . legalizacaoPdfValor($clienteTitulo) . '</td><td><span class="rotulo">CPF/CNPJ</span>' . legalizacaoPdfValor($clienteDocumento) . '</td></tr>
            <tr><td><span class="rotulo">Nome fantasia</span>' . legalizacaoPdfValor($cliente['nome_fantasia'] ?? '') . '</td><td><span class="rotulo">Localidade</span>' . legalizacaoPdfValor($localidade) . '</td></tr>
            <tr><td><span class="rotulo">Telefone</span>' . legalizacaoPdfValor($cliente['telefone'] ?? '') . '</td><td><span class="rotulo">E-mail</span>' . legalizacaoPdfValor($cliente['email'] ?? '') . '</td></tr>
            <tr><td colspan="2"><span class="rotulo">Endereço</span>' . legalizacaoPdfValor(legalizacaoPdfEnderecoCliente($cliente)) . '</td></tr>
        </table></section>

        <section class="secao"><h2>Dados do processo</h2><table class="dados">
            <tr><td><span class="rotulo">Tipo</span>' . legalizacaoPdfEscapar(legalizacaoTextoTipo((string)($processo['tipo'] ?? 'outros'))) . '</td><td><span class="rotulo">Status</span>' . legalizacaoPdfEscapar(legalizacaoTextoStatus((string)($processo['status'] ?? 'em_andamento'))) . '</td></tr>
            <tr><td><span class="rotulo">Etapa atual</span>' . legalizacaoPdfValor($processo['etapa_atual_nome'] ?? '') . '</td><td><span class="rotulo">Responsável</span>' . legalizacaoPdfValor($processo['responsavel_nome'] ?? '') . '</td></tr>
            <tr><td><span class="rotulo">Solicitado em</span>' . legalizacaoPdfEscapar(legalizacaoFormatarData($processo['solicitado_em'] ?? null)) . '</td><td><span class="rotulo">Prazo</span>' . legalizacaoPdfEscapar(legalizacaoFormatarData($processo['prazo'] ?? null)) . '</td></tr>
            <tr><td colspan="2"><span class="rotulo">Contato do cliente</span>' . legalizacaoPdfValor($processo['contato_cliente'] ?? '') . '</td></tr>
        </table></section>

        <section class="secao quebravel"><h2>Etapas do processo</h2><table class="lista"><thead><tr><th class="numero">Nº</th><th>Etapa</th><th class="status">Situação</th></tr></thead><tbody>'
        . ($linhasEtapas !== '' ? $linhasEtapas : '<tr><td colspan="3">Nenhuma etapa cadastrada.</td></tr>') . '</tbody></table></section>

        <section class="secao quebravel documentacao"><h2>Documentação exigida</h2><table class="lista"><thead><tr><th class="numero"></th><th>Documento</th><th class="status">Situação</th></tr></thead><tbody>'
        . ($linhasChecklist !== '' ? $linhasChecklist : '<tr><td colspan="3">Nenhum documento cadastrado.</td></tr>') . '</tbody></table></section>

        <section class="secao"><h2>Observações do processo</h2><div class="observacoes">' . legalizacaoPdfValor($processo['observacoes'] ?? '') . '</div></section>'
        . ($linhasHistorico !== '' ? '<section class="secao quebravel"><h2>Histórico recente</h2><table class="lista"><thead><tr><th class="data">Data</th><th class="usuario">Usuário</th><th>Registro</th></tr></thead><tbody>' . $linhasHistorico . '</tbody></table></section>' : '') . '
        <table class="assinaturas"><tr><td>Responsável pelo processo</td><td class="espaco"></td><td>Conferência</td></tr></table>
        <div class="rodape">Gerado em ' . date('d/m/Y H:i') . ' por ' . legalizacaoPdfValor($usuarioNome ?: 'Usuário') . '</div>
    </body></html>';
}

function legalizacaoGerarPdf(
    array $processo,
    array $cliente,
    array $etapas,
    array $checklist,
    array $historico,
    string $empresaNome,
    string $usuarioNome
): string {
    $opcoes = new Options();
    $opcoes->set('defaultFont', 'DejaVu Sans');
    $opcoes->set('isRemoteEnabled', false);
    $opcoes->set('isPhpEnabled', false);

    $pdf = new Dompdf($opcoes);
    $pdf->loadHtml(legalizacaoPdfHtml($processo, $cliente, $etapas, $checklist, $historico, $empresaNome, $usuarioNome), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    return $pdf->output();
}

function legalizacaoNomeArquivoPdf(array $processo): string
{
    $cliente = trim((string)($processo['cliente_nome_atual'] ?? $processo['cliente_nome'] ?? 'cliente'));
    $cliente = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cliente) ?: 'cliente';
    $cliente = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $cliente), '-'));
    return 'ficha-legalizacao-' . (int)($processo['id'] ?? 0) . '-' . ($cliente !== '' ? $cliente : 'cliente') . '.pdf';
}
