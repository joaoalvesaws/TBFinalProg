<?php
// src/igrejas.php
// Cadastro de igrejas. Só o administrador geral acessa.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';

$usuario = exigirPerfil('ADMIN_GERAL');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
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
                    <tr><th>Igreja</th><th>Departamentos</th><th>Cadastrada em</th></tr>
                </thead>
                <tbody>
                <?php foreach ($igrejas as $i): ?>
                    <tr>
                        <td><?= e($i['nome']) ?></td>
                        <td><?= (int) $i['departamentos'] ?></td>
                        <td><?= e(date('d/m/Y', strtotime($i['criado_em']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$igrejas): ?>
                    <tr><td colspan="3" class="text-muted">Nenhuma igreja cadastrada ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>
