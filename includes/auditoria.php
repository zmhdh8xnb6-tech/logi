<?php

function auditoriaTabelaDisponivel(PDO $pdo): bool
{
    static $disponivel = null;

    if ($disponivel !== null) {
        return $disponivel;
    }

    try {
        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'auditoria_logs'
        ");
        $disponivel = (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        $disponivel = false;
    }

    return $disponivel;
}

function auditoriaLimparDados(?array $dados): ?array
{
    if ($dados === null) {
        return null;
    }

    $camposSensiveis = [
        'senha',
        'password',
        'senha_hash',
        'token',
        'token_verificacao',
        'csrf_token',
    ];

    foreach ($dados as $chave => $valor) {
        if (in_array(strtolower((string)$chave), $camposSensiveis, true)) {
            $dados[$chave] = '[PROTEGIDO]';
            continue;
        }

        if (is_array($valor)) {
            $dados[$chave] = auditoriaLimparDados($valor);
        }
    }

    return $dados;
}

function auditoriaMudancas(?array $antes, ?array $depois): array
{
    $antes = $antes ?? [];
    $depois = $depois ?? [];
    $campos = array_unique(array_merge(array_keys($antes), array_keys($depois)));
    $mudancasAntes = [];
    $mudancasDepois = [];

    foreach ($campos as $campo) {
        $valorAntes = $antes[$campo] ?? null;
        $valorDepois = $depois[$campo] ?? null;

        if ($valorAntes == $valorDepois) {
            continue;
        }

        $mudancasAntes[$campo] = $valorAntes;
        $mudancasDepois[$campo] = $valorDepois;
    }

    return [
        'antes' => $mudancasAntes,
        'depois' => $mudancasDepois,
    ];
}

function auditoriaCortar(string $texto, int $limite): string
{
    return function_exists('mb_substr')
        ? mb_substr($texto, 0, $limite)
        : substr($texto, 0, $limite);
}

function registrarAuditoria(
    PDO $pdo,
    string $modulo,
    string $acao,
    string $entidade,
    int|string|null $entidadeId,
    string $descricao,
    ?array $dadosAntes = null,
    ?array $dadosDepois = null,
    ?int $usuarioId = null,
    ?string $usuarioNome = null
): void {
    if (!auditoriaTabelaDisponivel($pdo)) {
        return;
    }

    $usuarioId = $usuarioId ?? (isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null);
    $usuarioNome = $usuarioNome
        ?? ($_SESSION['usuario_nome'] ?? ($usuarioId ? 'Usuário #' . $usuarioId : 'Sistema'));
    $dadosAntes = auditoriaLimparDados($dadosAntes);
    $dadosDepois = auditoriaLimparDados($dadosDepois);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO auditoria_logs (
                usuario_id,
                usuario_nome,
                modulo,
                acao,
                entidade,
                entidade_id,
                descricao,
                dados_antes,
                dados_depois,
                endereco_ip,
                user_agent,
                url,
                criado_em
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $usuarioId,
            auditoriaCortar($usuarioNome, 150),
            auditoriaCortar($modulo, 80),
            auditoriaCortar($acao, 40),
            auditoriaCortar($entidade, 80),
            $entidadeId !== null ? (string)$entidadeId : null,
            auditoriaCortar($descricao, 500),
            $dadosAntes !== null
                ? json_encode($dadosAntes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            $dadosDepois !== null
                ? json_encode($dadosDepois, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            auditoriaCortar($_SERVER['REMOTE_ADDR'] ?? '', 45),
            auditoriaCortar($_SERVER['HTTP_USER_AGENT'] ?? '', 500),
            auditoriaCortar($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''), 500),
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // A auditoria nunca deve impedir a operação principal do sistema.
    }
}
