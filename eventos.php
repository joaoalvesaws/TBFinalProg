<?php
// eventos.php
// Eventos (cultos, reuniões...) para os quais o líder monta a escala.
// Líder, admin da igreja e admin geral. Cada um só enxerga a própria igreja
// (o admin geral escolhe a igreja ao cadastrar).
// Só o admin geral vê, em cada evento, quem é o responsável por cada função.


require __DIR__ . '/includes/sessao.php';

require __DIR__ . '/includes/util.php';

$usuario = exigirPerfil('LIDER');
$ehGeral = $usuario['perfil'] === 'ADMIN_GERAL';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    $acao = post('acao');

    // ---------- Cadastrar ----------
    if ($acao === 'criar') {
        $nome       = post('nome');
        $data       = post('data');
        $horaInicio = post('hora_inicio');
        $horaFim    = post('hora_fim');
        $igrejaId   = igrejaDoContexto($pdo, $usuario, $_POST['igreja_id'] ?? 0);
        $erros      = [];

        if (!$igrejaId) {
            $erros[] = $ehGeral ? 'Escolha a igreja.' : 'Sua conta não está vinculada a uma igreja.';
        }
        if ($nome === '' || mb_strlen($nome) > 120) {
            $erros[] = 'Informe o nome do evento (até 120 caracteres).';
        }
        if (!dataValida($data)) {
            $erros[] = 'Data inválida.';
        } elseif ($data < date('Y-m-d')) {
            $erros[] = 'A data do evento não pode estar no passado.';
        }
        if (!horaValida($horaInicio) || !horaValida($horaFim)) {
            $erros[] = 'Informe o horário de início e de fim.';
        } elseif ($horaFim <= $horaInicio) {
            $erros[] = 'O horário de fim precisa ser depois do início (evento no mesmo dia).';
        }

        if ($erros) {
            foreach ($erros as $mensagem) {
                flash('danger', $mensagem);
            }
        } else {
            $pdo->prepare(
                'INSERT INTO eventos (igreja_id, nome, data, hora_inicio, hora_fim)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$igrejaId, $nome, $data, $horaInicio, $horaFim]);

            auditar($pdo, (int) $usuario['id'], 'EVENTO_CRIADO', 'eventos',
                    (int) $pdo->lastInsertId(),
                    ['nome' => $nome, 'data' => $data, 'igreja_id' => $igrejaId]);
            flash('success', 'Evento cadastrado.');
            redirecionar('/eventos.php');
        }

    // ---------- Excluir (só se ninguém foi escalado) ----------
    } elseif ($acao === 'excluir') {
        $eventoId = (int) post('id');

        $s = $pdo->prepare('SELECT id, nome, igreja_id FROM eventos WHERE id = ?');
        $s->execute([$eventoId]);
        $evento = $s->fetch();

        if (!$evento || !podeAcessarIgreja($usuario, (int) $evento['igreja_id'])) {
            flash('danger', 'Evento não encontrado.');
        } else {
            $c = $pdo->prepare('SELECT COUNT(*) FROM escalas WHERE evento_id = ?');
            $c->execute([$eventoId]);

            if ((int) $c->fetchColumn() > 0) {
                flash('warning', 'Este evento já tem pessoas escaladas. Remova as escalas antes de excluir.');
            } else {
                $pdo->prepare('DELETE FROM eventos WHERE id = ?')->execute([$eventoId]);
                auditar($pdo, (int) $usuario['id'], 'EVENTO_EXCLUIDO', 'eventos', $eventoId,
                        ['nome' => $evento['nome']]);
                flash('success', 'Evento excluído.');
            }
        }
        redirecionar('/eventos.php');
    }
}

// Próximos eventos (a partir de hoje)
$sql = 'SELECT e.id, e.nome, e.data, e.hora_inicio, e.hora_fim, i.nome AS igreja,
               (SELECT COUNT(*) FROM escalas s WHERE s.evento_id = e.id) AS pessoas
          FROM eventos e
          JOIN igrejas i ON i.id = e.igreja_id
         WHERE e.data >= CURDATE()';
if ($ehGeral) {
    $stmt = $pdo->query($sql . ' ORDER BY e.data, e.hora_inicio');
} else {
    $stmt = $pdo->prepare($sql . ' AND e.igreja_id = ? ORDER BY e.data, e.hora_inicio');
    $stmt->execute([$usuario['igreja_id']]);
}
$eventos = $stmt->fetchAll();
$igrejas = $ehGeral ? listarIgrejas($pdo) : [];

// ---------------------------------------------------------------
// SÓ O ADMIN GERAL: quem é o responsável por cada função de cada evento
// (os outros perfis nem executam estas consultas)
// ---------------------------------------------------------------
$porEvento = [];   // [evento_id][departamento_id] = ['nome' => ..., 'pessoas' => [...]]
$lideres   = [];   // [departamento_id] = [nomes dos líderes]

$rotuloStatus = [
    'RASCUNHO' => ['Rascunho',   'secondary'],
    'ENVIADA'  => ['Publicada',  'primary'],
    'ACEITA'   => ['Confirmada', 'success'],
];

if ($ehGeral && $eventos) {
    $eventoIds = array_map(fn($linha) => (int) $linha['id'], $eventos);
    $marcas    = implode(',', array_fill(0, count($eventoIds), '?'));

    $stmt = $pdo->prepare(
        "SELECT es.evento_id, es.departamento_id, d.nome AS departamento,
                es.funcao, es.status, u.nome AS pessoa
           FROM escalas es
           JOIN departamentos d ON d.id = es.departamento_id
           JOIN usuarios u      ON u.id = es.usuario_id
          WHERE es.evento_id IN ($marcas)
          ORDER BY d.nome, es.funcao, u.nome"
    );
    $stmt->execute($eventoIds);

    $depIds = [];
    foreach ($stmt->fetchAll() as $r) {
        $eventoDaLinha = (int) $r['evento_id'];
        $depDaLinha    = (int) $r['departamento_id'];
        $porEvento[$eventoDaLinha][$depDaLinha]['nome']      = $r['departamento'];
        $porEvento[$eventoDaLinha][$depDaLinha]['pessoas'][] = $r;
        $depIds[$depDaLinha] = true;
    }

    if ($depIds) {
        $depLista  = array_keys($depIds);
        $marcasDep = implode(',', array_fill(0, count($depLista), '?'));

        $stmt = $pdo->prepare(
            "SELECT du.departamento_id, u.nome
               FROM departamento_usuario du
               JOIN usuarios u ON u.id = du.usuario_id
              WHERE du.e_lider = 1 AND u.ativo = 1
                AND du.departamento_id IN ($marcasDep)
              ORDER BY u.nome"
        );
        $stmt->execute($depLista);
        foreach ($stmt->fetchAll() as $r) {
            $lideres[(int) $r['departamento_id']][] = $r['nome'];
        }
    }
}

$tituloPagina = 'Eventos';

include __DIR__ . '/includes/topo.php';

?>

<h1 class="h3 mb-3">Eventos</h1>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h2 class="h5">Cadastrar evento</h2>
                <form method="POST" autocomplete="off">
                    <?= csrfCampo() ?>
                    <input type="hidden" name="acao" value="criar">

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
                        <label for="nome" class="form-label">Nome (ex.: Culto de domingo)</label>
                        <input type="text" class="form-control" id="nome" name="nome"
                               maxlength="120" required value="<?= antigo('nome') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="data" class="form-label">Data</label>
                        <input type="date" class="form-control" id="data" name="data"
                               min="<?= e(date('Y-m-d')) ?>" required value="<?= antigo('data') ?>">
                    </div>

                    <div class="row">
                        <div class="col mb-3">
                            <label for="hora_inicio" class="form-label">Início</label>
                            <input type="time" class="form-control" id="hora_inicio" name="hora_inicio"
                                   required value="<?= antigo('hora_inicio') ?>">
                        </div>
                        <div class="col mb-3">
                            <label for="hora_fim" class="form-label">Fim</label>
                            <input type="time" class="form-control" id="hora_fim" name="hora_fim"
                                   required value="<?= antigo('hora_fim') ?>">
                        </div>
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
                        <th>Data</th>
                        <th>Evento</th>
                        <?php if ($ehGeral): ?><th>Igreja</th><?php endif; ?>
                        <th>Escalados</th>
                        <?php if ($ehGeral): ?><th>Responsáveis</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($eventos as $ev): ?>
                    <tr>
                        <td class="text-nowrap">
                            <?= e(date('d/m/Y', strtotime($ev['data']))) ?><br>
                            <small class="text-muted">
                                <?= e(substr($ev['hora_inicio'], 0, 5)) ?> - <?= e(substr($ev['hora_fim'], 0, 5)) ?>
                            </small>
                        </td>
                        <td><?= e($ev['nome']) ?></td>
                        <?php if ($ehGeral): ?><td><?= e($ev['igreja']) ?></td><?php endif; ?>
                        <td><?= (int) $ev['pessoas'] ?></td>

                        <?php if ($ehGeral): ?>
                            <td>
                                <?php $departamentos = $porEvento[(int) $ev['id']] ?? []; ?>
                                <?php if (!$departamentos): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <details>
                                        <summary class="small">Ver responsáveis</summary>
                                        <?php foreach ($departamentos as $depId => $d): ?>
                                            <div class="small mt-2">
                                                <strong><?= e($d['nome']) ?></strong>
                                                <span class="text-muted">
                                                    · Líder: <?= e(isset($lideres[$depId]) ? implode(', ', $lideres[$depId]) : 'sem líder definido') ?>
                                                </span>
                                                <ul class="list-unstyled mb-0 ms-2">
                                                    <?php foreach ($d['pessoas'] as $p): ?>
                                                        <?php $rotulo = $rotuloStatus[$p['status']] ?? [$p['status'], 'secondary']; ?>
                                                        <li>
                                                            <?= e($p['funcao'] ?: '-') ?>: <?= e($p['pessoa']) ?>
                                                            <span class="badge text-bg-<?= e($rotulo[1]) ?>"><?= e($rotulo[0]) ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endforeach; ?>
                                    </details>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>

                        <td class="text-end">
                            <?php if ((int) $ev['pessoas'] === 0): ?>
                                <form method="POST" class="d-inline">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            data-confirma="Excluir este evento?">Excluir</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$eventos): ?>
                    <tr><td colspan="<?= $ehGeral ? 6 : 4 ?>" class="text-muted">Nenhum evento futuro cadastrado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>