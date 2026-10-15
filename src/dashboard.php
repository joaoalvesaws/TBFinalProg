<?php
// src/dashboard.php
// Visão geral do mês: quem está escalado em cada dia. Todos os perfis acessam.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/auxiliares.php';
require __DIR__ . '/includes/calendario.php';

$usuario = exigirLogin();

[$ano, $mes] = mesPedido();
$calendario  = carregarMes($pdo, $usuario, $ano, $mes);

$tituloPagina = 'Dashboard';
$paginaAtiva  = 'dashboard';
$semTopbar    = true;   // o cabeçalho (selo, botão e setas) é desenhado abaixo

include __DIR__ . '/includes/topo.php';
?>
        <header class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
          <div>
            <h1 class="h4 fw-bold mb-3">Dashboard</h1>
            <div class="fw-semibold mb-2"><?= e(MESES[$mes] . ' ' . $ano) ?></div>
            <div class="small">
              <span class="k-legenda"><span class="k-ponto k-ponto-sab"></span>Sábado</span>
              <span class="k-legenda"><span class="k-ponto k-ponto-dom"></span>Domingo</span>
            </div>
          </div>

          <div class="d-flex flex-column align-items-end gap-2">
            <span class="k-selo"><?= e(perfilSelo($usuario['perfil'])) ?></span>
            <div class="d-flex align-items-center gap-2">
              <?php if (ehAdmin($usuario)): ?>
                <!-- Só o administrador vê este botão. A geração da escala (machine learning)
                     ainda não existe: por enquanto o botão não tem ação. -->
                <button type="button" id="btn-gerar-escala" class="btn btn-sm k-btn-claro px-3">Gerar Escala</button>
              <?php endif; ?>
              <?= navegacaoMes('dashboard.php', $ano, $mes, 'k-seta') ?>
            </div>
          </div>
        </header>

        <section aria-label="Calendário de <?= e(MESES[$mes] . ' de ' . $ano) ?>">
          <?= gradeCalendario($calendario, false) ?>
        </section>
<?php include __DIR__ . '/includes/rodape.php'; ?>
