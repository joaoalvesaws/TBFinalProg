<?php
// src/includes/sessao.php
// Sessão segura, token CSRF, login e controle de acesso por perfil.
// Uso: no começo de toda página, escreva
//     require __DIR__ . '/includes/sessao.php';
// (já carrega o conexao.php e inicia a sessão sozinho)

require_once __DIR__ . '/../conexao.php';

// Do menor para o maior nível. Quem tem nível maior acessa o que o menor acessa.
const NIVEIS_PERFIL = [
    'VOLUNTARIO'   => 1,
    'LIDER'        => 2,
    'ADMIN_IGREJA' => 3,
    'ADMIN_GERAL'  => 4,
];

// Tempo sem atividade para a sessão expirar (2 horas)
const SESSAO_INATIVIDADE_MAX = 7200;

// ---------------------------------------------------------------
// Sessão
// ---------------------------------------------------------------

function sessaoIniciar(): void
{
    global $config;

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');   // recusa IDs de sessão inventados
    ini_set('session.use_only_cookies', '1');  // nunca aceita ID pela URL

    session_name('escalas_sid');
    session_set_cookie_params([
        'lifetime' => 0,                                   // some ao fechar o navegador
        'path'     => '/',
        'secure'   => $config['ambiente'] === 'producao',  // só HTTPS em produção
        'httponly' => true,                                // JavaScript não lê o cookie
        'samesite' => 'Lax',                               // proteção extra contra CSRF
    ]);

    session_start();

    // Expira por inatividade
    if (isset($_SESSION['ultimo_acesso'])
        && time() - $_SESSION['ultimo_acesso'] > SESSAO_INATIVIDADE_MAX) {
        sessaoDestruir();
        session_start();
    }
    $_SESSION['ultimo_acesso'] = time();
}

function sessaoDestruir(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }

    session_destroy();
}

// ---------------------------------------------------------------
// Saída segura (XSS): use e() sempre que mostrar dados na tela
// ---------------------------------------------------------------

function e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------
// CSRF
// No formulário:      use csrfCampo() dentro do form (com echo)
// No começo do POST:  csrfVerificar();
// ---------------------------------------------------------------

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfCampo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function csrfVerificar(): void
{
    $enviado = $_POST['csrf'] ?? '';

    if (!is_string($enviado) || !hash_equals(csrfToken(), $enviado)) {
        http_response_code(403);
        exit('Requisição inválida. Volte à página anterior e tente novamente.');
    }
}

// ---------------------------------------------------------------
// Login e logout
// ---------------------------------------------------------------

// Chame depois de conferir a senha com password_verify().
// $usuario é a linha da tabela usuarios.
function loginIniciar(array $usuario): void
{
    session_regenerate_id(true);               // novo ID: evita fixação de sessão
    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['csrf']       = bin2hex(random_bytes(32));
}

function logout(): void
{
    sessaoDestruir();
}

// ---------------------------------------------------------------
// Usuário logado e permissões
// ---------------------------------------------------------------

// Devolve os dados do usuário logado, ou null se não há login válido.
// Consulta o banco a cada página: se o usuário for desativado ou mudar
// de perfil, o efeito é imediato.
function usuarioAtual(): ?array
{
    static $cache = false;

    if ($cache !== false) {
        return $cache;
    }

    if (empty($_SESSION['usuario_id'])) {
        return $cache = null;
    }

    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT id, igreja_id, nome, email, perfil
           FROM usuarios
          WHERE id = ? AND ativo = 1'
    );
    $stmt->execute([$_SESSION['usuario_id']]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        unset($_SESSION['usuario_id']);
        return $cache = null;
    }

    return $cache = $usuario;
}

// Exige login. Se não estiver logado, manda para o login.
function exigirLogin(): array
{
    $usuario = usuarioAtual();

    if ($usuario === null) {
        header('Location: /login.php');
        exit;
    }

    return $usuario;
}

// Exige um perfil mínimo: exigirPerfil('LIDER') aceita líder, admin da
// igreja e admin geral, mas não voluntário.
function exigirPerfil(string $perfilMinimo): array
{
    $usuario = exigirLogin();

    $nivelUsuario = NIVEIS_PERFIL[$usuario['perfil']] ?? 0;
    $nivelExigido = NIVEIS_PERFIL[$perfilMinimo] ?? PHP_INT_MAX;

    if ($nivelUsuario < $nivelExigido) {
        http_response_code(403);
        exit('Você não tem permissão para acessar esta página.');
    }

    return $usuario;
}

// Cada igreja só enxerga os próprios dados; o admin geral enxerga tudo.
function podeAcessarIgreja(array $usuario, int $igrejaId): bool
{
    if ($usuario['perfil'] === 'ADMIN_GERAL') {
        return true;
    }
    return (int) $usuario['igreja_id'] === $igrejaId;
}

// Inicia a sessão automaticamente ao incluir este arquivo
sessaoIniciar();
