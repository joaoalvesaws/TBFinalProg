<?php
// src/includes/calendario.php
// Monta o calendário mensal (usado por dashboard.php e calendario.php).
// Precisa do sessao.php e do auxiliares.php já carregados.

// Lê ?ano= e ?mes= da URL (valida); sem parâmetros, usa o mês atual.
function mesPedido(): array
{
    $hoje = new DateTimeImmutable('today');

    $ano = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
    $mes = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);

    return [$ano ?: (int) $hoje->format('Y'), $mes ?: (int) $hoje->format('n')];
}

// Mês anterior (-1) ou seguinte (+1): devolve [ano, mes]
function mesVizinho(int $ano, int $mes, int $delta): array
{
    $d = (new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes)))
        ->modify(($delta > 0 ? '+' : '-') . '1 month');
    return [(int) $d->format('Y'), (int) $d->format('n')];
}

// Busca no banco quem está escalado e quem está indisponível em cada dia do mês.
// Cada igreja só vê os próprios dados; o administrador geral vê todas.
function carregarMes(PDO $pdo, array $usuario, int $ano, int $mes): array
{
    $primeiro = new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
    $ultimo   = $primeiro->modify('last day of this month');
    $ini      = $primeiro->format('Y-m-d');
    $fim      = $ultimo->format('Y-m-d');
    $igreja   = igrejaDoUsuario($usuario);

    $dias = [];
    for ($d = $primeiro; $d <= $ultimo; $d = $d->modify('+1 day')) {
        $dias[$d->format('Y-m-d')] = ['escalas' => [], 'indisp' => []];
    }

    // Escalas. Voluntário não vê rascunho (só o que já foi publicado).
    $sql = 'SELECT ev.data, u.nome
              FROM escalas s
              JOIN eventos  ev ON ev.id = s.evento_id
              JOIN usuarios u  ON u.id  = s.usuario_id
             WHERE ev.data BETWEEN ? AND ?';
    $par = [$ini, $fim];
    if ($igreja !== null) {
        $sql .= ' AND ev.igreja_id = ?';
        $par[] = $igreja;
    }
    if (!ehLiderOuMais($usuario)) {
        $sql .= " AND s.status <> 'RASCUNHO'";
    }
    $sql .= ' ORDER BY ev.data, ev.hora_inicio, u.nome';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($par);
    foreach ($stmt as $linha) {
        $dias[$linha['data']]['escalas'][] = $linha['nome'];
    }

    // Indisponibilidade (bloqueios que tocam o mês)
    $sql = 'SELECT b.data_inicio, b.data_fim, u.nome
              FROM bloqueios b
              JOIN usuarios u ON u.id = b.usuario_id
             WHERE b.data_inicio <= ? AND b.data_fim >= ?';
    $par = [$fim, $ini];
    if ($igreja !== null) {
        $sql .= ' AND u.igreja_id = ?';
        $par[] = $igreja;
    }
    $sql .= ' ORDER BY u.nome';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($par);
    foreach ($stmt as $b) {
        $de  = max($b['data_inicio'], $ini);
        $ate = min($b['data_fim'], $fim);
        for ($d = new DateTimeImmutable($de); $d->format('Y-m-d') <= $ate; $d = $d->modify('+1 day')) {
            $dias[$d->format('Y-m-d')]['indisp'][] = $b['nome'];
        }
    }

    foreach ($dias as $data => $d) {
        $dias[$data]['escalas'] = array_values(array_unique($d['escalas']));
        $dias[$data]['indisp']  = array_values(array_unique($d['indisp']));
    }

    return [
        'ano'    => $ano,
        'mes'    => $mes,
        'offset' => (int) $primeiro->format('w'),   // 0 = domingo
        'dias'   => $dias,
    ];
}

// Desenha a grade (dias da semana + dias do mês). Só consulta: nada é clicável.
// $grande = true: cartões maiores e com a indisponibilidade (página Calendário).
function gradeCalendario(array $m, bool $grande): string
{
    $classeGrade = 'k-calendario' . ($grande ? ' k-calendario-grande' : '');

    ob_start(); ?>
    <div class="<?= $classeGrade ?> mb-2" aria-hidden="true">
      <?php foreach (['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'] as $nome): ?>
        <div class="k-dia-semana"><?= $nome ?></div>
      <?php endforeach; ?>
    </div>
    <div class="<?= $classeGrade ?>">
      <?php for ($i = 0; $i < $m['offset']; $i++): ?>
        <div class="k-dia k-dia-vazio" aria-hidden="true"></div>
      <?php endfor; ?>

      <?php foreach ($m['dias'] as $data => $d):
          $dow    = (int) (new DateTimeImmutable($data))->format('w');
          $classe = 'k-dia';
          if ($dow === 6) { $classe .= ' k-dia-sab'; }
          if ($dow === 0) { $classe .= ' k-dia-dom'; }
          if (!$d['escalas'] && !($grande && $d['indisp'])) { $classe .= ' k-dia-sem-escala'; }
      ?>
        <div class="<?= $classe ?>" data-data="<?= e($data) ?>">
          <span class="k-num"><?= (int) substr($data, 8, 2) ?></span>
          <?php foreach ($d['escalas'] as $nome): ?>
            <strong class="k-nome"><?= e($nome) ?></strong>
          <?php endforeach; ?>
          <?php if ($grande): foreach ($d['indisp'] as $nome): ?>
            <span class="k-indisp"><span class="visually-hidden">Indisponível: </span><?= e($nome) ?></span>
          <?php endforeach; endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// Setas de mês anterior/seguinte + seletor de ano. $pagina = 'dashboard.php' ou 'calendario.php'
function navegacaoMes(string $pagina, int $ano, int $mes, string $classeSeta): string
{
    [$anoAnt, $mesAnt] = mesVizinho($ano, $mes, -1);
    [$anoSeg, $mesSeg] = mesVizinho($ano, $mes, +1);

    $anoAtual = (int) date('Y');
    $anos = array_unique([$anoAtual - 1, $anoAtual, $anoAtual + 1, $ano]);
    sort($anos);

    ob_start(); ?>
    <a href="<?= e($pagina) ?>?ano=<?= $anoAnt ?>&amp;mes=<?= $mesAnt ?>" class="<?= e($classeSeta) ?>" aria-label="Mês anterior">&lt;</a>
    <form method="get" action="<?= e($pagina) ?>">
      <input type="hidden" name="mes" value="<?= $mes ?>">
      <select name="ano" class="form-select form-select-sm k-ano" aria-label="Ano" onchange="this.form.submit()">
        <?php foreach ($anos as $a): ?>
          <option value="<?= $a ?>"<?= $a === $ano ? ' selected' : '' ?>><?= $a ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn btn-sm k-btn-claro">Ir</button></noscript>
    </form>
    <a href="<?= e($pagina) ?>?ano=<?= $anoSeg ?>&amp;mes=<?= $mesSeg ?>" class="<?= e($classeSeta) ?>" aria-label="Próximo mês">&gt;</a>
    <?php
    return ob_get_clean();
}
