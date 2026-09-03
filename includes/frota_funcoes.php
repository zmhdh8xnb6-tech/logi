<?php

function frotaToken(): string
{
    if (empty($_SESSION['frota_csrf_token'])) {
        $_SESSION['frota_csrf_token'] = bin2hex(random_bytes(24));
    }

    return (string)$_SESSION['frota_csrf_token'];
}

function frotaTokenValido(mixed $token): bool
{
    return is_string($token)
        && $token !== ''
        && hash_equals(frotaToken(), $token);
}

function frotaRedirecionar(string $mensagem, string $tipo = 'success', string $aba = 'visao-geral', array $parametros = []): void
{
    $abas = ['visao-geral', 'obrigacoes', 'multas'];
    $aba = in_array($aba, $abas, true) ? $aba : 'visao-geral';

    header('Location: frota.php?' . http_build_query(array_merge([
        'aba' => $aba,
        'msg' => $mensagem,
        'tipo' => $tipo,
    ], $parametros)));
    exit;
}

function frotaTexto(string $valor, int $limite): string
{
    $valor = trim(preg_replace('/\s+/', ' ', $valor) ?? '');
    return function_exists('mb_substr')
        ? mb_substr($valor, 0, $limite)
        : substr($valor, 0, $limite);
}

function frotaPlaca(string $placa): string
{
    return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $placa));
}

function frotaPlacaValida(string $placa): bool
{
    return preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placa) === 1;
}

function frotaPlacaFormatada(string $placa): string
{
    $placa = frotaPlaca($placa);
    return strlen($placa) === 7 ? substr($placa, 0, 3) . '-' . substr($placa, 3) : $placa;
}

function frotaRenavam(string $renavam): string
{
    return (string)preg_replace('/\D/', '', $renavam);
}

function frotaDataValida(string $data, bool $obrigatoria = true): bool
{
    if ($data === '') {
        return !$obrigatoria;
    }

    $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $objeto !== false && $objeto->format('Y-m-d') === $data;
}

function frotaValorEntrada(string $valor): float
{
    $valor = trim($valor);
    if ($valor === '') {
        return 0.0;
    }

    $valor = str_replace(['R$', ' '], '', $valor);
    if (str_contains($valor, ',') && str_contains($valor, '.')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (str_contains($valor, ',')) {
        $valor = str_replace(',', '.', $valor);
    }

    return is_numeric($valor) ? round(max(0, (float)$valor), 2) : -1.0;
}

function frotaMoeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function frotaData(?string $data): string
{
    return $data ? date('d/m/Y', strtotime($data)) : '-';
}

function frotaSituacaoPrazo(array $registro): string
{
    if (($registro['situacao'] ?? '') !== 'pendente') {
        return (string)($registro['situacao'] ?? 'pendente');
    }

    $vencimento = (string)($registro['vencimento'] ?? '');
    if ($vencimento === '') {
        return 'pendente';
    }

    $hoje = date('Y-m-d');
    if ($vencimento < $hoje) {
        return 'vencido';
    }

    if ($vencimento <= date('Y-m-d', strtotime('+30 days'))) {
        return 'proximo';
    }

    return 'pendente';
}

function frotaTipoObrigacao(string $tipo): string
{
    $tipos = [
        'ipva' => 'IPVA',
        'licenciamento' => 'Licenciamento / CRLV',
        'seguro' => 'Seguro',
        'revisao' => 'Revisão',
        'troca_oleo' => 'Troca de óleo',
        'pneus' => 'Pneus',
        'outro' => 'Outro',
    ];

    return $tipos[$tipo] ?? 'Outro';
}

function frotaSituacaoVeiculo(string $situacao): string
{
    $situacoes = [
        'ativo' => 'Ativo',
        'manutencao' => 'Em manutenção',
        'inativo' => 'Inativo',
        'vendido' => 'Vendido',
    ];

    return $situacoes[$situacao] ?? 'Ativo';
}
