<?php
// src/solicitacoes.php
// Tudo o que o voluntário pede ou responde: bloquear período, aceitar escala e trocar plantão.
// Todos os perfis acessam. Cada pessoa só mexe nos próprios dados.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/regras.php';

$usuario = exigirLogin();
$meuId   = (int) $usuario['id'];
$igreja  = igrejaDoUsuario($usuario);

// ---------------------------------------------------------------
// Ações (POST): cada uma termina com redirecionamento (a página recarrega)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    $acao = (string) ($_POST['acao'] ?? '');

    // ----- Bloquear período (só "indisponível", sem motivo: LGPD) -----
    if ($acao === 'bloquear') {
        $inicio = dataValida($_POST['data_inicio'] ?? null);
        $fim    = dataValida($_POST['data_fim'] ?? null);

        if ($inicio === null || $fim === null) {
            flash('danger', 'Informe o início e o fim do período.');
        } elseif ($fim < $inicio) {
            flash('danger', 'O fim do período não pode ser antes do início.');
        } else {
            $pdo->prepare('INSERT INTO bloqueios (usuario_id, data_inicio, data_fim) VALUES (?, ?, ?)')
                ->execute([$meuId, $inicio, $fim]);
            auditar($pdo, $meuId, 'BLOQUEIO_CRIADO', 'bloqueios', (int) $pdo->lastInsertId(), ['inicio' => $inicio, 'fim' => $fim]);
            flash('success', 'Período bloqueado.');
        }
        redirecionar('solicitacoes.php');
    }

    // ----- Remover um bloqueio -----
    if ($acao === 'remover_bloqueio') {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM bloqueios WHERE id = ? AND usuario_id = ?');
        $stmt->execute([$id, $meuId]);
        if ($stmt->rowCount() > 0) {
            auditar($pdo, $meuId, 'BLOQUEIO_REMOVIDO', 'bloqueios', $id);
            flash('success', 'Bloqueio removido.');
        }
        redirecionar('solicitacoes.php');
    }

    // ----- Aceitar escala (ENVIADA -> ACEITA) -----
    if ($acao === 'aceitar_escala') {
        $id   = (int) ($_POST['escala_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE escalas SET status = 'ACEITA' WHERE id = ? AND usuario_id = ? AND status = 'ENVIADA'");
        $stmt->execute([$id, $meuId]);
        if ($stmt->rowCount() > 0) {
            auditar($pdo, $meuId, 'ESCALA_ACEITA', 'escalas', $id);
            flash('success', 'Escala aceita.');
        } else {
            flash('danger', 'Esta escala não está mais aguardando aceite.');
        }
        redirecionar('solicitacoes.php');
    }

    // ----- Solicitar troca de plantão -----
    if ($acao === 'solicitar_troca') {
        $destinatarioId = (int) ($_POST['destinatario_id'] ?? 0);
        $dia            = dataValida($_POST['dia_plantao'] ?? null);

        // O destinatário precisa ser outro voluntário/líder ativo da mesma igreja
        $stmt = $pdo->prepare(
            "SELECT id, nome FROM usuarios
              WHERE id = ? AND id <> ? AND ativo = 1
                AND perfil IN ('LIDER','VOLUNTARIO') AND igreja_id <=> ?"
        );
        $stmt->execute([$destinatarioId, $meuId, $igreja]);
        $destinatario = $stmt->fetch();

        if (!$destinatario || $dia === null) {
            flash('danger', 'Escolha o voluntário e o dia do seu plantão.');
            redirecionar('solicitacoes.php');
        }

        // Escala do solicitante nesse dia
        $stmt = $pdo->prepare(
            'SELECT s.id, s.evento_id, s.departamento_id, ev.data, ev.hora_inicio, ev.hora_fim
               FROM escalas s
               JOIN eventos ev ON ev.id = s.evento_id
              WHERE s.usuario_id = ? AND ev.data = ?
              ORDER BY ev.hora_inicio LIMIT 1'
        );
        $stmt->execute([$meuId, $dia]);
        $escala = $stmt->fetch();

        if (!$escala) {
            flash('danger', 'Você não tem plantão no dia ' . dataBr($dia) . '.');
            redirecionar('solicitacoes.php');
        }

        // Já existe pedido em andamento para este plantão?
        $stmt = $pdo->prepare("SELECT 1 FROM trocas WHERE escala_id = ? AND status IN ('PENDENTE','AGUARDANDO_LIDER') LIMIT 1");
        $stmt->execute([$escala['id']]);
        if ($stmt->fetchColumn()) {
            flash('warning', 'Já existe um pedido de troca em andamento para este plantão.');
            redirecionar('solicitacoes.php');
        }

        // Regras de escala (regras.php). Violação não bloqueia: vai para o líder depois do aceite.
        $problema = trocaViolaRegras($pdo, $escala, (int) $destinatario['id']);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO trocas (escala_id, solicitante_id, destinatario_id, precisa_lider) VALUES (?, ?, ?, ?)')
                ->execute([$escala['id'], $meuId, $destinatario['id'], $problema !== null ? 1 : 0]);
            $trocaId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO notificacoes (usuario_id, titulo, mensagem) VALUES (?, ?, ?)')
                ->execute([
                    $destinatario['id'],
                    'Pedido de troca de plantão',
                    $usuario['nome'] . ' pediu para trocar o plantão do dia ' . dataBr($dia) . ' com você.',
                ]);

            auditar($pdo, $meuId, 'TROCA_SOLICITADA', 'trocas', $trocaId, [
                'escala_id' => (int) $escala['id'], 'destinatario_id' => (int) $destinatario['id'], 'violacao' => $problema,
            ]);
            $pdo->commit();
        } catch (Throwable $erro) {
            $pdo->rollBack();
            throw $erro;
        }

        if ($problema !== null) {
            flash('warning', 'Pedido enviado. ' . $problema . ' Se o voluntário aceitar, a troca seguirá para aprovação do líder.');
        } else {
            flash('success', 'Pedido de troca enviado. ' . $destinatario['nome'] . ' poderá aceitar ou recusar.');
        }
        redirecionar('solicitacoes.php');
    }

    redirecionar('solicitacoes.php');
}

// ---------------------------------------------------------------
// Dados da tela
// ---------------------------------------------------------------

// Meus bloqueios de hoje em diante
$stmt = $pdo->prepare('SELECT id, data_inicio, data_fim FROM bloqueios WHERE usuario_id = ? AND data_fim >= CURDATE() ORDER BY data_inicio');
$stmt->execute([$meuId]);
$bloqueios = $stmt->fetchAll();

// Escalas publicadas que ainda aguardam o meu aceite
$stmt = $pdo->prepare(
    "SELECT s.id, ev.nome AS evento, ev.data, ev.hora_inicio, d.nome AS departamento
       FROM escalas s
       JOIN eventos ev      ON ev.id = s.evento_id
       JOIN departamentos d ON d.id  = s.departamento_id
      WHERE s.usuario_id = ? AND s.status = 'ENVIADA' AND ev.data >= CURDATE()
      ORDER BY ev.data, ev.hora_inicio"
);
$stmt->execute([$meuId]);
$aguardandoAceite = $stmt->fetchAll();

// Possíveis destinatários da troca (mesma igreja)
$stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE ativo = 1 AND id <> ? AND perfil IN ('LIDER','VOLUNTARIO') AND igreja_id <=> ? ORDER BY nome");
$stmt->execute([$meuId, $igreja]);
$colegas = $stmt->fetchAll();

// Trocas em que participo (peço ou recebo)
$stmt = $pdo->prepare(
    'SELECT t.id, t.status, t.precisa_lider, ev.data,
            us.nome AS solicitante, ud.nome AS destinatario
       FROM trocas t
       JOIN escalas  s  ON s.id  = t.escala_id
       JOIN eventos  ev ON ev.id = s.evento_id
       JOIN usuarios us ON us.id = t.solicitante_id
       JOIN usuarios ud ON ud.id = t.destinatario_id
      WHERE t.solicitante_id = ? OR t.destinatario_id = ?
      ORDER BY t.criado_em DESC, t.id DESC
      LIMIT 20'
);
$stmt->execute([$meuId, $meuId]);
$trocas = $stmt->fetchAll();

const STATUS_TROCA = [
    'PENDENTE'             => ['Pendente',          'k-badge-aviso'],
    'ACEITA'               => ['Aceito',            'k-badge-ok'],
    'RECUSADA'             => ['Recusado',          'k-badge-erro'],
    'AGUARDANDO_LIDER'     => ['Aguardando líder',  'k-badge-aviso'],
    'APROVADA_PELO_LIDER'  => ['Aprovado',          'k-badge-ok'],
    'REJEITADA_PELO_LIDER' => ['Rejeitado',         'k-badge-erro'],
];

$tituloPagina = 'Solicitações';
$paginaAtiva  = 'solicitacoes';

include __DIR__ . '/includes/topo.php';
?>
        <div class="k-conteudo">
          <div class="row g-3 mb-3">

            <!-- ===== Bloqueio de período ===== -->
            <div class="col-md-6">
              <section class="k-card h-100" aria-labelledby="t-bloqueio">
                <h2 class="k-card-titulo" id="t-bloqueio">Bloqueio de período</h2>
                <p class="small k-texto-suave">Informe os dias em que você não pode servir. O motivo não é registrado.</p>

                <form method="post" action="solicitacoes.php">
                  <?= csrfCampo() ?>
                  <input type="hidden" name="acao" value="bloquear">

                  <label for="data_inicio" class="form-label small">Início</label>
                  <input type="date" id="data_inicio" name="data_inicio" class="form-control k-campo mb-3" required>

                  <label for="data_fim" class="form-label small">Fim</label>
                  <input type="date" id="data_fim" name="data_fim" class="form-control k-campo mb-3" required>

                  <button type="submit" class="btn k-btn-acao">Bloquear período</button>
                </form>

                <?php if ($bloqueios): ?>
                  <hr class="k-divisor">
                  <h3 class="small fw-semibold mb-2">Meus bloqueios</h3>
                  <ul class="list-unstyled mb-0">
                    <?php foreach ($bloqueios as $b): ?>
                      <li class="d-flex justify-content-between align-items-center gap-2 py-1">
                        <span class="small">
                          <?= e(dataBr($b['data_inicio'])) ?><?= $b['data_fim'] !== $b['data_inicio'] ? ' a ' . e(dataBr($b['data_fim'])) : '' ?>
                        </span>
                        <form method="post" action="solicitacoes.php">
                          <?= csrfCampo() ?>
                          <input type="hidden" name="acao" value="remover_bloqueio">
                          <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                          <button type="submit" class="btn btn-sm k-btn-perigo" data-confirma="Remover este bloqueio?">Remover</button>
                        </form>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </section>
            </div>

            <!-- ===== Aceitar escala ===== -->
            <div class="col-md-6">
              <section class="k-card h-100" aria-labelledby="t-aceite">
                <h2 class="k-card-titulo" id="t-aceite">Aceitar escala</h2>

                <?php if (!$aguardandoAceite): ?>
                  <p class="small k-texto-suave mb-0">Nenhuma escala aguardando o seu aceite.</p>
                <?php else: ?>
                  <p class="small k-texto-suave">Confirme os plantões em que você foi escalado.</p>
                  <ul class="list-unstyled mb-0">
                    <?php foreach ($aguardandoAceite as $s): ?>
                      <li class="d-flex justify-content-between align-items-center gap-2 py-2 k-linha">
                        <span>
                          <strong><?= e(dataBr($s['data'])) ?></strong>
                          <span class="small k-texto-suave d-block"><?= e($s['evento']) ?>, <?= e(substr($s['hora_inicio'], 0, 5)) ?> · <?= e($s['departamento']) ?></span>
                        </span>
                        <form method="post" action="solicitacoes.php">
                          <?= csrfCampo() ?>
                          <input type="hidden" name="acao" value="aceitar_escala">
                          <input type="hidden" name="escala_id" value="<?= (int) $s['id'] ?>">
                          <button type="submit" class="btn btn-sm k-btn-acao">Aceitar escala</button>
                        </form>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </section>
            </div>
          </div>

          <!-- ===== Solicitar troca de plantão ===== -->
          <section class="k-card mb-3" aria-labelledby="t-troca">
            <h2 class="k-card-titulo" id="t-troca">Solicitar troca de plantão</h2>
            <p class="small k-texto-suave">Escolha um voluntário. Ele receberá uma notificação e poderá aceitar ou recusar a troca.</p>

            <form method="post" action="solicitacoes.php">
              <?= csrfCampo() ?>
              <input type="hidden" name="acao" value="solicitar_troca">

              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label for="destinatario_id" class="form-label small">Voluntário</label>
                  <select id="destinatario_id" name="destinatario_id" class="form-select k-campo" required<?= $colegas ? '' : ' disabled' ?>>
                    <?php if (!$colegas): ?>
                      <option value="">Nenhum voluntário disponível</option>
                    <?php else: ?>
                      <option value="" selected disabled>Selecione</option>
                      <?php foreach ($colegas as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></option>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label for="dia_plantao" class="form-label small">Dia do seu plantão</label>
                  <input type="date" id="dia_plantao" name="dia_plantao" class="form-control k-campo" required>
                </div>
              </div>

              <button type="submit" class="btn k-btn-acao"<?= $colegas ? '' : ' disabled' ?>>Enviar solicitação</button>
            </form>
          </section>

          <!-- ===== Trocas de plantão ===== -->
          <section class="k-card" aria-labelledby="t-trocas">
            <h2 class="k-card-titulo" id="t-trocas">Trocas de plantão</h2>

            <?php if (!$trocas): ?>
              <p class="small k-texto-suave mb-0">Você ainda não tem trocas.</p>
            <?php else: ?>
              <ul class="list-unstyled mb-0">
                <?php foreach ($trocas as $t):
                    [$rotulo, $classe] = STATUS_TROCA[$t['status']] ?? [$t['status'], 'k-badge-neutro'];
                ?>
                  <li class="py-2 k-linha">
                    <strong><?= e($t['solicitante']) ?> → <?= e($t['destinatario']) ?></strong>
                    <div class="small">
                      Dia: <?= e(dataBr($t['data'])) ?>
                      <span class="k-badge <?= $classe ?>"><?= e($rotulo) ?></span>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        </div>
<?php include __DIR__ . '/includes/rodape.php'; ?>
