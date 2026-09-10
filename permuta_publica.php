<?php
define('LOGI_PUBLIC_ACCESS', true);
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/permutas_funcoes.php';

header("X-Robots-Tag: noindex, nofollow, noarchive");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");

$token = strtolower(trim((string)($_GET['token'] ?? '')));
$compartilhamento = logiTabelaExiste($authPdo, 'permutas_compartilhamentos')
    ? permutasBuscarCompartilhamentoPublico($authPdo, $token, true)
    : null;

if (!$compartilhamento) {
    http_response_code(404);
?>
    <!DOCTYPE html>
    <html lang="pt-br">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex,nofollow,noarchive">
        <title>Link indisponível - FECON LOGISTICA</title>
        <link rel="stylesheet" href="assets/permuta_publica.css">
    </head>

    <body>
        <main class="publico-erro">
            <section>
                <h1>Este link não está disponível</h1>
                <p>Ele pode ter expirado, sido revogado ou estar incompleto. Solicite um novo link à FECON LOGISTICA.</p>
            </section>
        </main>
    </body>

    </html>
<?php
    exit;
}

$conteudo = $compartilhamento['conteudo'];
$competenciaRegistro = $conteudo['competencia'];
$itens = $conteudo['itens'];
$anexos = is_array($conteudo['anexos'] ?? null) ? $conteudo['anexos'] : [];
$empresaNome = trim((string)($conteudo['empresa_nome'] ?? '')) ?: 'FECON LOGISTICA';
$competenciaMes = date('Y-m', strtotime((string)$competenciaRegistro['competencia']));
$rotuloCompetencia = permutasCompetenciaRotulo($competenciaMes);
$total = (float)($conteudo['total'] ?? permutasTotal($itens));
$geradoEm = (string)($conteudo['gerado_em'] ?? $compartilhamento['criado_em']);
$urlToken = rawurlencode($token);
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Permuta <?= htmlspecialchars($rotuloCompetencia) ?> - FECON LOGISTICA</title>
    <link rel="shortcut icon" href="assets/images/logo.svg">
    <link rel="stylesheet" href="assets/permuta_publica.css">
</head>

<body>
    <header class="publico-topo">
        <div class="publico-topo-conteudo">
            <div class="publico-marca"><img src="assets/images/logo.svg" alt=""><span>FECON LOGISTICA</span></div>
            <span class="publico-somente-leitura"><span aria-hidden="true">&#10003;</span> Somente leitura</span>
        </div>
    </header>

    <main class="publico-conteudo">
        <div class="publico-cabecalho">
            <div>
                <h1>Relatório de permuta</h1>
                <p>DF Cartuchos · <?= htmlspecialchars($rotuloCompetencia) ?></p>
            </div>
            <div class="publico-acoes">
                <a class="publico-botao" href="permuta_publica_pdf.php?token=<?= $urlToken ?>" target="_blank" rel="noopener">Abrir PDF</a>
            </div>
        </div>

        <section class="publico-resumo" aria-label="Resumo da permuta">
            <div><span>Empresa</span><strong><?= htmlspecialchars($empresaNome) ?></strong></div>
            <div><span>Competência</span><strong><?= htmlspecialchars($rotuloCompetencia) ?></strong></div>
            <div><span>Itens</span><strong><?= count($itens) ?></strong></div>
            <div><span>Total</span><strong class="total"><?= htmlspecialchars(permutasMoeda($total)) ?></strong></div>
        </section>

        <section class="publico-secao">
            <div class="publico-secao-titulo">
                <h2>Itens da competência</h2>
                <p>Relação compartilhada pela FECON LOGISTICA.</p>
            </div>
            <div class="publico-tabela-wrapper">
                <table class="publico-tabela">
                    <thead>
                        <tr>
                            <th class="numero">Qtd.</th>
                            <th>Descrição</th>
                            <th>Destino</th>
                            <th class="moeda">Valor unitário</th>
                            <th class="moeda">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                            <?php $valorItem = (float)$item['quantidade'] * (float)$item['valor_unitario']; ?>
                            <tr>
                                <td class="numero"><?= htmlspecialchars(permutasQuantidadeRotulo((float)$item['quantidade'])) ?></td>
                                <td><strong><?= htmlspecialchars((string)$item['descricao']) ?></strong><?php if (!empty($item['observacao'])): ?><small><?= htmlspecialchars((string)$item['observacao']) ?></small><?php endif; ?></td>
                                <td><?= htmlspecialchars(trim((string)($item['destino'] ?? '')) ?: '-') ?></td>
                                <td class="moeda"><?= htmlspecialchars(permutasMoeda((float)$item['valor_unitario'])) ?></td>
                                <td class="moeda"><strong><?= htmlspecialchars(permutasMoeda($valorItem)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($anexos !== []): ?>
            <section class="publico-secao">
                <div class="publico-secao-titulo">
                    <h2>Relatórios digitalizados</h2>
                    <p>Arquivos anexados a esta competência.</p>
                </div>
                <div class="publico-anexos">
                    <?php foreach ($anexos as $anexo): ?>
                        <a class="publico-anexo" href="permuta_publica_anexo.php?token=<?= $urlToken ?>&amp;id=<?= (int)$anexo['id'] ?>" target="_blank" rel="noopener">
                            <span class="publico-anexo-icone" aria-hidden="true"><?= ($anexo['tipo_mime'] ?? '') === 'application/pdf' ? 'PDF' : 'IMG' ?></span>
                            <span><strong><?= htmlspecialchars((string)$anexo['nome_original']) ?></strong><small><?= htmlspecialchars(number_format((int)$anexo['tamanho_bytes'] / 1024, 0, ',', '.')) ?> KB · Abrir arquivo</small></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <p class="publico-rodape">Compartilhado em <?= htmlspecialchars(permutasDataBr($geradoEm, true)) ?> · Link válido até <?= htmlspecialchars(permutasDataBr($compartilhamento['expira_em'], true)) ?></p>
    </main>
</body>

</html>