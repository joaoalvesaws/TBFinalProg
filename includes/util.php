<?php
// htdocs/includes/util.php
// Funções auxiliares usadas por várias páginas.

require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/../notificar.php';

// ---------------------------------------------------------------
// Mensagens rápidas (aparecem uma vez no topo da página)
// ---------------------------------------------------------------

function flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'msg' => $mensagem];
}

function flashExibir(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }
    foreach ($_SESSION['flash'] as $f) {
        $auto = $f['tipo'] === 'success' ? ' data-fecha-auto="6000"' : '';
        echo '<div class="alert alert-' . e($f['tipo']) . '"' . $auto . '>'
           . e($f['msg']) . '</div>';
    }
    unset($_SESSION['flash']);
}

function redirecionar(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------
// Ler campos de formulário com segurança
// ---------------------------------------------------------------

function post(string $campo): string
{
    $v = $_POST[$campo] ?? '';
    return is_string($v) ? trim($v) : '';
}

function antigo(string $campo): string
{
    $v = $_POST[$campo] ?? '';
    return e(is_string($v) ? $v : '');
}

// ---------------------------------------------------------------
// Validações
// ---------------------------------------------------------------

function validarSenha(string $senha): ?string
{
    if (strlen($senha) < 8) {
        return 'A senha precisa ter pelo menos 8 caracteres.';
    }
    if (strlen($senha) > 72) {
        return 'A senha pode ter no máximo 72 caracteres.';
    }
    return null;
}

function dataValida(string $data): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $data);
    return $d !== false && $d->format('Y-m-d') === $data;
}

function horaValida(string $hora): bool
{
    $d = DateTime::createFromFormat('H:i', $hora);
    return $d !== false && $d->format('H:i') === $hora;
}

// ---------------------------------------------------------------
// Perfis e permissões
// ---------------------------------------------------------------

const ROTULOS_PERFIL = [
    'VOLUNTARIO'   => 'Voluntário',
    'LIDER'        => 'Líder',
    'ADMIN_IGREJA' => 'Admin da igreja',
    'ADMIN_GERAL'  => 'Admin geral',
];

function nivelPerfil(string $perfil): int
{
    return NIVEIS_PERFIL[$perfil] ?? 0;
}

function perfisQuePodeCriar(array $usuario): array
{
    $meu   = nivelPerfil($usuario['perfil']);
    $lista = [];
    foreach (NIVEIS_PERFIL as $perfil => $nivel) {
        if ($nivel < $meu) {
            $lista[] = $perfil;
        }
    }
    return $lista;
}

function podeGerenciarUsuario(PDO $pdo, array $eu, int $alvoId): bool
{
    $s = $pdo->prepare('SELECT id, igreja_id, perfil FROM usuarios WHERE id = ?');
    $s->execute([$alvoId]);
    $alvo = $s->fetch();

    if (!$alvo || nivelPerfil($alvo['perfil']) >= nivelPerfil($eu['perfil'])) {
        return false;
    }

    switch ($eu['perfil']) {
        case 'ADMIN_GERAL':
            return true;

        case 'ADMIN_IGREJA':
            return $alvo['igreja_id'] !== null
                && (int) $alvo['igreja_id'] === (int) $eu['igreja_id'];

        case 'LIDER':
            $q = $pdo->prepare(
                'SELECT 1
                   FROM departamento_usuario meu
                   JOIN departamento_usuario dele ON dele.departamento_id = meu.departamento_id
                  WHERE meu.usuario_id = ? AND meu.e_lider = 1 AND dele.usuario_id = ?
                  LIMIT 1'
            );
            $q->execute([$eu['id'], $alvoId]);
            return (bool) $q->fetch();
    }

    return false;
}

// ---------------------------------------------------------------
// Igrejas
// ---------------------------------------------------------------

function igrejaDoContexto(PDO $pdo, array $usuario, $igrejaIdEnviado): ?int
{
    if ($usuario['perfil'] !== 'ADMIN_GERAL') {
        return $usuario['igreja_id'] !== null ? (int) $usuario['igreja_id'] : null;
    }

    $id = (int) $igrejaIdEnviado;
    if ($id <= 0) {
        return null;
    }
    $s = $pdo->prepare('SELECT id FROM igrejas WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ? $id : null;
}

function listarIgrejas(PDO $pdo): array
{
    return $pdo->query('SELECT id, nome FROM igrejas ORDER BY nome')->fetchAll();
}

// ---------------------------------------------------------------
// Auditoria
// ---------------------------------------------------------------

function auditar(PDO $pdo, ?int $usuarioId, string $acao, string $entidade,
                 ?int $entidadeId = null, array $dados = []): void
{
    try {
        registrarAuditoria($pdo, $usuarioId, $acao, $entidade, $entidadeId, $dados);
    } catch (Throwable $ex) {
        error_log('Falha ao gravar auditoria: ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------
// Links de definir/recuperar senha
// ---------------------------------------------------------------

function criarTokenSenha(PDO $pdo, int $usuarioId, string $tipo, int $horasValidade): string
{
    $pdo->prepare('UPDATE tokens_senha SET usado_em = NOW() WHERE usuario_id = ? AND usado_em IS NULL')
        ->execute([$usuarioId]);

    $token = bin2hex(random_bytes(32));

    $pdo->prepare(
        'INSERT INTO tokens_senha (usuario_id, token_hash, tipo, expira_em)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . (int) $horasValidade . ' HOUR))'
    )->execute([$usuarioId, hash('sha256', $token), $tipo]);

    return $token;
}

function enviarConviteSenha(PDO $pdo, array $usuario, string $tipo = 'CONVITE'): string
{
    global $config;

    $horas = $tipo === 'CONVITE' ? 72 : 2;
    $token = criarTokenSenha($pdo, (int) $usuario['id'], $tipo, $horas);
    $base  = rtrim($config['app_url'] ?? $config['url_base'] ?? 'https://kosmos.freehosting.dev', '/');
    $link  = $base . '/definir_senha.php?token=' . $token;

    if ($tipo === 'CONVITE') {
        $template = 'convite_senha';
        $texto = "Olá, {$usuario['nome']}!\n\n"
               . "Uma conta foi criada para você no Sistema de Escalas.\n"
               . "Para definir sua senha, acesse o link abaixo (válido por 72 horas):\n\n"
               . "$link\n\n"
               . "Se você não esperava este convite, ignore esta mensagem.";
    } else {
        $template = 'recuperar_senha';
        $texto = "Olá, {$usuario['nome']}!\n\n"
               . "Recebemos um pedido para redefinir a sua senha.\n"
               . "Acesse o link abaixo (válido por 2 horas):\n\n"
               . "$link\n\n"
               . "Se não foi você, ignore esta mensagem: sua senha continua a mesma.";
    }

    enfileirar($pdo, (int) $usuario['id'], 'EMAIL', $template, $texto);

    return $link;
}

function avisarLinkEmDesenvolvimento(string $link): void
{
    global $config;

    if (($config['ambiente'] ?? '') === 'desenvolvimento') {
        flash('info', 'Só em desenvolvimento - link enviado por e-mail: ' . $link);
    }
}



function linkGoogleAgenda(
    string $titulo,
    string $inicio,
    string $fim,
    string $local = '',
    string $descricao = ''
): string {

    return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        . '&text=' . urlencode($titulo)
        . '&dates='
        . gmdate('Ymd\THis\Z', strtotime($inicio))
        . '/'
        . gmdate('Ymd\THis\Z', strtotime($fim))
        . '&location=' . urlencode($local)
        . '&details=' . urlencode($descricao);
}


function tokenCalendario(PDO $pdo, int $usuarioId): string
{
    $stmt = $pdo->prepare(
        'SELECT token FROM calendario_tokens WHERE usuario_id = ?'
    );
    $stmt->execute([$usuarioId]);

    $token = $stmt->fetchColumn();

    if ($token) {
        return $token;
    }

    $token = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare(
        'INSERT INTO calendario_tokens (usuario_id, token)
         VALUES (?, ?)'
    );

    $stmt->execute([$usuarioId, $token]);

    return $token;
}




/**
 * Adiciona uma notificação de e-mail na fila_envios do banco de dados
 * para ser processada pelo cron_avisos / notificar.php.
 */
function notificarUsuarioPorEmail(PDO $pdo, int $usuarioId, string $assunto, string $mensagemTexto): bool
{
    try {
        // Busca o usuário ativo
        $stmt = $pdo->prepare('SELECT id, nome, email FROM usuarios WHERE id = ? AND ativo = 1');
        $stmt->execute([$usuarioId]);
        $u = $stmt->fetch();

        if (!$u || empty($u['email'])) {
            return false;
        }

        $urlSite = 'https://kosmos.freehosting.dev';
        $corpoTexto = "Olá, {$u['nome']}!\n\n"
                    . "{$mensagemTexto}\n\n"
                    . "Acesse o sistema para verificar: {$urlSite}";

        // 1. Tenta usar a função enfileirar() do sistema
        if (function_exists('enfileirar')) {
            enfileirar($pdo, (int) $u['id'], 'EMAIL', $assunto, $corpoTexto);
            return true;
        }

        // 2. Fallback: faz o INSERT diretamente na fila_envios se enfileirar() não estiver no escopo
        $stmtFila = $pdo->prepare(
            "INSERT INTO fila_envios (usuario_id, meio, assunto, mensagem, status, criado_em)
             VALUES (?, 'EMAIL', ?, ?, 'PENDENTE', NOW())"
        );
        $stmtFila->execute([$u['id'], $assunto, $corpoTexto]);
        return true;

    } catch (Throwable $e) {
        error_log('[ERRO AO ENFILEIRAR EMAIL] ' . $e->getMessage());
    }

    return false;
}
