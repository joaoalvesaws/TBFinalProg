<?php
// src/igrejas.php
// Cadastro de igrejas. Só o administrador geral acessa.
// Ações: cadastrar, editar o nome, apagar todos os departamentos e excluir.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';
require __DIR__ . '/includes/limpeza.php';

$usuario = exigirPerfil('ADMIN_GERAL');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();

    // ----- Editar o nome de uma igreja -----
    if (post('acao') === 'editar') {
        $igrejaId = (int) post('id');
        $novoNome = post('nome');

        $s = $pdo->prepare('SELECT id, nome FROM igrejas WHERE id = ?');
        $s->execute([$igrejaId]);
        $igreja = $s->fetch();

        if (!$igreja) {
            flash('danger', 'Igreja não encontrada.');
        } elseif ($novoNome === '' || mb_strlen($novoNome) > 120) {
            flash('danger', 'Informe o nome da igreja (até 120 caracteres).');
        } elseif ($novoNome === $igreja['nome']) {
            flash('warning', 'O nome continua o mesmo. Nada foi alterado.');
        } else {
            try {
                $pdo->prepare('UPDATE igrejas SET nome = ? WHERE id = ?')->execute([$novoNome, $igrejaId]);
                auditar($pdo, (int) $usuario['id'], 'IGREJA_EDITADA', 'igrejas', $igrejaId,
                        ['nome_anterior' => $igreja['nome'], 'nome_novo' => $novoNome]);
                flash('success', 'Igreja renomeada para "' . $novoNome . '".');
            } catch (PDOException $ex) {
                error_log('Erro ao editar igreja: ' . $ex->getMessage());
                flash('danger', 'Não foi possível salvar a alteração.');
            }
        }
        redirecionar('/igrejas.php');
    }

    // ----- Apagar TODOS os departamentos de uma igreja (com escalas, vagas e vínculos) -----
    if (post('acao') === 'apagar_departamentos') {
        $igrejaId = (int) post('id');

        $s = $pdo->prepare('SELECT id, nome FROM igrejas WHERE id = ?');
        $s->execute([$igrejaId]);
        $igreja = $s->fetch();

        if (!$igreja) {
            flash('danger', 'Igreja não encontrada.');
        } elseif (mb_strtolower(trim(post('confirmar_nome'))) !== mb_strtolower($igreja['nome'])) {
            flash('danger', 'O nome digitado não confere com o da igreja. Nada foi apagado.');
        } else {
            $s = $pdo->prepare('SELECT id FROM departamentos WHERE igreja_id = ?');
            $s->execute([$igrejaId]);
            $depIds = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));

            if (!$depIds) {
                flash('warning', 'Esta igreja não tem departamentos.');
            } else {
                try {
                    $pdo->beginTransaction();
                    $totalEscalas = 0;
                    foreach ($depIds as $depId) {
                        $totalEscalas += limpezaApagarEscalas($pdo, $depId, false);
                        limpezaExcluirDepartamento($pdo, $depId);
                    }
                    auditar($pdo, (int) $usuario['id'], 'DEPARTAMENTOS_APAGADOS', 'igrejas', $igrejaId,
                            ['departamentos' => count($depIds), 'escalas' => $totalEscalas]);
                    $pdo->commit();
                    flash('success', count($depIds) . ' departamento(s) e ' . $totalEscalas
                        . ' escala(s) de "' . $igreja['nome'] . '" apagados.');
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Erro ao apagar departamentos: ' . $ex->getMessage());
                    flash('danger', 'Não foi possível apagar. Nada foi alterado.');
                    if (($config['ambiente'] ?? '') === 'desenvolvimento') {
                        flash('info', 'Detalhe técnico (só aparece em desenvolvimento): ' . $ex->getMessage());
                    }
                }
            }
        }
        redirecionar('/igrejas.php');
    }

    // ----- Excluir igreja (só sem departamentos e sem pessoas) -----
    if (post('acao') === 'excluir') {
        $igrejaId = (int) post('id');

        $s = $pdo->prepare('SELECT id, nome FROM igrejas WHERE id = ?');
        $s->execute([$igrejaId]);
        $igreja = $s->fetch();

        if (!$igreja) {
            flash('danger', 'Igreja não encontrada.');
        } else {
            $consultas = [
                'departamento(s)' => 'SELECT COUNT(*) FROM departamentos WHERE igreja_id = ?',
                'pessoa(s)'       => 'SELECT COUNT(*) FROM usuarios WHERE igreja_id = ?',
            ];
            $pendencias = [];
            foreach ($consultas as $rotulo => $sql) {
                $s = $pdo->prepare($sql);
                $s->execute([$igrejaId]);
                $n = (int) $s->fetchColumn();
                if ($n > 0) {
                    $pendencias[$rotulo] = $n;
                }
            }

            if (isset($pendencias['departamento(s)'])) {
                flash('danger', 'Apague primeiro os departamentos de "' . $igreja['nome'] . '" ('
                    . $pendencias['departamento(s)'] . '). Use o botão "Apagar todos os departamentos".');
            } elseif (isset($pendencias['pessoa(s)'])) {
                flash('danger', 'A igreja "' . $igreja['nome'] . '" ainda tem ' . $pendencias['pessoa(s)']
                    . ' pessoa(s) cadastrada(s), e hoje o sistema não exclui pessoas.');
            } else {
                try {
                    $pdo->beginTransaction();
                    // Sem departamentos não há escalas: eventos, vagas e cultos padrão são só calendário
                    $pdo->prepare('DELETE v FROM vagas v JOIN eventos ev ON ev.id = v.evento_id WHERE ev.igreja_id = ?')
                        ->execute([$igrejaId]);
                    $pdo->prepare('DELETE FROM eventos WHERE igreja_id = ?')->execute([$igrejaId]);
                    $pdo->prepare('DELETE FROM eventos_padrao WHERE igreja_id = ?')->execute([$igrejaId]);
                    $pdo->prepare('DELETE FROM igrejas WHERE id = ?')->execute([$igrejaId]);
                    auditar($pdo, (int) $usuario['id'], 'IGREJA_EXCLUIDA', 'igrejas', $igrejaId,
                            ['nome' => $igreja['nome']]);
                    $pdo->commit();
                    flash('success', 'Igreja "' . $igreja['nome'] . '" excluída.');
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Erro ao excluir igreja: ' . $ex->getMessage());
                    flash('danger', 'Não foi possível excluir. Nada foi alterado.');
                    if (($config['ambiente'] ?? '') === 'desenvolvimento') {
                        flash('info', 'Detalhe técnico (só aparece em desenvolvimento): ' . $ex->getMessage());
                    }
                }
            }
        }
        redirecionar('/igrejas.php');
    }

    // ----- Cadastrar igreja -----
    $nome = post('nome');

    if ($nome === '' || mb_strlen($nome) > 120) {
        flash('danger', 'Informe o nome da igreja (até 120 caracteres).');
    } else {
        $pdo->prepare('INSERT INTO igrejas (nome) VALUES (?)')->execute([$nome]);
        auditar($pdo, (int) $usuario['id'], 'IGREJA_CRIADA', 'igrejas',
                (int) $pdo->lastInsertId(), ['nome' => $nome]);
        flash('success', 'Igreja cadastrada.');
        redirecionar('/igrejas.php');
    }
}

$igrejas = $pdo->query(
    'SELECT i.id, i.nome, i.criado_em,
            (SELECT COUNT(*) FROM departamentos d WHERE d.igreja_id = i.id) AS departamentos
       FROM igrejas i
      ORDER BY i.nome'
)->fetchAll();

$tituloPagina = 'Igrejas';
include __DIR__ . '/includes/topo.php';
?>

<h1 class="h3 mb-3">Igrejas</h1>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h2 class="h5">Cadastrar igreja</h2>
                <form method="POST" autocomplete="off">
                    <?= csrfCampo() ?>
                    <div class="mb-3">
                        <label for="nome" class="form-label">Nome</label>
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
                    <tr><th>Igreja</th><th>Departamentos</th><th>Cadastrada em</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($igrejas as $i): ?>
                    <tr>
                        <td><?= e($i['nome']) ?></td>
                        <td><?= (int) $i['departamentos'] ?></td>
                        <td><?= e(date('d/m/Y', strtotime($i['criado_em']))) ?></td>
                        <td class="text-end">
                            <!-- Editar o nome (abre/fecha o campo logo abaixo) -->
                            <button type="button" class="btn btn-sm btn-outline-primary mb-1"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#editar-igreja-<?= (int) $i['id'] ?>"
                                    aria-expanded="false">
                                Editar
                            </button>
                            <form method="POST" class="d-inline">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger mb-1"
                                        data-confirma="Excluir a igreja <?= e($i['nome']) ?>? Os eventos dela também serão apagados.">
                                    Excluir igreja
                                </button>
                            </form>

                            <!-- Campo de edição (escondido até clicar em "Editar") -->
                            <div class="collapse mb-2" id="editar-igreja-<?= (int) $i['id'] ?>">
                                <form method="POST" class="d-flex gap-1 justify-content-end" autocomplete="off">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="acao" value="editar">
                                    <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                                    <input type="text" name="nome" class="form-control form-control-sm"
                                           style="max-width: 16rem" maxlength="120" required
                                           value="<?= e($i['nome']) ?>" aria-label="Novo nome da igreja">
                                    <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                                </form>
                            </div>

                            <form method="POST" class="d-inline-flex gap-1 align-items-center">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="acao" value="apagar_departamentos">
                                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                                <input type="text" name="confirmar_nome" class="form-control form-control-sm"
                                       style="width: 13rem" autocomplete="off" required
                                       placeholder="Digite: <?= e($i['nome']) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        data-confirma="Apagar TODOS os departamentos de <?= e($i['nome']) ?>, com escalas, vagas e vínculos? Não dá para desfazer.">
                                    Apagar todos os departamentos
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$igrejas): ?>
                    <tr><td colspan="4" class="text-muted">Nenhuma igreja cadastrada ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>