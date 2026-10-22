<?php
// src/departamentos.php
// Cadastro de departamentos. Admin da igreja (só da própria igreja) e admin geral.
// Ações: cadastrar, editar o nome, apagar escalas e excluir.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';
require __DIR__ . '/includes/limpeza.php';

$usuario = exigirPerfil('ADMIN_IGREJA');
$ehGeral = $usuario['perfil'] === 'ADMIN_GERAL';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();

    // ----- Editar o nome de um departamento -----
    if (post('acao') === 'editar') {
        $depId    = (int) post('id');
        $novoNome = post('nome');

        $s = $pdo->prepare('SELECT id, nome, igreja_id FROM departamentos WHERE id = ?');
        $s->execute([$depId]);
        $dep = $s->fetch();

        if (!$dep || !podeAcessarIgreja($usuario, (int) $dep['igreja_id'])) {
            flash('danger', 'Departamento não encontrado.');
        } elseif ($novoNome === '' || mb_strlen($novoNome) > 120) {
            flash('danger', 'Informe o nome do departamento (até 120 caracteres).');
        } elseif ($novoNome === $dep['nome']) {
            flash('warning', 'O nome continua o mesmo. Nada foi alterado.');
        } else {
            try {
                $pdo->prepare('UPDATE departamentos SET nome = ? WHERE id = ?')->execute([$novoNome, $depId]);
                auditar($pdo, (int) $usuario['id'], 'DEPARTAMENTO_EDITADO', 'departamentos', $depId,
                        ['nome_anterior' => $dep['nome'], 'nome_novo' => $novoNome]);
                flash('success', 'Departamento renomeado para "' . $novoNome . '".');
            } catch (PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062) {   // chave única repetida
                    flash('danger', 'Já existe um departamento com esse nome nesta igreja.');
                } else {
                    error_log('Erro ao editar departamento: ' . $ex->getMessage());
                    flash('danger', 'Não foi possível salvar a alteração.');
                }
            }
        }
        redirecionar('/departamentos.php');
    }

    // ----- Apagar as escalas de um departamento -----
    if (post('acao') === 'apagar_escalas') {
        $depId         = (int) post('id');
        $apenasFuturas = post('escopo') === 'futuras';

        $s = $pdo->prepare('SELECT id, nome, igreja_id FROM departamentos WHERE id = ?');
        $s->execute([$depId]);
        $dep = $s->fetch();

        if (!$dep || !podeAcessarIgreja($usuario, (int) $dep['igreja_id'])) {
            flash('danger', 'Departamento não encontrado.');
        } else {
            try {
                $pdo->beginTransaction();
                $apagadas = limpezaApagarEscalas($pdo, $depId, $apenasFuturas);
                auditar($pdo, (int) $usuario['id'], 'ESCALAS_APAGADAS', 'departamentos', $depId,
                        ['escopo' => $apenasFuturas ? 'futuras' : 'todas', 'quantidade' => $apagadas]);
                $pdo->commit();

                flash($apagadas > 0 ? 'success' : 'warning',
                    $apagadas > 0
                        ? $apagadas . ' escala(s) de "' . $dep['nome'] . '" apagada(s).'
                        : 'Não havia escalas para apagar.');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Erro ao apagar escalas: ' . $ex->getMessage());
                flash('danger', 'Não foi possível apagar. Nada foi alterado.');
                if (($config['ambiente'] ?? '') === 'desenvolvimento') {
                    flash('info', 'Detalhe técnico (só aparece em desenvolvimento): ' . $ex->getMessage());
                }
            }
        }
        redirecionar('/departamentos.php');
    }

    // ----- Excluir departamento (só sem escalas) -----
    if (post('acao') === 'excluir') {
        $depId = (int) post('id');

        $s = $pdo->prepare('SELECT id, nome, igreja_id FROM departamentos WHERE id = ?');
        $s->execute([$depId]);
        $dep = $s->fetch();

        if (!$dep || !podeAcessarIgreja($usuario, (int) $dep['igreja_id'])) {
            flash('danger', 'Departamento não encontrado.');
        } else {
            $s = $pdo->prepare('SELECT COUNT(*) FROM escalas WHERE departamento_id = ?');
            $s->execute([$depId]);
            $totalEscalas = (int) $s->fetchColumn();

            if ($totalEscalas > 0) {
                flash('danger', 'Apague primeiro as escalas de "' . $dep['nome'] . '" (' . $totalEscalas
                    . ' escala(s)). Use o botão "Apagar todas as escalas" e depois exclua o departamento.');
            } else {
                try {
                    $pdo->beginTransaction();
                    limpezaExcluirDepartamento($pdo, $depId);
                    auditar($pdo, (int) $usuario['id'], 'DEPARTAMENTO_EXCLUIDO', 'departamentos', $depId,
                            ['nome' => $dep['nome'], 'igreja_id' => (int) $dep['igreja_id']]);
                    $pdo->commit();
                    flash('success', 'Departamento "' . $dep['nome'] . '" excluído.');
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Erro ao excluir departamento: ' . $ex->getMessage());
                    flash('danger', 'Não foi possível excluir. Nada foi alterado.');
                    if (($config['ambiente'] ?? '') === 'desenvolvimento') {
                        flash('info', 'Detalhe técnico (só aparece em desenvolvimento): ' . $ex->getMessage());
                    }
                }
            }
        }
        redirecionar('/departamentos.php');
    }

    // ----- Cadastrar departamento -----
    $nome     = post('nome');
    $igrejaId = igrejaDoContexto($pdo, $usuario, $_POST['igreja_id'] ?? 0);

    if (!$igrejaId) {
        flash('danger', 'Escolha a igreja.');
    } elseif ($nome === '' || mb_strlen($nome) > 120) {
        flash('danger', 'Informe o nome do departamento (até 120 caracteres).');
    } else {
        try {
            $pdo->prepare('INSERT INTO departamentos (igreja_id, nome) VALUES (?, ?)')
                ->execute([$igrejaId, $nome]);
            auditar($pdo, (int) $usuario['id'], 'DEPARTAMENTO_CRIADO', 'departamentos',
                    (int) $pdo->lastInsertId(), ['nome' => $nome, 'igreja_id' => $igrejaId]);
            flash('success', 'Departamento cadastrado.');
            redirecionar('/departamentos.php');
        } catch (PDOException $ex) {
            if (($ex->errorInfo[1] ?? 0) === 1062) {   // chave única repetida
                flash('danger', 'Já existe um departamento com esse nome nesta igreja.');
            } else {
                error_log('Erro ao criar departamento: ' . $ex->getMessage());
                flash('danger', 'Não foi possível salvar. Tente novamente.');
            }
        }
    }
}

// Lista: admin geral vê todas as igrejas; admin da igreja vê só a sua
$sql = 'SELECT d.id, d.nome, i.nome AS igreja,
               (SELECT COUNT(*) FROM departamento_usuario du WHERE du.departamento_id = d.id) AS membros,
               (SELECT COUNT(*) FROM escalas es WHERE es.departamento_id = d.id) AS escalas,
               (SELECT COUNT(*) FROM escalas es2
                  JOIN eventos ev2 ON ev2.id = es2.evento_id
                 WHERE es2.departamento_id = d.id
                   AND TIMESTAMP(ev2.data, ev2.hora_fim) >= NOW()) AS escalas_futuras
          FROM departamentos d
          JOIN igrejas i ON i.id = d.igreja_id';
if ($ehGeral) {
    $stmt = $pdo->query($sql . ' ORDER BY i.nome, d.nome');
} else {
    $stmt = $pdo->prepare($sql . ' WHERE d.igreja_id = ? ORDER BY d.nome');
    $stmt->execute([$usuario['igreja_id']]);
}
$departamentos = $stmt->fetchAll();
$igrejas = $ehGeral ? listarIgrejas($pdo) : [];

$tituloPagina = 'Departamentos';
include __DIR__ . '/includes/topo.php';
?>

<h1 class="h3 mb-3">Departamentos</h1>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h2 class="h5">Cadastrar departamento</h2>
                <form method="POST" autocomplete="off">
                    <?= csrfCampo() ?>

                    <?php if ($ehGeral): ?>
                        <div class="mb-3">
                            <label for="igreja_id" class="form-label">Igreja</label>
                            <select class="form-select" id="igreja_id" name="igreja_id" required>
                                <option value="">Escolha...</option>
                                <?php foreach ($igrejas as $i): ?>
                                    <option value="<?= (int) $i['id'] ?>"
                                        <?= (int) ($_POST['igreja_id'] ?? 0) === (int) $i['id'] ? 'selected' : '' ?>>
                                        <?= e($i['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="nome" class="form-label">Nome (ex.: Louvor, Recepção)</label>
                        <input type="text" class="form-control" id="nome" name="nome"
                               maxlength="120" required value="<?= antigo('nome') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary" data-enviando="Salvando...">Cadastrar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <?php if ($ehGeral): ?><th>Igreja</th><?php endif; ?>
                        <th>Departamento</th>
                        <th>Pessoas</th>
                        <th>Escalas</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($departamentos as $d): ?>
                    <tr>
                        <?php if ($ehGeral): ?><td><?= e($d['igreja']) ?></td><?php endif; ?>
                        <td><?= e($d['nome']) ?></td>
                        <td><?= (int) $d['membros'] ?></td>
                        <td>
                            <?= (int) $d['escalas'] ?>
                            <?php if ((int) $d['escalas_futuras'] > 0): ?>
                                <small class="text-muted">(<?= (int) $d['escalas_futuras'] ?> futuras)</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <!-- Editar o nome (abre/fecha o campo logo abaixo) -->
                            <button type="button" class="btn btn-sm btn-outline-primary mb-1"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#editar-dep-<?= (int) $d['id'] ?>"
                                    aria-expanded="false">
                                Editar
                            </button>

                            <?php if ((int) $d['escalas_futuras'] > 0): ?>
                                <form method="POST" class="d-inline">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="acao" value="apagar_escalas">
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <button type="submit" name="escopo" value="futuras"
                                            class="btn btn-sm btn-outline-warning mb-1"
                                            data-confirma="Apagar as <?= (int) $d['escalas_futuras'] ?> escala(s) futuras de <?= e($d['nome']) ?>? O histórico passado continua.">
                                        Apagar futuras
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ((int) $d['escalas'] > 0): ?>
                                <form method="POST" class="d-inline">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="acao" value="apagar_escalas">
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <button type="submit" name="escopo" value="todas"
                                            class="btn btn-sm btn-outline-danger mb-1"
                                            data-confirma="Apagar TODAS as <?= (int) $d['escalas'] ?> escala(s) de <?= e($d['nome']) ?>, inclusive o histórico? Não dá para desfazer.">
                                        Apagar todas as escalas
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" class="d-inline">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger mb-1"
                                        <?= (int) $d['escalas'] > 0 ? 'disabled' : '' ?>
                                        data-confirma="Excluir o departamento <?= e($d['nome']) ?>? As <?= (int) $d['membros'] ?> pessoa(s) continuam cadastradas, mas saem dele.">
                                    Excluir departamento
                                </button>
                            </form>
                            <?php if ((int) $d['escalas'] > 0): ?>
                                <div class="small text-muted">Apague as escalas para poder excluir.</div>
                            <?php endif; ?>

                            <!-- Campo de edição (escondido até clicar em "Editar") -->
                            <div class="collapse mt-2" id="editar-dep-<?= (int) $d['id'] ?>">
                                <form method="POST" class="d-flex gap-1 justify-content-end" autocomplete="off">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="acao" value="editar">
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <input type="text" name="nome" class="form-control form-control-sm"
                                           style="max-width: 16rem" maxlength="120" required
                                           value="<?= e($d['nome']) ?>" aria-label="Novo nome do departamento">
                                    <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$departamentos): ?>
                    <tr><td colspan="<?= $ehGeral ? 5 : 4 ?>" class="text-muted">Nenhum departamento cadastrado ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>