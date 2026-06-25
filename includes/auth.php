<?php
if (!function_exists('modulosSistema')) {
    function modulosSistema(): array
    {
        return [
            'clientes' => 'Clientes',
            'parcelamentos' => 'Parcelamentos',
            'certificados' => 'Certificado Digital',
            'procuracoes' => 'Procurações',
            'alvaras' => 'Alvarás',
            'contador' => 'Contador',
            'crf' => 'Cadastro CRF',
            'contratos' => 'Contrato de Prestação de Serviços',
            'paralisacoes' => 'Paralisações',
            'baixas' => 'Baixas',
            'outros' => 'Outros Serviços',
            'usuarios' => 'Usuários e Permissões',
        ];
    }
}

if (!function_exists('usuarioLogado')) {
    function usuarioLogado(): bool
    {
        return isset($_SESSION['usuario_id']);
    }
}

if (!function_exists('usuarioEhAdmin')) {
    function usuarioEhAdmin(): bool
    {
        return ($_SESSION['usuario_tipo'] ?? '') === 'admin';
    }
}

if (!function_exists('permissoesUsuario')) {
    function permissoesUsuario(): array
    {
        $permissoes = $_SESSION['usuario_permissoes'] ?? [];

        if (is_string($permissoes)) {
            $permissoes = json_decode($permissoes, true) ?: [];
        }

        return is_array($permissoes) ? $permissoes : [];
    }
}

if (!function_exists('usuarioPode')) {
    function usuarioPode(string $modulo): bool
    {
        if (usuarioEhAdmin()) {
            return true;
        }

        return in_array($modulo, permissoesUsuario(), true);
    }
}

if (!function_exists('exigirLogin')) {
    function exigirLogin(): void
    {
        if (!usuarioLogado()) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('exigirPermissao')) {
    function exigirPermissao(string $modulo): void
    {
        exigirLogin();

        if (!usuarioPode($modulo)) {
            http_response_code(403);
            echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><title>Acesso negado</title>';
            echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>';
            echo '<body class="bg-light"><div class="container py-5"><div class="alert alert-danger">';
            echo '<h4 class="alert-heading">Acesso negado</h4>';
            echo '<p>Seu usuário não tem permissão para acessar esta página.</p>';
            echo '<a href="home.php" class="btn btn-outline-danger">Voltar</a>';
            echo '</div></div></body></html>';
            exit;
        }
    }
}
