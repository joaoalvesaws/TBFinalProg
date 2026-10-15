<?php
// Tela do líder: monta a escala de UM departamento para UM evento.
//
// Fluxo: escolhe o departamento, escolhe o evento, adiciona pessoas
// (cada uma validada pelo regras.php), remove se precisar e publica.
//
// Depende de:
//   includes/sessao.php     (login, perfil, CSRF)
//   regras.php              (função violacoes)
//   includes/auditoria.php  (função registrarAuditoria)
//   includes/topo.php e includes/rodape.php (layout)

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/regras.php';
require __DIR__ . '/includes/auditoria.php';

$usuario = exigirPerfil('LIDER');

// ---------------------------------------------------------------
// Funções auxiliares da tela
// ---------------------------------------------------------------

function dataBR(string $data): string
{
    return date('d/m/Y', strtotime($data));
}

// Guarda uma mensagem, recarrega a página e para o script.
// (Evita reenviar o formulário se o usuário apertar F5.)
function voltar(int $depId, int $eventoId, string $tipo, array $mensagens): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensagens' => $mensagens];

    $url = 'lider_escala.php';
    if ($depId) {
        $url .= '?dep=' . $depId . ($eventoId ? '&evento=' . $eventoId : '');
    }
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------
// 1) Departamentos que este usuário pode gerenciar
//    Líder: só os que ele lidera. Admin da igreja: os da igreja dele. (tomando por exemplo uma igreja)
//    Admin geral: todos.
// ---------------------------------------------------------------
$sqlBase = 'SELECT d.id, d.nome, d.igreja_id, i.nome AS igreja_nome
              FROM departamentos d
              JOIN igrejas i ON i.id = d.igreja_id';

if ($usuario['perfil'] === 'LIDER') {
    $stmt = $pdo->prepare(
        $sqlBase . ' JOIN departamento_usuario du ON du.departamento_id = d.id
                     WHERE du.usuario_id = ? AND du.e_lider = 1
                     ORDER BY d.nome'
    );
    $stmt->execute([$usuario['id']]);
} elseif ($usuario['perfil'] === 'ADMIN_IGREJA') {
    $stmt = $pdo->prepare($sqlBase . ' WHERE d.igreja_id = ? ORDER BY d.nome');
    $stmt->execute([$usuario['igreja_id']]);
} else {
    $stmt = $pdo->query($sqlBase . ' ORDER BY i.nome, d.nome');
}

$departamentos = [];
foreach ($stmt->fetchAll() as $d) {
    $departamentos[(int) $d['id']] = $d;
}

// ---------------------------------------------------------------
// 2) Departamento e evento escolhidos (sempre conferidos no servidor)
// ---------------------------------------------------------------
$depId = (int) ($_POST['dep'] ?? $_GET['dep'] ?? 0);

if ($depId === 0 && count($departamentos) === 1) {
    $depId = (int) array_key_first($departamentos);
}

$dep = $departamentos[$depId] ?? null;

if ($depId !== 0 && ($dep === null || !podeAcessarIgreja($usuario, (int) $dep['igreja_id']))) {
    http_response_code(403);
    exit('Você não gerencia este departamento.');
}

$eventoId = (int) ($_POST['evento'] ?? $_GET['evento'] ?? 0);
$evento = null;

if ($dep && $eventoId) {
    // O evento precisa ser da mesma igreja do departamento
    $stmt = $pdo->prepare('SELECT * FROM eventos WHERE id = ? AND igreja_id = ?');
    $stmt->execute([$eventoId, $dep['igreja_id']]);
    $evento = $stmt->fetch() ?: null;

    if ($evento === null) {
        http_response_code(404);
        exit('Evento não encontrado.');
    }
}

// ---------------------------------------------------------------
// 3) Ações (POST): adicionar, remover, publicar
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();

    if ($dep === null || $evento === null) {
        voltar($depId, 0, 'erro', ['Escolha um departamento e um evento.']);
    }

    if ($evento['data'] < date('Y-m-d')) {
        voltar($depId, $eventoId, 'erro', ['Este evento já passou e não pode mais ser alterado.']);
    }

    $acao = $_POST['acao'] ?? '';

    // ----- Adicionar uma pessoa à escala -----
    if ($acao === 'adicionar') {
        $voluntarioId = (int) ($_POST['usuario_id'] ?? 0);
        $funcao = trim((string) ($_POST['funcao'] ?? ''));

        // A pessoa precisa ser membro ativo deste departamento
        $stmt = $pdo->prepare(
            'SELECT u.id, u.nome, du.funcao
               FROM departamento_usuario du
               JOIN usuarios u ON u.id = du.usuario_id
              WHERE du.departamento_id = ? AND du.usuario_id = ? AND u.ativo = 1'
        );
        $stmt->execute([$depId, $voluntarioId]);
        $membro = $stmt->fetch();

        if (!$membro) {
            voltar($depId, $eventoId, 'erro', ['Essa pessoa não faz parte deste departamento.']);
        }

        if ($funcao === '') {
            $funcao = (string) ($membro['funcao'] ?? '');
        }
        $funcao = mb_substr($funcao, 0, 80);

        // Todas as regras de escala ficam no regras.php
        $erros = violacoes($pdo, $voluntarioId, $evento, $depId);
        if ($erros) {
            $mensagens = [];
            foreach ($erros as $erro) {
                $mensagens[] = $membro['nome'] . ': ' . $erro;
            }
            voltar($depId, $eventoId, 'erro', $mensagens);
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO escalas (evento_id, departamento_id, usuario_id, funcao, status)
                 VALUES (?, ?, ?, ?, 'RASCUNHO')"
            )->execute([$eventoId, $depId, $voluntarioId, $funcao !== '' ? $funcao : null]);
            $novoId = (int) $pdo->lastInsertId();

            registrarAuditoria($pdo, (int) $usuario['id'], 'ESCALA_ADICIONADA', 'escalas', $novoId, [
                'evento_id'       => $eventoId,
                'departamento_id' => $depId,
                'usuario_id'      => $voluntarioId,
                'funcao'          => $funcao,
            ]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') { // chave única: já escalado neste evento
                voltar($depId, $eventoId, 'erro', [$membro['nome'] . ' já está escalado(a) neste evento.']);
            }
            throw $e;
        }

        voltar($depId, $eventoId, 'sucesso', [$membro['nome'] . ' foi adicionado(a) como rascunho.']);
    }

    // ----- Remover uma pessoa da escala -----
    if ($acao === 'remover') {
        $escalaId = (int) ($_POST['escala_id'] ?? 0);

        // A linha precisa ser deste evento E deste departamento
        $stmt = $pdo->prepare(
            'SELECT * FROM escalas WHERE id = ? AND evento_id = ? AND departamento_id = ?'
        );
        $stmt->execute([$escalaId, $eventoId, $depId]);
        $linha = $stmt->fetch();

        if (!$linha) {
            voltar($depId, $eventoId, 'erro', ['Item da escala não encontrado.']);
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare('DELETE FROM escalas WHERE id = ?')->execute([$escalaId]);

            registrarAuditoria($pdo, (int) $usuario['id'], 'ESCALA_REMOVIDA', 'escalas', $escalaId, [
                'evento_id'       => $eventoId,
                'departamento_id' => $depId,
                'usuario_id'      => (int) $linha['usuario_id'],
                'status_anterior' => $linha['status'],
            ]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') { // existe pedido de troca ligado a esta vaga
                voltar($depId, $eventoId, 'erro', ['Esta vaga tem um pedido de troca registrado e não pode ser removida.']);
            }
            throw $e;
        }

        voltar($depId, $eventoId, 'sucesso', ['Pessoa removida da escala.']);
    }

    // ----- Publicar (RASCUNHO vira ENVIADA) -----
    if ($acao === 'publicar') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "UPDATE escalas SET status = 'ENVIADA'
                  WHERE evento_id = ? AND departamento_id = ? AND status = 'RASCUNHO'"
            );
            $stmt->execute([$eventoId, $depId]);
            $total = $stmt->rowCount();

            if ($total > 0) {
                registrarAuditoria($pdo, (int) $usuario['id'], 'ESCALA_PUBLICADA', 'eventos', $eventoId, [
                    'departamento_id' => $depId,
                    'quantidade'      => $total,
                ]);
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($total === 0) {
            voltar($depId, $eventoId, 'erro', ['Não há ninguém em rascunho para publicar.']);
        }
        voltar($depId, $eventoId, 'sucesso', ["Escala publicada ($total pessoa(s))."]);
    }

    voltar($depId, $eventoId, 'erro', ['Ação inválida.']);
}

// ---------------------------------------------------------------
// 4) Dados para mostrar na tela
// ---------------------------------------------------------------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$eventos = [];
$escalados = [];
$disponiveis = [];

if ($dep) {
    // Próximos eventos da igreja, com quantas pessoas deste departamento já estão escaladas
    $stmt = $pdo->prepare(
        'SELECT ev.id, ev.nome, ev.data, ev.hora_inicio, ev.hora_fim,
                (SELECT COUNT(*) FROM escalas es
                  WHERE es.evento_id = ev.id AND es.departamento_id = ?) AS total
           FROM eventos ev
          WHERE ev.igreja_id = ? AND ev.data >= CURDATE()
          ORDER BY ev.data, ev.hora_inicio
          LIMIT 60'
    );
    $stmt->execute([$depId, $dep['igreja_id']]);
    $eventos = $stmt->fetchAll();
}

if ($dep && $evento) {
    // Quem já está na escala deste evento (neste departamento)
    $stmt = $pdo->prepare(
        'SELECT es.id, es.funcao, es.status, u.nome
           FROM escalas es
           JOIN usuarios u ON u.id = es.usuario_id
          WHERE es.evento_id = ? AND es.departamento_id = ?
          ORDER BY u.nome'
    );
    $stmt->execute([$eventoId, $depId]);
    $escalados = $stmt->fetchAll();

    // Membros do departamento que ainda não estão escalados neste evento
    $stmt = $pdo->prepare(
        'SELECT u.id, u.nome, du.funcao
           FROM departamento_usuario du
           JOIN usuarios u ON u.id = du.usuario_id
          WHERE du.departamento_id = ? AND u.ativo = 1
            AND NOT EXISTS (
                SELECT 1 FROM escalas e2
                 WHERE e2.evento_id = ? AND e2.usuario_id = u.id)
          ORDER BY u.nome'
    );
    $stmt->execute([$depId, $eventoId]);
    $disponiveis = $stmt->fetchAll();
}

$temRascunho = false;
foreach ($escalados as $linha) {
    if ($linha['status'] === 'RASCUNHO') {
        $temRascunho = true;
    }
}

$classeStatus = [
    'RASCUNHO' => 'secondary',
    'ENVIADA'  => 'primary',
    'ACEITA'   => 'success',
];

$titulo = 'Montar escala';
include __DIR__ . '/includes/topo.php';
?>

<h1 class="h3 mb-3">Montar escala</h1>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['tipo'] === 'sucesso' ? 'success' : 'danger' ?>">
        <?php foreach ($flash['mensagens'] as $mensagem): ?>
            <div><?= e($mensagem) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$departamentos): ?>

    <div class="alert alert-warning">
        Você ainda não é líder de nenhum departamento. Peça ao administrador para
        marcar você como líder.
    </div>

<?php else: ?>

    <form method="GET" class="row g-2 mb-4">
        <div class="col-12 col-md-6">
            <select name="dep" class="form-select" required>
                <option value="">Escolha o departamento</option>
                <?php foreach ($departamentos as $d): ?>
                    <option value="<?= (int) $d['id'] ?>" <?= $dep && (int) $d['id'] === $depId ? 'selected' : '' ?>>
                        <?= e($d['nome']) ?> (<?= e($d['igreja_nome']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary">Ver eventos</button>
        </div>
    </form>

    <?php if ($dep): ?>
        <div class="row g-4">

            <!-- Coluna 1: eventos -->
            <div class="col-12 col-lg-4">
                <h2 class="h5">Próximos eventos</h2>

                <?php if (!$eventos): ?>
                    <p class="text-muted">Nenhum evento futuro cadastrado para esta igreja.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($eventos as $ev): ?>
                            <a class="list-group-item list-group-item-action <?= $evento && (int) $ev['id'] === $eventoId ? 'active' : '' ?>"
                               href="lider_escala.php?dep=<?= $depId ?>&evento=<?= (int) $ev['id'] ?>">
                                <div class="d-flex justify-content-between">
                                    <strong><?= e($ev['nome']) ?></strong>
                                    <span class="badge bg-light text-dark"><?= (int) $ev['total'] ?></span>
                                </div>
                                <small>
                                    <?= e(dataBR($ev['data'])) ?>,
                                    <?= e(substr($ev['hora_inicio'], 0, 5)) ?> às
                                    <?= e(substr($ev['hora_fim'], 0, 5)) ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Coluna 2: escala do evento escolhido -->
            <div class="col-12 col-lg-8">
                <?php if (!$evento): ?>
                    <p class="text-muted">Escolha um evento ao lado para montar a escala.</p>
                <?php else: ?>
                    <h2 class="h5">
                        <?= e($evento['nome']) ?>
                        <small class="text-muted">
                            em <?= e(dataBR($evento['data'])) ?>,
                            <?= e(substr($evento['hora_inicio'], 0, 5)) ?>
                        </small>
                    </h2>

                    <!-- Quem já está escalado -->
                    <?php if (!$escalados): ?>
                        <p class="text-muted">Ninguém escalado ainda.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr><th>Nome</th><th>Função</th><th>Situação</th><th></th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($escalados as $linha): ?>
                                    <tr>
                                        <td><?= e($linha['nome']) ?></td>
                                        <td><?= e($linha['funcao'] ?? '') ?></td>
                                        <td>
                                            <span class="badge bg-<?= e($classeStatus[$linha['status']] ?? 'secondary') ?>">
                                                <?= e($linha['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline">
                                                <?= csrfCampo() ?>
                                                <input type="hidden" name="acao" value="remover">
                                                <input type="hidden" name="dep" value="<?= $depId ?>">
                                                <input type="hidden" name="evento" value="<?= $eventoId ?>">
                                                <input type="hidden" name="escala_id" value="<?= (int) $linha['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        data-confirma="Remover <?= e($linha['nome']) ?> da escala?">
                                                    Remover
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <!-- Adicionar pessoa -->
                    <h3 class="h6 mt-4">Adicionar pessoa</h3>
                    <?php if (!$disponiveis): ?>
                        <p class="text-muted">Todos os membros do departamento já estão escalados neste evento.</p>
                    <?php else: ?>
                        <form method="POST" class="row g-2">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="acao" value="adicionar">
                            <input type="hidden" name="dep" value="<?= $depId ?>">
                            <input type="hidden" name="evento" value="<?= $eventoId ?>">

                            <div class="col-12 col-md-5">
                                <select name="usuario_id" class="form-select" required>
                                    <option value="">Escolha a pessoa</option>
                                    <?php foreach ($disponiveis as $m): ?>
                                        <option value="<?= (int) $m['id'] ?>">
                                            <?= e($m['nome']) ?><?= !empty($m['funcao']) ? ' (' . e($m['funcao']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <input type="text" name="funcao" class="form-control" maxlength="80"
                                       placeholder="Função (vazio = a padrão da pessoa)">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">Adicionar</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <!-- Publicar -->
                    <?php if ($temRascunho): ?>
                        <form method="POST" class="mt-4">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="acao" value="publicar">
                            <input type="hidden" name="dep" value="<?= $depId ?>">
                            <input type="hidden" name="evento" value="<?= $eventoId ?>">
                            <button type="submit" class="btn btn-success"
                                    data-confirma="Publicar a escala para os voluntários?">
                                Publicar escala
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/includes/rodape.php'; ?>