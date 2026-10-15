<?php
// Tela do voluntário: mostra onde ele está escalado e permite confirmar presença.
//
// O voluntário só vê escalas PUBLICADAS (ENVIADA ou ACEITA).
// Rascunhos do líder nunca aparecem aqui.
//
// Depende de:
//   includes/sessao.php     (login e CSRF)
//   includes/auditoria.php  (função registrarAuditoria)
//   includes/topo.php e includes/rodape.php (layout)

require __DIR__ . '/includes/sessao.php';


$usuario = exigirLogin();

$diasSemana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira',
               'quinta-feira', 'sexta-feira', 'sábado'];

function dataBR(string $data): string
{
    return date('d/m/Y', strtotime($data));
}

// ---------------------------------------------------------------
// Ação (POST): confirmar presença
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();

    $escalaId = (int) ($_POST['escala_id'] ?? 0);
    $mensagem = ['tipo' => 'erro', 'texto' => 'Ação inválida.'];

    if (($_POST['acao'] ?? '') === 'confirmar') {
        try {
            $pdo->beginTransaction();

            // Só confirma a própria escala, publicada e de evento que ainda não terminou
            $stmt = $pdo->prepare(
                "UPDATE escalas es
                   JOIN eventos ev ON ev.id = es.evento_id
                    SET es.status = 'ACEITA'
                  WHERE es.id = ?
                    AND es.usuario_id = ?
                    AND es.status = 'ENVIADA'
                    AND TIMESTAMP(ev.data, ev.hora_fim) >= NOW()"
            );
            $stmt->execute([$escalaId, $usuario['id']]);

            if ($stmt->rowCount() === 1) {
                registrarAuditoria($pdo, (int) $usuario['id'], 'ESCALA_CONFIRMADA', 'escalas', $escalaId);
                $mensagem = ['tipo' => 'sucesso', 'texto' => 'Presença confirmada. Obrigado!'];
            } else {
                $mensagem = ['tipo' => 'erro', 'texto' => 'Não foi possível confirmar esta escala.'];
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // Recarrega a página para não reenviar o formulário com F5
    $_SESSION['flash'] = $mensagem;
    header('Location: minhas_escalas.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------------------------------------------------------------
// Dados da tela
// ---------------------------------------------------------------
$sqlBase = "SELECT es.id, es.funcao, es.status,
                   ev.nome AS evento, ev.data, ev.hora_inicio, ev.hora_fim,
                   d.nome AS departamento,
                   i.nome AS igreja,
                   (SELECT COUNT(*) FROM trocas t
                     WHERE t.escala_id = es.id
                       AND t.status IN ('PENDENTE', 'AGUARDANDO_LIDER')) AS trocas_abertas
              FROM escalas es
              JOIN eventos ev      ON ev.id = es.evento_id
              JOIN departamentos d ON d.id  = es.departamento_id
              JOIN igrejas i       ON i.id  = ev.igreja_id
             WHERE es.usuario_id = ?
               AND es.status IN ('ENVIADA', 'ACEITA')";

// Próximas
$stmt = $pdo->prepare(
    $sqlBase . ' AND TIMESTAMP(ev.data, ev.hora_fim) >= NOW()
                 ORDER BY ev.data, ev.hora_inicio'
);
$stmt->execute([$usuario['id']]);
$proximas = $stmt->fetchAll();

// Anteriores (as 10 mais recentes)
$stmt = $pdo->prepare(
    $sqlBase . ' AND TIMESTAMP(ev.data, ev.hora_fim) < NOW()
                 ORDER BY ev.data DESC, ev.hora_inicio DESC
                 LIMIT 10'
);
$stmt->execute([$usuario['id']]);
$anteriores = $stmt->fetchAll();

$rotuloStatus = [
    'ENVIADA' => ['texto' => 'Aguardando sua confirmação', 'classe' => 'warning'],
    'ACEITA'  => ['texto' => 'Confirmada',                 'classe' => 'success'],
];

$titulo = 'Minhas escalas';
include __DIR__ . '/includes/topo.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Minhas escalas</h1>
    <a href="bloqueios.php" class="btn btn-outline-secondary btn-sm">Bloquear datas</a>
</div>

<p class="text-muted">Olá, <?= e($usuario['nome']) ?>.</p>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['tipo'] === 'sucesso' ? 'success' : 'danger' ?>">
        <?= e($flash['texto']) ?>
    </div>
<?php endif; ?>

<h2 class="h5 mt-4">Próximas</h2>

<?php if (!$proximas): ?>
    <p class="text-muted">Você não tem escalas publicadas para os próximos dias.</p>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($proximas as $es): ?>
            <?php
            $rotulo = $rotuloStatus[$es['status']] ?? ['texto' => $es['status'], 'classe' => 'secondary'];
            $diaSemana = $diasSemana[(int) date('w', strtotime($es['data']))];
            ?>
            <div class="col-12 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <h3 class="h6 mb-1"><?= e($es['evento']) ?></h3>
                            <span class="badge bg-<?= e($rotulo['classe']) ?>"><?= e($rotulo['texto']) ?></span>
                        </div>

                        <p class="mb-1">
                            <strong><?= e(dataBR($es['data'])) ?></strong>
                            (<?= e($diaSemana) ?>),
                            <?= e(substr($es['hora_inicio'], 0, 5)) ?> às
                            <?= e(substr($es['hora_fim'], 0, 5)) ?>
                        </p>

                        <p class="mb-3 text-muted small">
                            <?= e($es['departamento']) ?>
                            <?= !empty($es['funcao']) ? ' · ' . e($es['funcao']) : '' ?>
                            · <?= e($es['igreja']) ?>
                        </p>

                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($es['status'] === 'ENVIADA'): ?>
                                <form method="POST" class="d-inline">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="acao" value="confirmar">
                                    <input type="hidden" name="escala_id" value="<?= (int) $es['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm">Confirmar presença</button>
                                </form>
                            <?php endif; ?>

                            <?php if ((int) $es['trocas_abertas'] > 0): ?>
                                <span class="badge bg-info text-dark align-self-center">Troca em andamento</span>
                            <?php else: ?>
                                <a href="trocas.php?escala=<?= (int) $es['id'] ?>"
                                   class="btn btn-outline-primary btn-sm">Pedir troca</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($anteriores): ?>
    <h2 class="h5 mt-5">Anteriores</h2>
    <ul class="list-group">
        <?php foreach ($anteriores as $es): ?>
            <li class="list-group-item d-flex justify-content-between">
                <span>
                    <?= e($es['evento']) ?>
                    <small class="text-muted">· <?= e($es['departamento']) ?></small>
                </span>
                <small class="text-muted"><?= e(dataBR($es['data'])) ?></small>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php include __DIR__ . '/includes/rodape.php'; ?>