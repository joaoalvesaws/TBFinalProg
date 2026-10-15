<?php
// src/departamentos.php
// Cadastro de departamentos. Admin da igreja (só da própria igreja) e admin geral.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';

$usuario = exigirPerfil('ADMIN_IGREJA');
$ehGeral = $usuario['perfil'] === 'ADMIN_GERAL';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
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
               (SELECT COUNT(*) FROM departamento_usuario du WHERE du.departamento_id = d.id) AS membros
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
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($departamentos as $d): ?>
                    <tr>
                        <?php if ($ehGeral): ?><td><?= e($d['igreja']) ?></td><?php endif; ?>
                        <td><?= e($d['nome']) ?></td>
                        <td><?= (int) $d['membros'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$departamentos): ?>
                    <tr><td colspan="3" class="text-muted">Nenhum departamento cadastrado ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>
