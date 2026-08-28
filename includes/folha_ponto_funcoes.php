<?php

function folhaPontoMesValido(?string $mes): string
{
    if (is_string($mes) && preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $data = DateTime::createFromFormat('!Y-m', $mes);

        if ($data && $data->format('Y-m') === $mes) {
            return $mes;
        }
    }

    return date('Y-m');
}

function folhaPontoToken(): string
{
    if (empty($_SESSION['folha_ponto_csrf'])) {
        $_SESSION['folha_ponto_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['folha_ponto_csrf'];
}

function folhaPontoTokenValido(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['folha_ponto_csrf'])
        && hash_equals($_SESSION['folha_ponto_csrf'], $token);
}

function folhaPontoHoraValida(?string $hora): bool
{
    if ($hora === null || $hora === '') {
        return true;
    }

    return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora);
}

function folhaPontoMinutosHora(?string $hora): ?int
{
    if (!folhaPontoHoraValida($hora) || empty($hora)) {
        return null;
    }

    [$horas, $minutos] = array_map('intval', explode(':', $hora));
    return ($horas * 60) + $minutos;
}

function folhaPontoMinutosIntervalo(?string $entrada, ?string $saida): int
{
    $inicio = folhaPontoMinutosHora($entrada);
    $fim = folhaPontoMinutosHora($saida);

    if ($inicio === null || $fim === null || $fim <= $inicio) {
        return 0;
    }

    return $fim - $inicio;
}

function folhaPontoMinutosMarcacoes(array $dados): int
{
    return folhaPontoMinutosIntervalo($dados['entrada_1'] ?? null, $dados['saida_1'] ?? null)
        + folhaPontoMinutosIntervalo($dados['entrada_2'] ?? null, $dados['saida_2'] ?? null);
}

function folhaPontoFormatarMinutos(int $minutos, bool $comSinal = false): string
{
    $sinal = '';

    if ($minutos < 0) {
        $sinal = '-';
    } elseif ($comSinal && $minutos > 0) {
        $sinal = '+';
    }

    $absoluto = abs($minutos);
    return $sinal . intdiv($absoluto, 60) . 'h' . str_pad((string)($absoluto % 60), 2, '0', STR_PAD_LEFT);
}

function folhaPontoNomesDias(): array
{
    return [
        1 => 'Segunda-feira',
        2 => 'Terça-feira',
        3 => 'Quarta-feira',
        4 => 'Quinta-feira',
        5 => 'Sexta-feira',
        6 => 'Sábado',
        7 => 'Domingo',
    ];
}

function folhaPontoHorarioPadrao(): array
{
    $horarios = [];

    for ($dia = 1; $dia <= 7; $dia++) {
        $trabalha = $dia <= 5;
        $horarios[$dia] = [
            'dia_semana' => $dia,
            'trabalha' => $trabalha ? 1 : 0,
            'entrada_1' => $trabalha ? '08:00' : null,
            'saida_1' => $trabalha ? '12:00' : null,
            'entrada_2' => $trabalha ? '13:00' : null,
            'saida_2' => $dia <= 4 ? '18:00' : ($dia === 5 ? '17:00' : null),
        ];
    }

    return $horarios;
}

function folhaPontoNormalizarHorario(array $dados, int $dia): array
{
    $trabalha = !empty($dados['trabalha']);
    $horario = [
        'dia_semana' => $dia,
        'trabalha' => $trabalha ? 1 : 0,
        'entrada_1' => $trabalha ? trim((string)($dados['entrada_1'] ?? '')) : '',
        'saida_1' => $trabalha ? trim((string)($dados['saida_1'] ?? '')) : '',
        'entrada_2' => $trabalha ? trim((string)($dados['entrada_2'] ?? '')) : '',
        'saida_2' => $trabalha ? trim((string)($dados['saida_2'] ?? '')) : '',
    ];

    foreach (['entrada_1', 'saida_1', 'entrada_2', 'saida_2'] as $campo) {
        if (!folhaPontoHoraValida($horario[$campo])) {
            throw new RuntimeException('Existe um horário inválido na jornada semanal.');
        }
    }

    if (!$trabalha) {
        return $horario;
    }

    if ($horario['entrada_1'] === '' || $horario['saida_1'] === '') {
        throw new RuntimeException('Informe pelo menos a primeira entrada e a primeira saída nos dias trabalhados.');
    }

    if (($horario['entrada_2'] === '') !== ($horario['saida_2'] === '')) {
        throw new RuntimeException('Preencha o retorno e a saída final juntos.');
    }

    if (folhaPontoMinutosIntervalo($horario['entrada_1'], $horario['saida_1']) <= 0) {
        throw new RuntimeException('A primeira saída precisa ser posterior à primeira entrada.');
    }

    if (
        $horario['entrada_2'] !== ''
        && folhaPontoMinutosIntervalo($horario['entrada_2'], $horario['saida_2']) <= 0
    ) {
        throw new RuntimeException('A saída final precisa ser posterior ao retorno.');
    }

    return $horario;
}

function folhaPontoRedirecionar(string $url, string $mensagem, string $tipo = 'success'): void
{
    $_SESSION['folha_ponto_mensagem'] = [
        'texto' => $mensagem,
        'tipo' => $tipo,
    ];
    header('Location: ' . $url);
    exit;
}

function folhaPontoObterMensagem(): ?array
{
    $mensagem = $_SESSION['folha_ponto_mensagem'] ?? null;
    unset($_SESSION['folha_ponto_mensagem']);
    return is_array($mensagem) ? $mensagem : null;
}
