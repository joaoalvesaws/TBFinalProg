<?php
// Trocas de escala entre voluntários com notificações seguras por e-mail.

require __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/util.php';
require __DIR__ . '/regras.php';
require_once __DIR__ . '/includes/auditoria.php';

$usuario   = exigirLogin();
$usuarioId = (int) $usuario['id'];
$ehLider   = (NIVEIS_PERFIL[$usuario['perfil']] ?? 0) >= NIVEIS_PERFIL['LIDER'];

// ---------------------------------------------------------------
// Funções auxiliares
// ---------------------------------------------------------------

function dataBR(string $data): string
{
    return date('d/m/Y', strtotime($data));
}

function resultado(string $tipo, string $texto): array
{
    return ['tipo' => $tipo, 'texto' => $texto];
}

function nomeUsuario(PDO $pdo, int $id): string
{
    static $cache = [];

    if (!isset($cache[$id])) {
        $stmt = $pdo->prepare('SELECT nome FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        $cache[$id] = (string) $stmt->fetchColumn();
    }

    return $cache[$id];
}

function avisar(PDO $pdo, int $usuarioId, string $titulo, string $mensagem): void
{
    $pdo->prepare('INSERT INTO notificacoes (usuario_id, titulo, mensagem) VALUES (?, ?, ?)')
        ->execute([$usuarioId, $titulo, $mensagem]);
}

function emTransacao(PDO $pdo, callable $acao): array
{
    $pdo->beginTransaction();

    try {
        $retorno = $acao();
        $pdo->commit();
        return $retorno;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            return resultado('erro', 'Não foi possível aplicar a troca: conflito com outra escala.');
        }
        throw $e;
    }
}

function carregarTroca(PDO $pdo, int $trocaId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT t.*,
                es.usuario_id AS dono_atual,
                es.evento_id,
                es.departamento_id,
                es.status AS escala_status,
                ev.igreja_id,
                ev.nome AS evento_nome,
                ev.data, ev.hora_inicio, ev.hora_fim,
                (TIMESTAMP(ev.data, ev.hora_inicio) > NOW()) AS evento_futuro
           FROM trocas t
           JOIN escalas es ON es.id = t.escala_id
           JOIN eventos ev ON ev.id = es.evento_id
          WHERE t.id = ?
          FOR UPDATE'
    );
    $stmt->execute([$trocaId]);

    return $stmt->fetch() ?: null;
}

function validacaoRigida(PDO $pdo, array $t): ?string
{
    if ((int) $t['dono_atual'] !== (int) $t['solicitante_id']) {
        return 'A vaga não pertence mais a quem pediu a troca.';
    }
    if (!in_array($t['escala_status'], ['ENVIADA', 'ACEITA'], true)) {
        return 'A escala não está mais publicada.';
    }
    if (!(int) $t['evento_futuro']) {
        return 'O evento já começou.';
    }

    $stmt = $pdo->prepare(
        'SELECT 1
           FROM departamento_usuario du
           JOIN usuarios u ON u.id = du.usuario_id
          WHERE du.departamento_id = ? AND du.usuario_id = ? AND u.ativo = 1'
    );
    $stmt->execute([$t['departamento_id'], $t['destinatario_id']]);
    if (!$stmt->fetchColumn()) {
        return 'A pessoa que assumiria a vaga não faz parte deste departamento.';
    }

    $stmt = $pdo->prepare('SELECT 1 FROM escalas WHERE evento_id = ? AND usuario_id = ?');
    $stmt->execute([$t['evento_id'], $t['destinatario_id']]);
    if ($stmt->fetchColumn()) {
        return 'A pessoa que assumiria a vaga já está escalada neste evento.';
    }

    return null;
}

function aplicarTroca(PDO $pdo, array $t, string $statusFinal, ?int $aprovadorId): void
{
    $pdo->prepare("UPDATE escalas SET usuario_id = ?, status = 'ACEITA' WHERE id = ?")
        ->execute([$t['destinatario_id'], $t['escala_id']]);

    $pdo->prepare('UPDATE trocas SET status = ?, aprovado_por = ?, respondido_em = NOW() WHERE id = ?')
        ->execute([$statusFinal, $aprovadorId, $t['id']]);
}

function encerrarTroca(PDO $pdo, array $t, string $status, array $motivos): void
{
    $pdo->prepare('UPDATE trocas SET status = ?, violacoes = ?, respondido_em = NOW() WHERE id = ?')
        ->execute([$status, json_encode($motivos, JSON_UNESCAPED_UNICODE), $t['id']]);
}

function podeAprovar(array $usuario, array $t, array $depsLideradas): bool
{
    switch ($usuario['perfil']) {
        case 'ADMIN_GERAL':
            return true;
        case 'ADMIN_IGREJA':
            return (int) $usuario['igreja_id'] === (int) $t['igreja_id'];
        case 'LIDER':
            return in_array((int) $t['departamento_id'], $depsLideradas, true);
        default:
            return false;
    }
}

// ---------------------------------------------------------------
// Ações de Troca
// ---------------------------------------------------------------

function solicitarTroca(PDO $pdo, array $usuario, int $escalaId, int $destId): array
{
    $stmt = $pdo->prepare(
        "SELECT es.id, es.departamento_id, es.evento_id,
                ev.nome AS evento_nome, ev.data, ev.hora_inicio
           FROM escalas es
           JOIN eventos ev ON ev.id = es.evento_id
          WHERE es.id = ? AND es.usuario_id = ?
            AND es.status IN ('ENVIADA', 'ACEITA')
            AND TIMESTAMP(ev.data, ev.hora_inicio) > NOW()
          FOR UPDATE"
    );
    $stmt->execute([$escalaId, $usuario['id']]);
    $escala = $stmt->fetch();

    if (!$escala) {
        return resultado('erro', 'Esta escala não existe, não é sua ou não pode mais ser trocada.');
    }
    if ($destId === (int) $usuario['id'] || $destId <= 0) {
        return resultado('erro', 'Escolha um colega para assumir a vaga.');
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM trocas
          WHERE escala_id = ? AND status IN ('PENDENTE', 'AGUARDANDO_LIDER') LIMIT 1"
    );
    $stmt->execute([$escalaId]);
    if ($stmt->fetchColumn()) {
        return resultado('erro', 'Já existe um pedido de troca em andamento para esta vaga.');
    }

    $stmt = $pdo->prepare(
        'SELECT 1
           FROM departamento_usuario du
           JOIN usuarios u ON u.id = du.usuario_id
          WHERE du.departamento_id = ? AND du.usuario_id = ? AND u.ativo = 1'
    );
    $stmt->execute([$escala['departamento_id'], $destId]);
    if (!$stmt->fetchColumn()) {
        return resultado('erro', 'Essa pessoa não faz parte deste departamento.');
    }

    $stmt = $pdo->prepare('SELECT 1 FROM escalas WHERE evento_id = ? AND usuario_id = ?');
    $stmt->execute([$escala['evento_id'], $destId]);
    if ($stmt->fetchColumn()) {
        return resultado('erro', 'Essa pessoa já está escalada neste evento.');
    }

    $pdo->prepare(
        'INSERT INTO trocas (escala_id, solicitante_id, destinatario_id, validade)
         VALUES (?, ?, ?, ?)'
    )->execute([$escalaId, $usuario['id'], $destId, $escala['data'] . ' ' . $escala['hora_inicio']]);
    $trocaId = (int) $pdo->lastInsertId();

    registrarAuditoria($pdo, (int) $usuario['id'], 'TROCA_SOLICITADA', 'trocas', $trocaId, [
        'escala_id'       => $escalaId,
        'destinatario_id' => $destId,
    ]);

    avisar(
        $pdo,
        $destId,
        'Pedido de troca',
        $usuario['nome'] . ' pediu para você assumir a escala de '
            . $escala['evento_nome'] . ' em ' . dataBR($escala['data']) . '.'
    );

    if (function_exists('notificarUsuarioPorEmail')) {
        notificarUsuarioPorEmail(
            $pdo,
            $destId,
            'Nova solicitação de troca',
            'Você recebeu uma nova solicitação de troca de escala. Acesse o site para verificar as solicitações pendentes.'
        );
    }

    return resultado('sucesso', 'Pedido enviado. Você será avisado quando o colega responder.');
}

function recusarTroca(PDO $pdo, array $usuario, int $trocaId): array
{
    $t = carregarTroca($pdo, $trocaId);

    if (!$t || (int) $t['destinatario_id'] !== (int) $usuario['id'] || $t['status'] !== 'PENDENTE') {
        return resultado('erro', 'Pedido não encontrado ou já respondido.');
    }

    $pdo->prepare("UPDATE trocas SET status = 'RECUSADA', respondido_em = NOW() WHERE id = ?")
        ->execute([$trocaId]);

    registrarAuditoria($pdo, (int) $usuario['id'], 'TROCA_RECUSADA', 'trocas', $trocaId);

    avisar(
        $pdo,
        (int) $t['solicitante_id'],
        'Troca recusada',
        $usuario['nome'] . ' não poderá assumir a escala de ' . $t['evento_nome']
            . ' em ' . dataBR($t['data']) . '. A vaga continua com você.'
    );

    if (function_exists('notificarUsuarioPorEmail')) {
        notificarUsuarioPorEmail(
            $pdo,
            (int) $t['solicitante_id'],
            'Solicitação de troca recusada',
            'Sua solicitação de troca de escala foi recusada. Acesse o site para verificar os detalhes.'
        );
    }

    return resultado('sucesso', 'Pedido recusado.');
}

function aceitarTroca(PDO $pdo, array $usuario, int $trocaId): array
{
    $t = carregarTroca($pdo, $trocaId);

    if (!$t || (int) $t['destinatario_id'] !== (int) $usuario['id'] || $t['status'] !== 'PENDENTE') {
        return resultado('erro', 'Pedido não encontrado ou já respondido.');
    }

    $quando = $t['evento_nome'] . ' em ' . dataBR($t['data']);

    $motivo = validacaoRigida($pdo, $t);
    if ($motivo !== null) {
        encerrarTroca($pdo, $t, 'RECUSADA', [$motivo]);
        avisar($pdo, (int) $t['solicitante_id'], 'Pedido de troca cancelado',
            'O pedido de troca para ' . $quando . ' foi cancelado: ' . $motivo);

        if (function_exists('notificarUsuarioPorEmail')) {
            notificarUsuarioPorEmail(
                $pdo,
                (int) $t['solicitante_id'],
                'Solicitação de troca recusada',
                'Sua solicitação de troca de escala foi cancelada devido a um conflito nas regras. Acesse o site para verificar.'
            );
        }

        return resultado('erro', $motivo . ' O pedido foi cancelado.');
    }

    $erros = violacoes($pdo, (int) $usuario['id'], $t, (int) $t['departamento_id']);

    if (!$erros) {
        aplicarTroca($pdo, $t, 'ACEITA', null);

        registrarAuditoria($pdo, (int) $usuario['id'], 'TROCA_APLICADA', 'trocas', $trocaId, [
            'escala_id' => (int) $t['escala_id'],
            'de'        => (int) $t['solicitante_id'],
            'para'      => (int) $usuario['id'],
        ]);

        avisar($pdo, (int) $t['solicitante_id'], 'Troca concluída',
            $usuario['nome'] . ' aceitou e assumiu a escala de ' . $quando . '.');

        if (function_exists('notificarUsuarioPorEmail')) {
            notificarUsuarioPorEmail(
                $pdo,
                (int) $t['solicitante_id'],
                'Solicitação de troca aceita',
                'Sua solicitação de troca de escala foi aceita. Acesse o site para conferir a atualização da sua escala.'
            );
        }

        return resultado('sucesso', 'Troca aceita. Agora você está escalado(a) em ' . $quando . '.');
    }

    $pdo->prepare(
        "UPDATE trocas
            SET status = 'AGUARDANDO_LIDER', precisa_lider = 1,
                violacoes = ?, respondido_em = NOW()
          WHERE id = ?"
    )->execute([json_encode($erros, JSON_UNESCAPED_UNICODE), $trocaId]);

    registrarAuditoria($pdo, (int) $usuario['id'], 'TROCA_ENVIADA_AO_LIDER', 'trocas', $trocaId, [
        'violacoes' => $erros,
    ]);

    avisar($pdo, (int) $t['solicitante_id'], 'Troca aguardando o líder',
        $usuario['nome'] . ' aceitou, mas a troca de ' . $quando . ' precisa da aprovação do líder.');

    if (function_exists('notificarUsuarioPorEmail')) {
        notificarUsuarioPorEmail(
            $pdo,
            (int) $t['solicitante_id'],
            'Troca aguardando aprovação do líder',
            'O seu colega aceitou a troca, mas ela necessita da aprovação do líder. Acesse o site para acompanhar.'
        );
    }

    return resultado('sucesso', 'Você aceitou. Como a troca precisa da aprovação do líder, ela só será aplicada se ele aprovar.');
}

function decidirTroca(PDO $pdo, array $usuario, int $trocaId, bool $aprovar, array $depsLideradas): array
{
    $t = carregarTroca($pdo, $trocaId);

    if (!$t || $t['status'] !== 'AGUARDANDO_LIDER') {
        return resultado('erro', 'Pedido não encontrado ou já decidido.');
    }
    if (!podeAprovar($usuario, $t, $depsLideradas)) {
        return resultado('erro', 'Você não pode decidir este pedido.');
    }
    if ((int) $usuario['id'] === (int) $t['solicitante_id'] || (int) $usuario['id'] === (int) $t['destinatario_id']) {
        return resultado('erro', 'Você participa desta troca e não pode decidi-la. Peça a outro líder.');
    }

    $quando = $t['evento_nome'] . ' em ' . dataBR($t['data']);

    if (!$aprovar) {
        $pdo->prepare(
            "UPDATE trocas
                SET status = 'REJEITADA_PELO_LIDER', aprovado_por = ?, respondido_em = NOW()
              WHERE id = ?"
        )->execute([$usuario['id'], $trocaId]);

        registrarAuditoria($pdo, (int) $usuario['id'], 'TROCA_REJEITADA', 'trocas', $trocaId);

        foreach ([(int) $t['solicitante_id'], (int) $t['destinatario_id']] as $quem) {
            avisar($pdo, $quem, 'Troca rejeitada',
                'O líder rejeitou a troca de ' . $quando . '. A vaga continua com '
                . nomeUsuario($pdo, (int) $t['solicitante_id']) . '.');
        }

        if (function_exists('notificarUsuarioPorEmail')) {
            notificarUsuarioPorEmail(
                $pdo,
                (int) $t['solicitante_id'],
                'Solicitação de troca recusada pelo líder',
                'Sua solicitação de troca de escala foi recusada pelo líder. Acesse o site para verificar os detalhes.'
            );
        }

        return resultado('sucesso', 'Pedido rejeitado. A vaga continua com quem pediu.');
    }

    $motivo = validacaoRigida($pdo, $t);
    if ($motivo !== null) {
        return resultado('erro', $motivo . ' Não é possível aprovar; rejeite o pedido.');
    }

    aplicarTroca($pdo, $t, 'APROVADA_PELO_LIDER', (int) $usuario['id']);

    registrarAuditoria($pdo, (int) $usuario['id'], 'TROCA_APROVADA', 'trocas', $trocaId, [
        'escala_id' => (int) $t['escala_id'],
        'de'        => (int) $t['solicitante_id'],
        'para'      => (int) $t['destinatario_id'],
    ]);

    foreach ([(int) $t['solicitante_id'], (int) $t['destinatario_id']] as $quem) {
        avisar($pdo, $quem, 'Troca aprovada',
            'O líder aprovou a troca de ' . $quando . '. '
            . nomeUsuario($pdo, (int) $t['destinatario_id']) . ' assumiu a vaga.');

        if (function_exists('notificarUsuarioPorEmail')) {
            notificarUsuarioPorEmail(
                $pdo,
                $quem,
                'Solicitação de troca aprovada',
                'A solicitação de troca de escala foi aprovada pelo líder e atualizada no sistema. Acesse o site para conferir.'
            );
        }
    }

    return resultado('sucesso', 'Troca aprovada e aplicada.');
}

// ---------------------------------------------------------------
// Departamentos que este usuário lidera
// ---------------------------------------------------------------
$depsLideradas = [];
if ($usuario['perfil'] === 'LIDER') {
    $stmt = $pdo->prepare(
        'SELECT departamento_id FROM departamento_usuario WHERE usuario_id = ? AND e_lider = 1'
    );
    $stmt->execute([$usuarioId]);
    $depsLideradas = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ---------------------------------------------------------------
// POST
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();

    $acao     = $_POST['acao'] ?? '';
    $trocaId  = (int) ($_POST['troca_id'] ?? 0);
    $resposta = resultado('erro', 'Ação inválida.');

    if ($acao === 'solicitar') {
        $escalaId = (int) ($_POST['escala_id'] ?? 0);
        $destId   = (int) ($_POST['destinatario_id'] ?? 0);
        $resposta = emTransacao($pdo, fn() => solicitarTroca($pdo, $usuario, $escalaId, $destId));
    } elseif ($acao === 'aceitar') {
        $resposta = emTransacao($pdo, fn() => aceitarTroca($pdo, $usuario, $trocaId));
    } elseif ($acao === 'recusar') {
        $resposta = emTransacao($pdo, fn() => recusarTroca($pdo, $usuario, $trocaId));
    } elseif ($acao === 'aprovar' || $acao === 'rejeitar') {
        if ($ehLider) {
            $aprovar  = ($acao === 'aprovar');
            $resposta = emTransacao(
                $pdo,
                fn() => decidirTroca($pdo, $usuario, $trocaId, $aprovar, $depsLideradas)
            );
        } else {
            $resposta = resultado('erro', 'Você não tem permissão para isso.');
        }
    }

    $_SESSION['flash'] = $resposta;
    header('Location: trocas.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------------------------------------------------------------
// Dados da tela
// ---------------------------------------------------------------

$escalaIdForm = (int) ($_GET['escala'] ?? 0);
$escalaForm   = null;
$candidatos   = [];

if ($escalaIdForm > 0) {
    $stmt = $pdo->prepare(
        "SELECT es.id, es.departamento_id, es.evento_id, es.funcao,
                ev.nome AS evento, ev.data, ev.hora_inicio, ev.hora_fim,
                d.nome AS departamento
           FROM escalas es
           JOIN eventos ev      ON ev.id = es.evento_id
           JOIN departamentos d ON d.id  = es.departamento_id
          WHERE es.id = ? AND es.usuario_id = ?
            AND es.status IN ('ENVIADA', 'ACEITA')
            AND TIMESTAMP(ev.data, ev.hora_inicio) > NOW()"
    );
    $stmt->execute([$escalaIdForm, $usuarioId]);
    $escalaForm = $stmt->fetch() ?: null;

    if ($escalaForm) {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.nome, du.funcao
               FROM departamento_usuario du
               JOIN usuarios u ON u.id = du.usuario_id
              WHERE du.departamento_id = ? AND u.ativo = 1 AND u.id <> ?
                AND NOT EXISTS (
                    SELECT 1 FROM escalas e2
                     WHERE e2.evento_id = ? AND e2.usuario_id = u.id)
              ORDER BY u.nome'
        );
        $stmt->execute([$escalaForm['departamento_id'], $usuarioId, $escalaForm['evento_id']]);
        $candidatos = $stmt->fetchAll();
    }
}

$sqlLista = 'SELECT t.id, t.status, t.violacoes, t.criado_em,
                    es.funcao, es.departamento_id,
                    ev.nome AS evento, ev.data, ev.hora_inicio, ev.hora_fim, ev.igreja_id,
                    d.nome AS departamento,
                    t.solicitante_id, t.destinatario_id,
                    us.nome AS solicitante, ud.nome AS destinatario
               FROM trocas t
               JOIN escalas es      ON es.id = t.escala_id
               JOIN eventos ev      ON ev.id = es.evento_id
               JOIN departamentos d ON d.id  = es.departamento_id
               JOIN usuarios us     ON us.id = t.solicitante_id
               JOIN usuarios ud     ON ud.id = t.destinatario_id';

$stmt = $pdo->prepare(
    $sqlLista . " WHERE t.destinatario_id = ? AND t.status = 'PENDENTE'
                    AND (t.validade IS NULL OR t.validade > NOW())
                  ORDER BY ev.data, ev.hora_inicio"
);
$stmt->execute([$usuarioId]);
$recebidos = $stmt->fetchAll();

$stmt = $pdo->prepare($sqlLista . ' WHERE t.solicitante_id = ? ORDER BY t.id DESC LIMIT 20');
$stmt->execute([$usuarioId]);
$meusPedidos = $stmt->fetchAll();

$paraAprovar = [];
if ($ehLider) {
    $stmt = $pdo->query(
        $sqlLista . " WHERE t.status = 'AGUARDANDO_LIDER'
                        AND TIMESTAMP(ev.data, ev.hora_inicio) > NOW()
                      ORDER BY ev.data, ev.hora_inicio"
    );
    foreach ($stmt->fetchAll() as $linha) {
        if (podeAprovar($usuario, $linha, $depsLideradas)) {
            $paraAprovar[] = $linha;
        }
    }
}

$rotuloStatus = [
    'PENDENTE'             => ['Aguardando resposta',  'warning'],
    'ACEITA'               => ['Troca concluída',      'success'],
    'RECUSADA'             => ['Recusada',             'secondary'],
    'AGUARDANDO_LIDER'     => ['Aguardando o líder',   'info'],
    'APROVADA_PELO_LIDER'  => ['Aprovada pelo líder',  'success'],
    'REJEITADA_PELO_LIDER' => ['Rejeitada pelo líder', 'danger'],
];

$titulo = 'Trocas';
include __DIR__ . '/includes/topo.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Trocas</h1>
    <a href="minhas_escalas.php" class="btn btn-outline-secondary btn-sm">Minhas escalas</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['tipo'] === 'sucesso' ? 'success' : 'danger' ?>">
        <?= e($flash['texto']) ?>
    </div>
<?php endif; ?>

<?php if ($escalaIdForm > 0): ?>
    <?php if (!$escalaForm): ?>
        <div class="alert alert-warning">
            Esta escala não existe, não é sua ou não pode mais ser trocada.
        </div>
    <?php else: ?>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5">Pedir troca</h2>
                <p class="mb-3">
                    <strong><?= e($escalaForm['evento']) ?></strong>,
                    <?= e(dataBR($escalaForm['data'])) ?>,
                    <?= e(substr($escalaForm['hora_inicio'], 0, 5)) ?> às
                    <?= e(substr($escalaForm['hora_fim'], 0, 5)) ?><br>
                    <small class="text-muted">
                        <?= e($escalaForm['departamento']) ?>
                        <?= !empty($escalaForm['funcao']) ? ' · ' . e($escalaForm['funcao']) : '' ?>
                    </small>
                </p>

                <?php if (!$candidatos): ?>
                    <p class="text-muted mb-0">
                        Não há colegas disponíveis neste departamento para assumir esta vaga.
                    </p>
                <?php else: ?>
                    <form method="POST" class="row g-2">
                        <?= csrfCampo() ?>
                        <input type="hidden" name="acao" value="solicitar">
                        <input type="hidden" name="escala_id" value="<?= (int) $escalaForm['id'] ?>">

                        <div class="col-12 col-md-6">
                            <select name="destinatario_id" class="form-select" required>
                                <option value="">Quem vai assumir a vaga?</option>
                                <?php foreach ($candidatos as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>">
                                        <?= e($c['nome']) ?><?= !empty($c['funcao']) ? ' (' . e($c['funcao']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">Enviar pedido</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($ehLider): ?>
    <h2 class="h5 mt-4">Aguardando sua aprovação</h2>

    <?php if (!$paraAprovar): ?>
        <p class="text-muted">Nenhuma troca esperando decisão.</p>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($paraAprovar as $p): ?>
                <?php $motivos = json_decode($p['violacoes'] ?? '[]', true) ?: []; ?>
                <div class="col-12 col-md-6">
                    <div class="card h-100 border-info">
                        <div class="card-body">
                            <h3 class="h6 mb-1"><?= e($p['evento']) ?> · <?= e(dataBR($p['data'])) ?></h3>
                            <p class="small text-muted mb-2"><?= e($p['departamento']) ?></p>
                            <p class="mb-2">
                                <strong><?= e($p['solicitante']) ?></strong> sai e
                                <strong><?= e($p['destinatario']) ?></strong> assume.
                            </p>
                            <p class="mb-1 small">A troca viola:</p>
                            <ul class="small">
                                <?php foreach ($motivos as $motivo): ?>
                                    <li><?= e($motivo) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="d-flex gap-2">
                                <form method="POST">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="acao" value="aprovar">
                                    <input type="hidden" name="troca_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm"
                                            data-confirma="Aprovar a troca mesmo violando as regras?">Aprovar</button>
                                </form>
                                <form method="POST">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="acao" value="rejeitar">
                                    <input type="hidden" name="troca_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"
                                            data-confirma="Rejeitar esta troca?">Rejeitar</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<h2 class="h5 mt-4">Pedidos recebidos</h2>

<?php if (!$recebidos): ?>
    <p class="text-muted">Ninguém pediu para você assumir uma escala.</p>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($recebidos as $r): ?>
            <div class="col-12 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h3 class="h6 mb-1"><?= e($r['evento']) ?></h3>
                        <p class="mb-1">
                            <strong><?= e(dataBR($r['data'])) ?></strong>,
                            <?= e(substr($r['hora_inicio'], 0, 5)) ?> às
                            <?= e(substr($r['hora_fim'], 0, 5)) ?>
                        </p>
                        <p class="small text-muted mb-3">
                            <?= e($r['departamento']) ?>
                            <?= !empty($r['funcao']) ? ' · ' . e($r['funcao']) : '' ?><br>
                            <?= e($r['solicitante']) ?> pediu para você assumir esta vaga.
                        </p>
                        <div class="d-flex gap-2">
                            <form method="POST">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="acao" value="aceitar">
                                <input type="hidden" name="troca_id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm">Aceitar</button>
                            </form>
                            <form method="POST">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="acao" value="recusar">
                                <input type="hidden" name="troca_id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-outline-secondary btn-sm"
                                        data-confirma="Recusar este pedido?">Recusar</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="h5 mt-4">Meus pedidos</h2>

<?php if (!$meusPedidos): ?>
    <p class="text-muted">Você ainda não pediu nenhuma troca.</p>
<?php else: ?>
    <ul class="list-group">
        <?php foreach ($meusPedidos as $m): ?>
            <?php $rotulo = $rotuloStatus[$m['status']] ?? [$m['status'], 'secondary']; ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>
                    <?= e($m['evento']) ?> · <?= e(dataBR($m['data'])) ?>
                    <small class="text-muted">· para <?= e($m['destinatario']) ?></small>
                </span>
                <span class="badge bg-<?= e($rotulo[1]) ?>"><?= e($rotulo[0]) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php include __DIR__ . '/includes/rodape.php'; ?>