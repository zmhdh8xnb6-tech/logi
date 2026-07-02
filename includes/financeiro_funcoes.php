<?php

function financeiroTabelasDisponiveis(PDO $pdo, array $tabelas): bool
{
    if ($tabelas === []) {
        return true;
    }

    $marcadores = implode(',', array_fill(0, count($tabelas), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name IN ({$marcadores})
    ");
    $stmt->execute($tabelas);

    return (int)$stmt->fetchColumn() === count($tabelas);
}

function financeiroValorEntrada(string $valor): float
{
    $valor = trim(str_replace(['R$', ' '], '', $valor));

    if ($valor === '') {
        return 0.0;
    }

    if (str_contains($valor, ',')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (substr_count($valor, '.') > 1) {
        $valor = str_replace('.', '', $valor);
    } elseif (str_contains($valor, '.')) {
        $casasDepoisDoPonto = strlen($valor) - strrpos($valor, '.') - 1;

        if ($casasDepoisDoPonto === 3) {
            $valor = str_replace('.', '', $valor);
        }
    }

    return is_numeric($valor) ? round((float)$valor, 2) : 0.0;
}

function financeiroValorValido(string $valor): bool
{
    $valor = trim(str_replace(['R$', ' '], '', $valor));

    if ($valor === '') {
        return false;
    }

    return (bool)preg_match(
        '/^(?:(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{1,2})?|\d+(?:\.\d{1,2})?)$/',
        $valor
    );
}

function financeiroMoeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function financeiroData(?string $data): string
{
    if (empty($data)) {
        return '-';
    }

    return date('d/m/Y', strtotime($data));
}

function financeiroMesValido(?string $mes): string
{
    if (is_string($mes) && preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $data = DateTime::createFromFormat('!Y-m', $mes);

        if ($data && $data->format('Y-m') === $mes) {
            return $mes;
        }
    }

    return date('Y-m');
}

function financeiroSomarMeses(string $data, int $meses): string
{
    $origem = new DateTime($data);
    $dia = (int)$origem->format('d');
    $destino = new DateTime($origem->format('Y-m-01'));
    $destino->modify(($meses >= 0 ? '+' : '') . $meses . ' months');
    $ultimoDia = (int)$destino->format('t');
    $destino->setDate(
        (int)$destino->format('Y'),
        (int)$destino->format('m'),
        min($dia, $ultimoDia)
    );

    return $destino->format('Y-m-d');
}

function financeiroToken(): string
{
    if (empty($_SESSION['financeiro_csrf'])) {
        $_SESSION['financeiro_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['financeiro_csrf'];
}

function financeiroTokenValido(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['financeiro_csrf'])
        && hash_equals($_SESSION['financeiro_csrf'], $token);
}

function financeiroDefinirMensagem(string $texto, string $tipo = 'success'): void
{
    $_SESSION['financeiro_mensagem'] = [
        'texto' => $texto,
        'tipo' => $tipo,
    ];
}

function financeiroObterMensagem(): ?array
{
    $mensagem = $_SESSION['financeiro_mensagem'] ?? null;
    unset($_SESSION['financeiro_mensagem']);

    return is_array($mensagem) ? $mensagem : null;
}

function financeiroRedirecionar(string $url, string $mensagem, string $tipo = 'success'): void
{
    financeiroDefinirMensagem($mensagem, $tipo);
    header('Location: ' . $url);
    exit;
}
