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
        h2 { margin: 0 0 6px; padding-bottom: 4px; border-bottom: 1px solid #cbd5e1; color: #1e3a5f; font-size: 11px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        .dados td { width: 50%; padding: 5px 7px; border: 1px solid #dbe3ed; vertical-align: top; }
        .rotulo { display: block; margin-bottom: 2px; color: #64748b; font-size: 7.5px; font-weight: bold; text-transform: uppercase; }
        .alteracao { min-height: 120px; padding: 12px; border: 1px solid #b8cff5; border-left: 4px solid #1368f5; background: #f5f8ff; font-size: 11px; line-height: 1.55; white-space: pre-wrap; }
        .rodape { position: fixed; right: 0; bottom: -10mm; left: 0; color: #94a3b8; font-size: 7.5px; text-align: center; }
    </style></head><body>
        <div class="cabecalho"><div class="marca">' . legalizacaoPdfEscapar($empresaNome) . ' | Sistema Logi</div>
            <h1>Ficha de acompanhamento - Legalização</h1><p class="subtitulo">Resumo do cliente e do processo</p>
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

        <section class="secao"><h2>O que está sendo alterado</h2><div class="alteracao">' . legalizacaoPdfValor($processo['observacoes'] ?? '') . '</div></section>
        <div class="rodape">Gerado em ' . date('d/m/Y H:i') . ' por ' . legalizacaoPdfValor($usuarioNome ?: 'Usuário') . '</div>
    </body></html>';
}

function legalizacaoGerarPdf(
    array $processo,
    array $cliente,
    string $empresaNome,
    string $usuarioNome
): string {
    $opcoes = new Options();
    $opcoes->set('defaultFont', 'DejaVu Sans');
    $opcoes->set('isRemoteEnabled', false);
    $opcoes->set('isPhpEnabled', false);

    $pdf = new Dompdf($opcoes);
    $pdf->loadHtml(legalizacaoPdfHtml($processo, $cliente, $empresaNome, $usuarioNome), 'UTF-8');
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
