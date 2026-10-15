<?php
// src/calendario.php
// Calendário do mês, só para consulta: escalados e indisponibilidades. Todos os perfis acessam.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/auxiliares.php';
require __DIR__ . '/includes/calendario.php';

$usuario = exigirLogin();

[$ano, $mes] = mesPedido();
$calendario  = carregarMes($pdo, $usuario, $ano, $mes);

$tituloPagina = 'Calendário';
$paginaAtiva  = 'calendario';

include __DIR__ . '/includes/topo.php';
?>
        <div class="k-conteudo">
          <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
            <div>
              <h2 class="h5 fw-bold mb-2"><?= e(MESES[$mes] . ' ' . $ano) ?></h2>
              <div class="small">
                <span class="k-legenda"><span class="k-ponto k-ponto-sab"></span>Sábado</span>
                <span class="k-legenda"><span class="k-ponto k-ponto-dom"></span>Domingo</span>
                <span class="k-legenda"><span class="k-ponto k-ponto-indisp"></span>Indisponibilidade</span>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <?= navegacaoMes('calendario.php', $ano, $mes, 'btn btn-sm k-btn-nav') ?>
            </div>
          </div>

          <section aria-label="Calendário de <?= e(MESES[$mes] . ' de ' . $ano) ?>">
            <?= gradeCalendario($calendario, true) ?>
          </section>
        </div>
<?php include __DIR__ . '/includes/rodape.php'; ?>
