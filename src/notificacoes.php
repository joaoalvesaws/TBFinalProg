<?php
// src/notificacoes.php
// Notificações do administrador logado. Só administradores acessam.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/auxiliares.php';

$usuario = exigirPerfil('ADMIN_IGREJA');
$meuId   = (int) $usuario['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'marcar_lida') {
        $pdo->prepare('UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $meuId]);
    } elseif ($acao === 'marcar_todas') {
        $pdo->prepare('UPDATE notificacoes SET lida = 1 WHERE usuario_id = ? AND lida = 0')
            ->execute([$meuId]);
        flash('success', 'Todas as notificações foram marcadas como lidas.');
    }
    redirecionar('notificacoes.php');
}

$stmt = $pdo->prepare('SELECT id, titulo, mensagem, lida, criado_em FROM notificacoes WHERE usuario_id = ? ORDER BY lida, criado_em DESC, id DESC LIMIT 100');
$stmt->execute([$meuId]);
$notificacoes = $stmt->fetchAll();
$naoLidas = count(array_filter($notificacoes, fn($n) => !$n['lida']));

$tituloPagina = 'Notificações';
$paginaAtiva  = 'notificacoes';

include __DIR__ . '/includes/topo.php';
?>
        <div class="k-conteudo">
          <?php if ($naoLidas > 0): ?>
            <form method="post" action="notificacoes.php" class="d-flex justify-content-end mb-3">
              <?= csrfCampo() ?>
              <input type="hidden" name="acao" value="marcar_todas">
              <button type="submit" class="btn btn-sm k-btn-contorno">Marcar todas como lidas</button>
            </form>
          <?php endif; ?>

          <section class="k-card" aria-label="Lista de notificações">
            <?php if (!$notificacoes): ?>
              <p class="mb-0 k-texto-suave">Nenhuma notificação por enquanto.</p>
            <?php else: ?>
              <ul class="list-unstyled mb-0">
                <?php foreach ($notificacoes as $n): ?>
                  <li class="py-3 k-linha d-flex justify-content-between align-items-start gap-3<?= $n['lida'] ? ' k-lida' : '' ?>">
                    <div>
                      <strong><?= e($n['titulo']) ?></strong>
                      <?php if (!$n['lida']): ?><span class="k-badge k-badge-info">Nova</span><?php endif; ?>
                      <div class="small"><?= e($n['mensagem']) ?></div>
                      <div class="small k-texto-suave"><?= e((new DateTimeImmutable($n['criado_em']))->format('d/m/Y H:i')) ?></div>
                    </div>
                    <?php if (!$n['lida']): ?>
                      <form method="post" action="notificacoes.php">
                        <?= csrfCampo() ?>
                        <input type="hidden" name="acao" value="marcar_lida">
                        <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <button type="submit" class="btn btn-sm k-btn-contorno text-nowrap">Marcar como lida</button>
                      </form>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        </div>
<?php include __DIR__ . '/includes/rodape.php'; ?>
