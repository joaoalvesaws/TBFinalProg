<?php
// src/includes/util.php
// Funções auxiliares usadas por várias páginas.
// Use sempre DEPOIS do sessao.php, no começo da página:
//     require __DIR__ . '/includes/sessao.php';
//     require __DIR__ . '/includes/util.php';

require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/../notificar.php';


// ---------------------------------------------------------------
// Mensagens rápidas (aparecem uma vez no topo da página)
// ---------------------------------------------------------------

// $tipo: success, danger, warning ou info (cores do Bootstrap)
function flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'msg' => $mensagem];
}

// Chamada pelo topo.php
function flashExibir(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }
    foreach ($_SESSION['flash'] as $f) {
        // Só as mensagens de sucesso somem sozinhas (ver assets/app.js)
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

// Texto do POST sem espaços nas pontas ('' se não existir ou não for texto)
function post(string $campo): string
{
    $v = $_POST[$campo] ?? '';
    return is_string($v) ? trim($v) : '';
}

// Valor antigo do campo, já protegido, para refazer o formulário após um erro
function antigo(string $campo): string
{
    $v = $_POST[$campo] ?? '';
    return e(is_string($v) ? $v : '');
}

// ---------------------------------------------------------------
// Validações
// ---------------------------------------------------------------

// Devolve a mensagem de erro, ou null se a senha for aceitável
function validarSenha(string $senha): ?string
{
    if (strlen($senha) < 8) {
        return 'A senha precisa ter pelo menos 8 caracteres.';
    }
    if (strlen($senha) > 72) {   // limite do bcrypt, usado pelo password_hash
        return 'A senha pode ter no máximo 72 caracteres.';
    }
    return null;
}

function dataValida(string $data): bool   // formato AAAA-MM-DD
{
    $d = DateTime::createFromFormat('Y-m-d', $data);
    return $d !== false && $d->format('Y-m-d') === $data;
}

function horaValida(string $hora): bool   // formato HH:MM
{
    $d = DateTime::createFromFormat('H:i', $hora);
    return $d !== false && $d->format('H:i') === $hora;
}

// ---------------------------------------------------------------
// Perfis e permissões (usa NIVEIS_PERFIL do sessao.php)
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

// Regra: cada pessoa só cadastra perfis ABAIXO do seu.
// Admin geral -> admin da igreja, líder, voluntário
// Admin da igreja -> líder, voluntário
// Líder -> voluntário
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

// Pode $eu mexer na conta de $alvoId? (reenviar convite, ativar/desativar)
// - só em perfis abaixo do seu
// - admin da igreja: só da própria igreja
// - líder: só quem está em um departamento que ele lidera
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

// Qual igreja a tela deve usar? O admin geral escolhe no formulário;
// os outros perfis usam a própria. Devolve null se não houver igreja válida.
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
// Auditoria (usa registrarAuditoria() do includes/auditoria.php, mas
// não derruba a tela se a gravação falhar; o erro vai para o log)
// Exemplo: auditar($pdo, $usuario['id'], 'IGREJA_CRIADA', 'igrejas', $id, ['nome' => $nome]);
// ---------------------------------------------------------------
function auditar(PDO $pdo, ?int $usuarioId, string $acao, string $entidade,
                 ?int $entidadeId = null, array $dados = []): void
{
    try {
        registrarAuditoria($pdo, $usuarioId, $acao, $entidade, $entidadeId, $dados);
    } catch (Throwable $ex) {
        // Falha na auditoria não deve derrubar a tela, mas precisa ficar no log
        error_log('Falha ao gravar auditoria: ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------
// Links de definir/recuperar senha
// ---------------------------------------------------------------

// Cria um token novo e invalida os anteriores ainda não usados.
// Devolve o token "cru" (vai só no link); no banco fica apenas o hash.
function criarTokenSenha(PDO $pdo, int $usuarioId, string $tipo, int $horasValidade): string
{
    $pdo->prepare('UPDATE tokens_senha SET usado_em = NOW() WHERE usuario_id = ? AND usado_em IS NULL')
        ->execute([$usuarioId]);

    $token = bin2hex(random_bytes(32));   // 64 caracteres, impossível de adivinhar

    // $horasValidade é inteiro (cast), então é seguro escrever direto no SQL
    $pdo->prepare(
        'INSERT INTO tokens_senha (usuario_id, token_hash, tipo, expira_em)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . (int) $horasValidade . ' HOUR))'
    )->execute([$usuarioId, hash('sha256', $token), $tipo]);

    return $token;
}

// Cria o token, coloca o e-mail na fila e devolve o link.
// $usuario precisa ter: id, nome. $tipo: 'CONVITE' ou 'RECUPERACAO'.
function enviarConviteSenha(PDO $pdo, array $usuario, string $tipo = 'CONVITE'): string
{
    global $config;

    $horas = $tipo === 'CONVITE' ? 72 : 2;
    $token = criarTokenSenha($pdo, (int) $usuario['id'], $tipo, $horas);
    $base  = rtrim($config['app_url'] ?? 'http://localhost:8080', '/');
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

// Só em desenvolvimento: mostra o link na tela para você testar sem e-mail
function avisarLinkEmDesenvolvimento(string $link): void
{
    global $config;

    if (($config['ambiente'] ?? '') === 'desenvolvimento') {
        flash('info', 'Só em desenvolvimento - link enviado por e-mail: ' . $link);
    }
}