<?php
// src/bloqueios.php
// O voluntário informa os dias em que NÃO pode servir (férias, viagem, compromissos).
// LGPD: guarda só o período, nunca o motivo.
// O regras.php e o gerador de IA respeitam estes bloqueios ao escalar.
//
// Depende de: includes/sessao.php, includes/util.php (post, flash, redirecionar,
//   dataValida), includes/topo.php e includes/rodape.php.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';

$usuario   = exigirLogin();
$usuarioId = (int) $usuario['id'];

const MAX_DIAS_BLOQUEIO     = 366;   // maior período de um único bloqueio
const MAX_BLOQUEIOS_ATIVOS  = 50;    // bloqueios atuais ou futuros por pessoa

$fmt = fn(string $data): string => date('d/m/Y', strtotime($data));

// ---------------------------------------------------------------
// Ações (todas por POST com token CSRF)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    $acao = post('acao');

    // ---------- Criar um bloqueio ----------
    if ($acao === 'criar') {
        $inicio = post('data_inicio');
        $fim    = post('data_fim');
        $hoje   = date('Y-m-d');
        $erros  = [];

        if (!dataValida($inicio) || !dataValida($fim)) {
            $erros[] = 'Informe as datas de início e de fim.';
        } elseif ($fim < $hoje) {
            $erros[] = 'A data final não pode estar no passado.';
        } elseif ($fim < $inicio) {
            $erros[] = 'A data final não pode ser anterior à inicial.';
        } elseif ((new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($fim))->days > MAX_DIAS_BLOQUEIO) {
            $erros[] = 'Um bloqueio pode ter no máximo ' . MAX_DIAS_BLOQUEIO . ' dias.';
        }

        if (!$erros) {
            $s = $pdo->prepare('SELECT COUNT(*) FROM bloqueios WHERE usuario_id = ? AND data_fim >= CURDATE()');
            $s->execute([$usuarioId]);
            if ((int) $s->fetchColumn() >= MAX_BLOQUEIOS_ATIVOS) {
                $erros[] = 'Você atingiu o limite de ' . MAX_BLOQUEIOS_ATIVOS
                         . ' bloqueios. Remova os que não valem mais.';
            }

            // Já existe um bloqueio que cobre todo este período?
            $s = $pdo->prepare(
                'SELECT 1 FROM bloqueios
                  WHERE usuario_id = ? AND data_inicio <= ? AND data_fim >= ? LIMIT 1'
            );
            $s->execute([$usuarioId, $inicio, $fim]);
            if ($s->fetchColumn()) {
                $erros[] = 'Este período já está bloqueado.';
            }
        }

        if ($erros) {
            foreach ($erros as $mensagem) {
                flash('danger', $mensagem);
            }
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO bloqueios (usuario_id, data_inicio, data_fim) VALUES (?, ?, ?)'
                )->execute([$usuarioId, $inicio, $fim]);

                flash('success', 'Bloqueio salvo: de ' . $fmt($inicio) . ' a ' . $fmt($fim) . '.');

                // O bloqueio vale para escalas FUTURAS. Escalas já publicadas continuam.
                $s = $pdo->prepare(
                    "SELECT COUNT(*)
                       FROM escalas es
                       JOIN eventos ev ON ev.id = es.evento_id
                      WHERE es.usuario_id = ?
                        AND es.status IN ('ENVIADA', 'ACEITA')
                        AND ev.data BETWEEN ? AND ?
                        AND TIMESTAMP(ev.data, ev.hora_fim) >= NOW()"
                );
                $s->execute([$usuarioId, $inicio, $fim]);
                $jaEscalado = (int) $s->fetchColumn();

                if ($jaEscalado > 0) {
                    flash('warning', 'Atenção: você já está escalado(a) em ' . $jaEscalado
                        . ' evento(s) nesse período. O bloqueio não cancela escalas que já existem: '
                        . 'peça a troca em "Trocas".');
                }
            } catch (PDOException $ex) {
                error_log('Erro ao salvar bloqueio: ' . $ex->getMessage());
                flash('danger', 'Não foi possível salvar. Tente novamente.');
            }
        }

    // ---------- Remover um bloqueio (só os da própria pessoa) ----------
    } elseif ($acao === 'excluir') {
        $stmt = $pdo->prepare('DELETE FROM bloqueios WHERE id = ? AND usuario_id = ?');
        $stmt->execute([(int) post('id'), $usuarioId]);

        if ($stmt->rowCount() === 1) {
            flash('success', 'Bloqueio removido.');
        } else {
            flash('danger', 'Bloqueio não encontrado.');
        }
    }

    redirecionar('/bloqueios.php');
    exit;
}

// ---------------------------------------------------------------
// Dados da tela
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT id, data_inicio, data_fim FROM bloqueios
      WHERE usuario_id = ? AND data_fim >= CURDATE()
      ORDER BY data_inicio'
);
$stmt->execute([$usuarioId]);
$ativos = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT id, data_inicio, data_fim FROM bloqueios
      WHERE usuario_id = ? AND data_fim < CURDATE()
      ORDER BY data_fim DESC
      LIMIT 10'
);
$stmt->execute([$usuarioId]);
$passados = $stmt->fetchAll();

$tituloPagina = 'Meus bloqueios';
include __DIR__ . '/includes/topo.php';
?>

<h1 class="h3 mb-3">Meus bloqueios</h1>

<div class="row g-4">

    <!-- Novo bloqueio -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h2 class="h5">Bloquear dias</h2>
                <p class="small text-muted">
                    Marque os dias em que você <strong>não pode</strong> servir. Não precisa
                    dizer o motivo: o sistema guarda só as datas e não escala você nesses dias.
                </p>

                <form method="POST" data-intervalo-datas>
                    <?= csrfCampo() ?>
                    <input type="hidden" name="acao" value="criar">

                    <div class="mb-3">
                        <label for="data_inicio" class="form-label">De</label>
                        <input type="date" class="form-control" id="data_inicio" name="data_inicio"
                               min="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="data_fim" class="form-label">Até</label>
                        <input type="date" class="form-control" id="data_fim" name="data_fim"
                               min="<?= e(date('Y-m-d')) ?>" required>
                        <div class="form-text">Para um dia só, use a mesma data nos dois campos.</div>
                    </div>

                    <button type="submit" class="btn btn-primary" data-enviando="Salvando...">Bloquear</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Lista -->
    <div class="col-lg-8">
        <h2 class="h5">Bloqueios atuais e futuros</h2>

        <?php if (!$ativos): ?>
            <p class="text-muted">Você não tem nenhum bloqueio.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr><th>Período</th><th>Dias</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ativos as $b): ?>
                        <?php
                        $dias = (new DateTimeImmutable($b['data_inicio']))
                                    ->diff(new DateTimeImmutable($b['data_fim']))->days + 1;
                        ?>
                        <tr>
                            <td>
                                <?= e($fmt($b['data_inicio'])) ?>
                                <?php if ($b['data_fim'] !== $b['data_inicio']): ?>
                                    até <?= e($fmt($b['data_fim'])) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $dias ?></td>
                            <td class="text-end">
                                <form method="POST" class="d-inline">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            data-confirma="Remover este bloqueio? Você poderá ser escalado(a) nesses dias.">
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

        <?php if ($passados): ?>
            <h2 class="h6 mt-4 text-muted">Anteriores</h2>
            <ul class="list-group list-group-flush small">
                <?php foreach ($passados as $b): ?>
                    <li class="list-group-item d-flex justify-content-between text-muted">
                        <span>
                            <?= e($fmt($b['data_inicio'])) ?>
                            <?php if ($b['data_fim'] !== $b['data_inicio']): ?>
                                até <?= e($fmt($b['data_fim'])) ?>
                            <?php endif; ?>
                        </span>
                        <form method="POST" class="d-inline">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                            <button type="submit" class="btn btn-link btn-sm text-muted p-0">Apagar</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>