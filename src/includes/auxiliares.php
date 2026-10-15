<?php
// src/includes/auxiliares.php
// Funções de apoio das telas: rótulos de perfil, mensagens, auditoria e datas.
// Uso: depois do sessao.php, no começo de cada página:
//     require __DIR__ . '/includes/sessao.php';
//     require __DIR__ . '/includes/auxiliares.php';

const ROTULOS_PERFIL = [
    'ADMIN_GERAL'  => 'Administrador geral',
    'ADMIN_IGREJA' => 'Administrador da igreja',
    'LIDER'        => 'Líder',
    'VOLUNTARIO'   => 'Voluntário',
];

// Texto do selo no canto da tela (mostra com qual perfil o login foi feito)
const SELOS_PERFIL = [
    'ADMIN_GERAL'  => 'ADMIN GERAL',
    'ADMIN_IGREJA' => 'ADMIN',
    'LIDER'        => 'LÍDER',
    'VOLUNTARIO'   => 'VOLUNTÁRIO',
];

const MESES = [
    1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
    5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
    9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
];

// ---------------------------------------------------------------
// Perfis
// ---------------------------------------------------------------

function perfilRotulo(string $perfil): string
{
    return ROTULOS_PERFIL[$perfil] ?? $perfil;
}

function perfilSelo(string $perfil): string
{
    return SELOS_PERFIL[$perfil] ?? $perfil;
}

function nivelDe(array $usuario): int
{
    return NIVEIS_PERFIL[$usuario['perfil']] ?? 0;
}

// Administrador da igreja ou administrador geral
function ehAdmin(array $usuario): bool
{
    return nivelDe($usuario) >= NIVEIS_PERFIL['ADMIN_IGREJA'];
}

// Líder, administrador da igreja ou administrador geral
function ehLiderOuMais(array $usuario): bool
{
    return nivelDe($usuario) >= NIVEIS_PERFIL['LIDER'];
}

// Igreja do usuário; null = administrador geral (enxerga todas)
function igrejaDoUsuario(array $usuario): ?int
{
    return $usuario['igreja_id'] === null ? null : (int) $usuario['igreja_id'];
}

// ---------------------------------------------------------------
// Navegação e mensagens
// ---------------------------------------------------------------

function redirecionar(string $destino): never
{
    header('Location: ' . $destino);
    exit;
}

// Guarda uma mensagem para aparecer na próxima página (depois do redirecionamento).
// $tipo: success, danger, warning ou info (cores do Bootstrap)
function flash(string $tipo, string $texto): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'texto' => $texto];
}

function flashPegar(): array
{
    $mensagens = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $mensagens;
}

// ---------------------------------------------------------------
// Auditoria: chame depois de cada ação importante
// ---------------------------------------------------------------

function auditar(PDO $pdo, ?int $usuarioId, string $acao, string $entidade, ?int $entidadeId = null, array $dados = []): void
{
    $json = $dados ? json_encode($dados, JSON_UNESCAPED_UNICODE) : null;

    $pdo->prepare(
        'INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id, dados)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$usuarioId, $acao, $entidade, $entidadeId, $json]);
}

// ---------------------------------------------------------------
// Datas
// ---------------------------------------------------------------

// Aceita só "AAAA-MM-DD" válido (formato do <input type="date">). Devolve null se inválido.
function dataValida($valor): ?string
{
    if (!is_string($valor)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return ($d && $d->format('Y-m-d') === $valor) ? $valor : null;
}

function dataBr(string $ymd): string
{
    return (new DateTimeImmutable($ymd))->format('d/m/Y');
}
