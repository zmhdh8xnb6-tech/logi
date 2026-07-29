<?php
date_default_timezone_set('America/Sao_Paulo');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

if (!defined('RECEITAWS_TOKEN')) {
    define('RECEITAWS_TOKEN', getenv('RECEITAWS_TOKEN') ?: '');
}

if (!defined('RECEITAWS_DIAS_DEFASAGEM')) {
    define('RECEITAWS_DIAS_DEFASAGEM', (int)(getenv('RECEITAWS_DIAS_DEFASAGEM') ?: 1));
}

if (!defined('RECEITAWS_FALLBACK')) {
    define('RECEITAWS_FALLBACK', getenv('RECEITAWS_FALLBACK') ?: 'noCache');
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auditoria.php';

$ambiente = $_SERVER['SERVER_NAME'] ?? 'localhost';

if ($ambiente === 'localhost' || $ambiente === '127.0.0.1') {
    $centralHost = "localhost";
    $centralDb = "crud_clientes";
    $centralUser = "root";
    $centralPass = "";
    $baseUrl = "http://localhost/projeto_ph";
} else {
    $centralHost = "localhost";
    $centralDb = "u285798939_logi";
    $centralUser = "u285798939_logi";
    $centralPass = "Logi@2026#Sistema";
    $baseUrl = "https://sistemalogi.com.br";
}

try {
    $authPdo = new PDO("mysql:host=$centralHost;dbname=$centralDb;charset=utf8mb4", $centralUser, $centralPass);
    $authPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $authPdo->exec("SET time_zone = '-03:00'");

    $authConn = new mysqli($centralHost, $centralUser, $centralPass, $centralDb);
    $authConn->set_charset('utf8mb4');
    $authConn->query("SET time_zone = '-03:00'");

    $host = $centralHost;
    $db = $centralDb;
    $user = $centralUser;
    $pass = $centralPass;
    $pdo = $authPdo;
    $conn = $authConn;

    atualizarSessaoUsuario($authPdo);

    if (!empty($_SESSION['tenant_db'])) {
        $host = $_SESSION['tenant_host'] ?: $centralHost;
        $db = $_SESSION['tenant_db'];
        $user = $_SESSION['tenant_user'] ?: $centralUser;
        $pass = array_key_exists('tenant_pass', $_SESSION) && !empty($_SESSION['tenant_user'])
            ? $_SESSION['tenant_pass']
            : $centralPass;
    }

    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '-03:00'");

    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '-03:00'");
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}

if (!function_exists('clientesSituacaoDisponivel')) {
    function clientesSituacaoDisponivel(PDO $pdo): bool
    {
        static $cache = [];

        $chave = spl_object_id($pdo);

        if (array_key_exists($chave, $cache)) {
            return $cache[$chave];
        }

        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM clientes LIKE 'situacao_cliente'");
            $stmt->execute();
            $cache[$chave] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $cache[$chave] = false;
        }

        return $cache[$chave];
    }
}

if (!function_exists('clientesFiltroAtivos')) {
    function clientesFiltroAtivos(PDO $pdo, string $alias = ''): string
    {
        if (!clientesSituacaoDisponivel($pdo)) {
            return '';
        }

        $prefixo = $alias !== '' ? $alias . '.' : '';
        return " AND COALESCE({$prefixo}situacao_cliente, 'ativo') = 'ativo'";
    }
}

if (!function_exists('clientesFiltroDevolvidos')) {
    function clientesFiltroDevolvidos(PDO $pdo, string $alias = ''): string
    {
        if (!clientesSituacaoDisponivel($pdo)) {
            return ' AND 1 = 0';
        }

        $prefixo = $alias !== '' ? $alias . '.' : '';
        return " AND COALESCE({$prefixo}situacao_cliente, 'ativo') IN ('devolvido', 'baixado')";
    }
}

if (!function_exists('logiTabelaExiste')) {
    function logiTabelaExiste(PDO $pdo, string $tabela): bool
    {
        static $cache = [];
        $chave = spl_object_id($pdo) . ':' . $tabela;

        if (array_key_exists($chave, $cache)) {
            return $cache[$chave];
        }

        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tabela]);
            $cache[$chave] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$chave] = false;
        }

        return $cache[$chave];
    }
}

if (!function_exists('logiColunaExiste')) {
    function logiColunaExiste(PDO $pdo, string $tabela, string $coluna): bool
    {
        static $cache = [];
        $chave = spl_object_id($pdo) . ':' . $tabela . ':' . $coluna;

        if (array_key_exists($chave, $cache)) {
            return $cache[$chave];
        }

        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM {$tabela} LIKE ?");
            $stmt->execute([$coluna]);
            $cache[$chave] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $cache[$chave] = false;
        }

        return $cache[$chave];
    }
}

if (!function_exists('multiempresaDisponivel')) {
    function multiempresaDisponivel(PDO $pdo): bool
    {
        return logiTabelaExiste($pdo, 'empresas');
    }
}

if (!function_exists('empresasDisponiveis')) {
    function empresasDisponiveis(PDO $pdo): array
    {
        if (!logiTabelaExiste($pdo, 'empresas')) {
            return [];
        }

        try {
            $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

            if (
                $usuarioId > 0
                && !usuarioEhAdmin()
                && logiTabelaExiste($pdo, 'usuario_empresas')
            ) {
                $stmt = $pdo->prepare("
                    SELECT e.id, e.nome
                    FROM empresas e
                    INNER JOIN usuario_empresas ue ON ue.empresa_id = e.id
                    WHERE ue.usuario_id = ?
                      AND COALESCE(e.ativa, 1) = 1
                    ORDER BY e.id ASC
                ");
                $stmt->execute([$usuarioId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $stmt = $pdo->query("
                SELECT id, nome
                FROM empresas
                WHERE COALESCE(ativa, 1) = 1
                ORDER BY id ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('empresaAtivaId')) {
    function empresaAtivaId(PDO $pdo): ?int
    {
        if (!multiempresaDisponivel($pdo)) {
            return null;
        }

        $empresas = empresasDisponiveis($pdo);

        if ($empresas === []) {
            unset($_SESSION['empresa_id'], $_SESSION['empresa_nome']);
            return 0;
        }

        $empresaSessao = (int)($_SESSION['empresa_id'] ?? 0);

        foreach ($empresas as $empresa) {
            if ((int)$empresa['id'] === $empresaSessao) {
                return $empresaSessao;
            }
        }

        $_SESSION['empresa_id'] = (int)$empresas[0]['id'];
        $_SESSION['empresa_nome'] = (string)$empresas[0]['nome'];

        return (int)$empresas[0]['id'];
    }
}

if (!function_exists('empresaAtivaNome')) {
    function empresaAtivaNome(PDO $pdo): string
    {
        $empresaId = empresaAtivaId($pdo);

        if ($empresaId === null) {
            return '';
        }

        foreach (empresasDisponiveis($pdo) as $empresa) {
            if ((int)$empresa['id'] === $empresaId) {
                return (string)$empresa['nome'];
            }
        }

        return '';
    }
}

if (!function_exists('empresaFiltro')) {
    function empresaFiltro(PDO $pdo, string $tabela, string $alias = ''): string
    {
        $empresaId = empresaAtivaId($pdo);

        if ($empresaId === null || !logiTabelaExiste($pdo, 'empresas')) {
            return '';
        }

        if (!logiColunaExiste($pdo, $tabela, 'empresa_id')) {
            return ' AND 1 = 0';
        }

        $prefixo = $alias !== '' ? $alias . '.' : '';
        return " AND {$prefixo}empresa_id = " . (int)$empresaId;
    }
}

if (!function_exists('empresaFiltroClienteDireto')) {
    function empresaFiltroClienteDireto(PDO $pdo, string $alias = ''): string
    {
        $empresaId = (int)($_SESSION['empresa_id'] ?? 0);

        if ($empresaId <= 0) {
            $empresaId = (int)(empresaAtivaId($pdo) ?? 0);
        }

        if (!logiColunaExiste($pdo, 'clientes', 'empresa_id')) {
            return logiTabelaExiste($pdo, 'empresas') ? ' AND 1 = 0' : '';
        }

        if ($empresaId <= 0) {
            return logiTabelaExiste($pdo, 'empresas') ? ' AND 1 = 0' : '';
        }

        $prefixo = $alias !== '' ? $alias . '.' : '';
        return " AND {$prefixo}empresa_id = " . $empresaId;
    }
}

if (!function_exists('empresaIdParaInsert')) {
    function empresaIdParaInsert(PDO $pdo, string $tabela): ?int
    {
        if (!logiColunaExiste($pdo, $tabela, 'empresa_id')) {
            return null;
        }

        return empresaAtivaId($pdo);
    }
}

if (!function_exists('empresaInsertColuna')) {
    function empresaInsertColuna(PDO $pdo, string $tabela): string
    {
        return empresaIdParaInsert($pdo, $tabela) !== null ? 'empresa_id, ' : '';
    }
}

if (!function_exists('empresaInsertPlaceholder')) {
    function empresaInsertPlaceholder(PDO $pdo, string $tabela): string
    {
        return empresaIdParaInsert($pdo, $tabela) !== null ? '?, ' : '';
    }
}

if (!function_exists('empresaInsertValores')) {
    function empresaInsertValores(PDO $pdo, string $tabela): array
    {
        $empresaId = empresaIdParaInsert($pdo, $tabela);
        return $empresaId !== null ? [$empresaId] : [];
    }
}
