<?php

function assinaturasEmailToken(): string
{
    if (empty($_SESSION['assinaturas_email_csrf_token'])) {
        $_SESSION['assinaturas_email_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['assinaturas_email_csrf_token'];
}

function assinaturasEmailTokenValido(?string $token): bool
{
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['assinaturas_email_csrf_token'])
        && hash_equals((string)$_SESSION['assinaturas_email_csrf_token'], $token);
}

function assinaturasEmailEmpresaId(PDO $pdo): int
{
    return max(1, (int)(empresaAtivaId($pdo) ?? 1));
}

function assinaturasEmailEstruturaDisponivel(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN ('assinaturas_email', 'assinaturas_email_cartao_historico')
        ");
        return (int)$stmt->fetchColumn() === 2;
    } catch (Throwable $e) {
        return false;
    }
}

function assinaturasEmailInstalarEstrutura(PDO $pdo): void
{
    $sql = (string)@file_get_contents(__DIR__ . '/../sql/assinaturas_email.sql');
    if (trim($sql) === '') {
        throw new RuntimeException('O arquivo de instalação das assinaturas de e-mail não foi encontrado.');
    }

    $comandos = preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [];
    foreach ($comandos as $comando) {
        if (trim($comando) !== '') {
            $pdo->exec($comando);
        }
    }
}

function assinaturasEmailNormalizarCartaoFinal(string $valor): string
{
    return preg_replace('/\D+/', '', $valor) ?? '';
}

function assinaturasEmailCartaoRotulo(?string $final): string
{
    $final = assinaturasEmailNormalizarCartaoFinal((string)$final);
    return strlen($final) === 4 ? '•••• ' . $final : '-';
}

function assinaturasEmailDiaRotulo(int|string|null $dia): string
{
    $dia = (int)$dia;
    return $dia >= 1 && $dia <= 31 ? 'Dia ' . $dia : '-';
}

function assinaturasEmailDataHora(?string $data): string
{
    return empty($data) ? '-' : date('d/m/Y H:i', strtotime($data));
}

function assinaturasEmailBuscar(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM assinaturas_email WHERE id = ? AND empresa_id = ? LIMIT 1');
    $stmt->execute([$id, assinaturasEmailEmpresaId($pdo)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function assinaturasEmailRedirecionar(string $mensagem, string $tipo = 'success'): never
{
    $_SESSION['assinaturas_email_flash'] = [
        'mensagem' => $mensagem,
        'tipo' => in_array($tipo, ['success', 'warning', 'danger', 'info'], true) ? $tipo : 'info',
    ];
    header('Location: assinaturas_email.php');
    exit;
}
