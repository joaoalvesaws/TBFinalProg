<?php
// Tela do líder: monta a escala de UM departamento.
//
// Fluxo manual: escolhe o departamento, escolhe o evento, adiciona pessoas
// (cada uma validada pelo regras.php), remove se precisar e publica.
//
// Fluxo com IA: define as VAGAS dos eventos, pede o rascunho de um período,
// revisa/ajusta e publica. A IA só sugere; o regras.php confere tudo.
//
// Depende de:
//   includes/sessao.php, includes/util.php (login, CSRF, mensagens, auditoria, notificarUsuarioPorEmail)
//   regras.php          (função violacoes)
//   gerador_ia.php      (geração do rascunho com IA)
//   includes/topo.php e includes/rodape.php (layout)

require_once __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/util.php';
require_once __DIR__ . '/regras.php';
require_once __DIR__ . '/gerador_ia.php';

$usuario = exigirPerfil('LIDER');

// ---------------------------------------------------------------
// Funções auxiliares da tela
// ---------------------------------------------------------------

function dataBR(string $data): string
{
    return date('d/m/Y', strtotime($data));
}

// Guarda as mensagens, recarrega a página e para o script.
function voltar(int $depId, int $eventoId, string $tipo, array $mensagens): void
{
    $classe = ['sucesso' => 'success', 'aviso' => 'warning'][$tipo] ?? 'danger';
    foreach ($mensagens as $mensagem) {
        flash($classe, $mensagem);
    }

    $url = 'lider_escalas.php';
    if ($depId) {
        $url .= '?dep=' . $depId . ($eventoId ? '&evento=' . $eventoId : '');
    }
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------
// 1) Departamentos que este usuário pode gerenciar
// ---------------------------------------------------------------
$sqlBase = 'SELECT d.id, d.nome, d.igreja_id, i.nome AS igreja_nome
              FROM departamentos d
              JOIN igrejas i ON i.id = d.igreja_id';

if ($usuario['perfil'] === 'LIDER') {
    $stmt = $pdo->prepare(
        $sqlBase . ' JOIN departamento_usuario du ON du.departamento_id = d.id
                     WHERE du.usuario_id = ? AND du.e_lider = 1
                     ORDER BY d.nome'
    );
    $stmt->execute([$usuario['id']]);
} elseif ($usuario['perfil'] === 'ADMIN_IGREJA') {
    $stmt = $pdo->prepare($sqlBase . ' WHERE d.igreja_id = ? ORDER BY d.nome');
    $stmt->execute([$usuario['igreja_id']]);
} else {
    $stmt = $pdo->query($sqlBase . ' ORDER BY i.nome, d.nome');
}

$departamentos = [];
foreach ($stmt->fetchAll() as $d) {
    $departamentos[(int) $d['id']] = $d;
}

// ---------------------------------------------------------------
// 2) Departamento e evento escolhidos
// ---------------------------------------------------------------
$depId = (int) ($_POST['dep'] ?? $_GET['dep'] ?? 0);

if ($depId === 0 && count($departamentos) === 1) {
    $depId = (int) array_key_first($departamentos);
}

$dep = $departamentos[$depId] ?? null;

if ($depId !== 0 && ($dep === null || !podeAcessarIgreja($usuario, (int) $dep['igreja_id']))) {
    http_response_code(403);
    exit('Você não gerencia este departamento.');
}

$eventoId = (int) ($_POST['evento'] ?? $_GET['evento'] ?? 0);
$evento = null;

if ($dep && $eventoId) {
    $stmt = $pdo->prepare('SELECT * FROM eventos WHERE id = ? AND igreja_id = ?');
    $stmt->execute([$eventoId, $dep['igreja_id']]);
    $evento = $stmt->fetch() ?: null;

    if ($evento === null) {
        http_response_code(404);
        exit('Evento não encontrado.');
    }
}

// ---------------------------------------------------------------
// 3) Ações (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    $acao = post('acao');

    if ($dep === null) {
        voltar($depId, 0, 'erro', ['Escolha um departamento.']);
    }

    // ----- Gerar rascunho com IA -----
    if ($acao === 'gerar_ia') {
        $resultado = gerarRascunhoComIA(
            $pdo, $usuario, $dep,
            post('data_inicio'), post('data_fim'), post('pedido')
        );

        if (!$resultado['ok']) {
            voltar($depId, 0, 'erro', [$resultado['erro']]);
        }

        $_SESSION['ia_resultado'] = $resultado;

        $mensagens = [];
        if (!empty($resultado['aviso'])) {
            $mensagens[] = $resultado['aviso'];
        }
        if ($resultado['criadas'] > 0 || empty($resultado['aviso'])) {
            $mensagens[] = 'A IA sugeriu ' . $resultado['criadas']
                         . ' pessoa(s) como RASCUNHO. Revise antes de publicar.';
        }
        $tipo = ($resultado['nao_resolvidos'] || !empty($resultado['aviso'])) ? 'aviso' : 'sucesso';
        voltar($depId, 0, $tipo, $mensagens);
    }

    // ----- Publicar todos os rascunhos de um período -----
    if ($acao === 'publicar_periodo') {
        $inicio = post('data_inicio');
        $fim    = post('data_fim');

        if (!dataValida($inicio) || !dataValida($fim) || $fim < $inicio) {
            voltar($depId, 0, 'erro', ['Informe um período válido para publicar.']);
        }

        // Identifica os usuários que receberão o aviso de nova escala
        $stmtUser = $pdo->prepare(
            "SELECT DISTINCT es.usuario_id 
               FROM escalas es
               JOIN eventos ev ON ev.id = es.evento_id
              WHERE es.departamento_id = ? AND es.status = 'RASCUNHO'
                AND ev.data BETWEEN ? AND ? AND ev.data >= CURDATE()"
        );
        $stmtUser->execute([$depId, $inicio, $fim]);
        $usuariosNotificar = $stmtUser->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare(
            "UPDATE escalas es
               JOIN eventos ev ON ev.id = es.evento_id
                SET es.status = 'ENVIADA'
              WHERE es.departamento_id = ? AND es.status = 'RASCUNHO'
                AND ev.data BETWEEN ? AND ? AND ev.data >= CURDATE()"
        );
        $stmt->execute([$depId, $inicio, $fim]);
        $total = $stmt->rowCount();

        if ($total === 0) {
            voltar($depId, 0, 'erro', ['Não há rascunhos neste período para publicar.']);
        }

        registrarAuditoria($pdo, (int) $usuario['id'], 'ESCALA_PUBLICADA_PERIODO', 'departamentos', $depId, [
            'periodo'    => [$inicio, $fim],
            'quantidade' => $total,
        ]);

        // Dispara e-mails para os voluntários avisando sobre novas escalas
        if (function_exists('notificarUsuarioPorEmail')) {
            foreach ($usuariosNotificar as $uid) {
                notificarUsuarioPorEmail(
                    $pdo,
                    (int) $uid,
                    'Novas escalas disponíveis',
                    'Você possui novas escalas disponíveis no sistema. Acesse a plataforma para verificar as datas e horários.'
                );
            }
        }

        voltar($depId, 0, 'sucesso', ["Escala publicada ($total pessoa(s)). E-mails de notificação enviados."]);
    }

    // ===== Ações que precisam de um evento futuro =====
    if ($evento === null) {
        voltar($depId, 0, 'erro', ['Escolha um departamento e um evento.']);
    }

    if ($evento['data'] < date('Y-m-d')) {
        voltar($depId, $eventoId, 'erro', ['Este evento já passou e não pode mais ser alterado.']);
    }

    // ----- Definir uma vaga -----
    if ($acao === 'vaga_adicionar') {
        $funcaoVaga = mb_substr(post('funcao_vaga'), 0, 80);
        $quantidade = (int) post('quantidade');

        if ($quantidade < 1 || $quantidade > 20) {
            voltar($depId, $eventoId, 'erro', ['A quantidade de vagas deve ser de 1 a 20.']);
        }

        $pdo->prepare(
            'INSERT INTO vagas (evento_id, departamento_id, funcao, quantidade)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade)'
        )->execute([$eventoId, $depId, $funcaoVaga, $quantidade]);

        registrarAuditoria($pdo, (int) $usuario['id'], 'VAGA_DEFINIDA', 'eventos', $eventoId, [
            'departamento_id' => $depId,
            'funcao'          => $funcaoVaga,
            'quantidade'      => $quantidade,
        ]);
        voltar($depId, $eventoId, 'sucesso', ['Vaga salva.']);
    }

    // ----- Remover uma vaga -----
    if ($acao === 'vaga_remover') {
        $vagaId = (int) post('vaga_id');
        $stmt = $pdo->prepare('DELETE FROM vagas WHERE id = ? AND evento_id = ? AND departamento_id = ?');
        $stmt->execute([$vagaId, $eventoId, $depId]);

        if ($stmt->rowCount() > 0) {
            registrarAuditoria($pdo, (int) $usuario['id'], 'VAGA_REMOVIDA', 'eventos', $eventoId, [
                'departamento_id' => $depId,
                'vaga_id'         => $vagaId,
            ]);
        }
        voltar($depId, $eventoId, 'sucesso', ['Vaga removida.']);
    }

    // ----- Copiar vagas para próximos eventos -----
    if ($acao === 'vagas_copiar') {
        $pdo->prepare(
            'INSERT INTO vagas (evento_id, departamento_id, funcao, quantidade)
             SELECT ev2.id, v.departamento_id, v.funcao, v.quantidade
               FROM vagas v
               JOIN eventos ev2 ON ev2.igreja_id = ? AND ev2.nome = ?
                                AND ev2.data >= CURDATE() AND ev2.id <> ?
              WHERE v.evento_id = ? AND v.departamento_id = ?
             ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade)'
        )->execute([$dep['igreja_id'], $evento['nome'], $eventoId, $eventoId, $depId]);

        registrarAuditoria($pdo, (int) $usuario['id'], 'VAGAS_COPIADAS', 'eventos', $eventoId, [
            'departamento_id' => $depId,
            'nome_evento'     => $evento['nome'],
        ]);
        voltar($depId, $eventoId, 'sucesso', ['Vagas copiadas para os próximos eventos "' . $evento['nome'] . '".']);
    }

    // ----- Remover cópias de vagas -----
    if ($acao === 'vagas_remover_copias') {
        $stmt = $pdo->prepare(
            'DELETE v
               FROM vagas v
               JOIN eventos ev2 ON ev2.id = v.evento_id
              WHERE v.departamento_id = ?
                AND ev2.igreja_id = ? AND ev2.nome = ?
                AND ev2.data >= CURDATE() AND ev2.id <> ?'
        );
        $stmt->execute([$depId, $dep['igreja_id'], $evento['nome'], $eventoId]);
        $total = $stmt->rowCount();

        registrarAuditoria($pdo, (int) $usuario['id'], 'VAGAS_COPIAS_REMOVIDAS', 'eventos', $eventoId, [
            'departamento_id' => $depId,
            'nome_evento'     => $evento['nome'],
            'vagas_removidas' => $total,
        ]);

        if ($total === 0) {
            voltar($depId, $eventoId, 'erro', ['Não havia vagas nos outros eventos "' . $evento['nome'] . '".']);
        }
        voltar($depId, $eventoId, 'sucesso', [$total . ' vaga(s) removida(s) dos outros eventos "' . $evento['nome'] . '".']);
    }

    // ----- Adicionar uma pessoa à escala -----
    if ($acao === 'adicionar') {
        $voluntarioId = (int) ($_POST['usuario_id'] ?? 0);
        $funcao = trim((string) ($_POST['funcao'] ?? ''));

        $stmt = $pdo->prepare(
            'SELECT u.id, u.nome, du.funcao
               FROM departamento_usuario du
               JOIN usuarios u ON u.id = du.usuario_id
              WHERE du.departamento_id = ? AND du.usuario_id = ? AND u.ativo = 1'
        );
        $stmt->execute([$depId, $voluntarioId]);
        $membro = $stmt->fetch();

        if (!$membro) {
            voltar($depId, $eventoId, 'erro', ['Essa pessoa não faz parte deste departamento.']);
        }

        if ($funcao === '') {
            $funcao = (string) ($membro['funcao'] ?? '');
        }
        $funcao = mb_substr($funcao, 0, 80);

        $erros = violacoes($pdo, $voluntarioId, $evento, $depId);
        if ($erros) {
            $mensagens = [];
            foreach ($erros as $erro) {
                $mensagens[] = $membro['nome'] . ': ' . $erro;
            }
            voltar($depId, $eventoId, 'erro', $mensagens);
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO escalas (evento_id, departamento_id, usuario_id, funcao, status)
                 VALUES (?, ?, ?, ?, 'RASCUNHO')"
            )->execute([$eventoId, $depId, $voluntarioId, $funcao !== '' ? $funcao : null]);
            $novoId = (int) $pdo->lastInsertId();

            registrarAuditoria($pdo, (int) $usuario['id'], 'ESCALA_ADICIONADA', 'escalas', $novoId, [
                'evento_id'       => $eventoId,
                'departamento_id' => $depId,
                'usuario_id'      => $voluntarioId,
                'funcao'          => $funcao,
            ]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                voltar($depId, $eventoId, 'erro', [$membro['nome'] . ' já está escalado(a) neste evento.']);
            }
            throw $e;
        }

        voltar($depId, $eventoId, 'sucesso', [$membro['nome'] . ' foi adicionado(a) como rascunho.']);
    }

    // ----- Remover uma pessoa da escala -----
    if ($acao === 'remover') {
        $escalaId = (int) ($_POST['escala_id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT * FROM escalas WHERE id = ? AND evento_id = ? AND departamento_id = ?'
        );
        $stmt->execute([$escalaId, $eventoId, $depId]);
        $linha = $stmt->fetch();

        if (!$linha) {
            voltar($depId, $eventoId, 'erro', ['Item da escala não encontrado.']);
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare('DELETE FROM escalas WHERE id = ?')->execute([$escalaId]);

            registrarAuditoria($pdo, (int) $usuario['id'], 'ESCALA_REMOVIDA', 'escalas', $escalaId, [
                'evento_id'       => $eventoId,
                'departamento_id' => $depId,
                'usuario_id'      => (int) $linha['usuario_id'],
                'status_anterior' => $linha['status'],
            ]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                voltar($depId, $eventoId, 'erro', ['Esta vaga tem um pedido de troca registrado e não pode ser removida.']);
            }
            throw $e;
        }

        voltar($depId, $eventoId, 'sucesso', ['Pessoa removida da escala.']);
    }

    // ----- Publicar evento individual (RASCUNHO vira ENVIADA) -----
    if ($acao === 'publicar') {
        try {
            // Busca usuários que estão em rascunho para notificar após a publicação
            $stmtUser = $pdo->prepare(
                "SELECT DISTINCT usuario_id FROM escalas
                  WHERE evento_id = ? AND departamento_id = ? AND status = 'RASCUNHO'"
            );
            $stmtUser->execute([$eventoId, $depId]);
            $usuariosNotificar = $stmtUser->fetchAll(PDO::FETCH_COLUMN);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "UPDATE escalas SET status = 'ENVIADA'
                  WHERE evento_id = ? AND departamento_id = ? AND status = 'RASCUNHO'"
            );
            $stmt->execute([$eventoId, $depId]);
            $total = $stmt->rowCount();

            if ($total > 0) {
                registrarAuditoria($pdo, (int) $usuario['id'], 'ESCALA_PUBLICADA', 'eventos', $eventoId, [
                    'departamento_id' => $depId,
                    'quantidade'      => $total,
                ]);
            }

            $pdo->commit();

            // Dispara e-mails de notificação
            if ($total > 0 && function_exists('notificarUsuarioPorEmail')) {
                foreach ($usuariosNotificar as $uid) {
                    notificarUsuarioPorEmail(
                        $pdo,
                        (int) $uid,
                        'Novas escalas disponíveis',
                        'Você possui novas escalas disponíveis no sistema. Acesse a plataforma para verificar as datas e horários.'
                    );
                }
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($total === 0) {
            voltar($depId, $eventoId, 'erro', ['Não há ninguém em rascunho para publicar.']);
        }
        voltar($depId, $eventoId, 'sucesso', ["Escala publicada ($total pessoa(s)). E-mails enviados."]);
    }

    voltar($depId, $eventoId, 'erro', ['Ação inválida.']);
}

// ---------------------------------------------------------------
// 4) Dados para mostrar na tela
// ---------------------------------------------------------------
$iaResultado = $_SESSION['ia_resultado'] ?? null;
unset($_SESSION['ia_resultado']);

$eventos = [];
$escalados = [];
$disponiveis = [];
$vagasEvento = [];
$faltamPorVaga = [];
$funcoesDoDep = [];

if ($dep) {
    $stmt = $pdo->prepare(
        'SELECT ev.id, ev.nome, ev.data, ev.hora_inicio, ev.hora_fim,
                (SELECT COUNT(*) FROM escalas es
                  WHERE es.evento_id = ev.id AND es.departamento_id = ?) AS total,
                (SELECT COALESCE(SUM(v.quantidade), 0) FROM vagas v
                  WHERE v.evento_id = ev.id AND v.departamento_id = ?) AS vagas
           FROM eventos ev
          WHERE ev.igreja_id = ? AND ev.data >= CURDATE()
          ORDER BY ev.data, ev.hora_inicio
          LIMIT 60'
    );
    $stmt->execute([$depId, $depId, $dep['igreja_id']]);
    $eventos = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT DISTINCT funcao FROM departamento_usuario
          WHERE departamento_id = ? AND funcao IS NOT NULL AND funcao <> ''
          ORDER BY funcao"
    );
    $stmt->execute([$depId]);
    $funcoesDoDep = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

if ($dep && $evento) {
    $stmt = $pdo->prepare(
        'SELECT es.id, es.funcao, es.status, u.nome
           FROM escalas es
           JOIN usuarios u ON u.id = es.usuario_id
          WHERE es.evento_id = ? AND es.departamento_id = ?
          ORDER BY u.nome'
    );
    $stmt->execute([$eventoId, $depId]);
    $escalados = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT u.id, u.nome, du.funcao
           FROM departamento_usuario du
           JOIN usuarios u ON u.id = du.usuario_id
          WHERE du.departamento_id = ? AND u.ativo = 1
            AND NOT EXISTS (
                SELECT 1 FROM escalas e2
                 WHERE e2.evento_id = ? AND e2.usuario_id = u.id)
          ORDER BY u.nome'
    );
    $stmt->execute([$depId, $eventoId]);
    $disponiveis = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT id, funcao, quantidade FROM vagas
          WHERE evento_id = ? AND departamento_id = ?
          ORDER BY (funcao = ''), funcao"
    );
    $stmt->execute([$eventoId, $depId]);
    $vagasEvento = $stmt->fetchAll();

    foreach (iaPendencias($pdo, $depId, [$eventoId]) as $p) {
        $faltamPorVaga[$p['vaga_id']] = $p['faltam'];
    }
}

$temRascunho = false;
foreach ($escalados as $linha) {
    if ($linha['status'] === 'RASCUNHO') {
        $temRascunho = true;
    }
}

$classeStatus = [
    'RASCUNHO' => 'secondary',
    'ENVIADA'  => 'primary',
    'ACEITA'   => 'success',
];

$periodoInicio = $iaResultado['inicio'] ?? date('Y-m-d');
$periodoFim    = $iaResultado['fim'] ?? date('Y-m-d', strtotime('+30 days'));

$tituloPagina = 'Montar escala';
include __DIR__ . '/includes/topo.php';
?>

<h1 class="h3 mb-3">Montar escala</h1>

<?php if (!$departamentos): ?>

    <div class="alert alert-warning">
        Você ainda não é líder de nenhum departamento. Peça ao administrador para
        marcar você como líder.
    </div>

<?php else: ?>

    <form method="GET" class="row g-2 mb-4">
        <div class="col-12 col-md-6">
            <select name="dep" class="form-select" required>
                <option value="">Escolha o departamento</option>
                <?php foreach ($departamentos as $d): ?>
                    <option value="<?= (int) $d['id'] ?>" <?= $dep && (int) $d['id'] === $depId ? 'selected' : '' ?>>
                        <?= e($d['nome']) ?> (<?= e($d['igreja_nome']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary">Ver eventos</button>
        </div>
    </form>

    <?php if ($dep): ?>

        <!-- Painel IA -->
        <div class="card mb-4 border-primary">
            <div class="card-body">
                <h2 class="h5">Gerar rascunho com IA</h2>
                <p class="small text-muted mb-3">
                    1) Defina as vagas de cada evento (ao escolher um evento, mais abaixo).
                    2) Escolha o período e peça o rascunho. 3) Revise, ajuste e publique.
                </p>

                <?php if (!iaConfigurada()): ?>
                    <div class="alert alert-warning mb-0">
                        A IA ainda não foi configurada. Falta a chave no arquivo de configuração do sistema.
                    </div>
                <?php else: ?>
                    <form method="POST" class="row g-2">
                        <?= csrfCampo() ?>
                        <input type="hidden" name="acao" value="gerar_ia">
                        <input type="hidden" name="dep" value="<?= $depId ?>">

                        <div class="col-6 col-md-3">
                            <label class="form-label" for="ia_inicio">De</label>
                            <input type="date" class="form-control" id="ia_inicio" name="data_inicio"
                                   value="<?= e($periodoInicio) ?>" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="ia_fim">Até</label>
                            <input type="date" class="form-control" id="ia_fim" name="data_fim"
                                   value="<?= e($periodoFim) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="ia_pedido">Pedido para a IA (opcional)</label>
                            <textarea class="form-control" id="ia_pedido" name="pedido" rows="2" maxlength="500"
                                      placeholder="Ex.: evite repetir quem serviu no último mês; procure alternar os cultos de domingo."></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"
                                    data-enviando="A IA está montando o rascunho...">
                                Gerar rascunho com IA
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <hr>

                <form method="POST" class="row g-2 align-items-end">
                    <?= csrfCampo() ?>
                    <input type="hidden" name="acao" value="publicar_periodo">
                    <input type="hidden" name="dep" value="<?= $depId ?>">
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="pub_inicio">Publicar rascunhos de</label>
                        <input type="date" class="form-control" id="pub_inicio" name="data_inicio"
                               value="<?= e($periodoInicio) ?>" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="pub_fim">até</label>
                        <input type="date" class="form-control" id="pub_fim" name="data_fim"
                               value="<?= e($periodoFim) ?>" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-success"
                                data-confirma="Publicar para os voluntários TODOS os rascunhos deste período?">
                            Publicar período
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-4">

            <!-- Coluna 1: Lista de Eventos -->
            <div class="col-12 col-lg-4">
                <h2 class="h5">Próximos eventos</h2>

                <?php if (!$eventos): ?>
                    <p class="text-muted">Nenhum evento futuro cadastrado para esta igreja.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($eventos as $ev): ?>
                            <a class="list-group-item list-group-item-action <?= $evento && (int) $ev['id'] === $eventoId ? 'active' : '' ?>"
                               href="lider_escalas.php?dep=<?= $depId ?>&evento=<?= (int) $ev['id'] ?>">
                                <div class="d-flex justify-content-between">
                                    <strong><?= e($ev['nome']) ?></strong>
                                    <span class="badge bg-light text-dark">
                                        <?= (int) $ev['total'] ?><?= (int) $ev['vagas'] > 0 ? ' / ' . (int) $ev['vagas'] : '' ?>
                                    </span>
                                </div>
                                <small>
                                    <?= e(dataBR($ev['data'])) ?>,
                                    <?= e(substr($ev['hora_inicio'], 0, 5)) ?> às
                                    <?= e(substr($ev['hora_fim'], 0, 5)) ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Coluna 2: Detalhes do Evento -->
            <div class="col-12 col-lg-8">
                <?php if (!$evento): ?>
                    <p class="text-muted">Escolha um evento ao lado para definir as vagas e montar a escala.</p>
                <?php else: ?>
                    <h2 class="h5">
                        <?= e($evento['nome']) ?>
                        <small class="text-muted">
                            em <?= e(dataBR($evento['data'])) ?>,
                            <?= e(substr($evento['hora_inicio'], 0, 5)) ?>
                        </small>
                    </h2>

                    <!-- Vagas -->
                    <h3 class="h6 mt-3">Vagas deste evento</h3>
                    <?php if (!$vagasEvento): ?>
                        <p class="text-muted small">Nenhuma vaga definida.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr><th>Função</th><th>Vagas</th><th>Faltam</th><th></th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($vagasEvento as $v): ?>
                                    <tr>
                                        <td><?= e($v['funcao'] !== '' ? $v['funcao'] : 'Qualquer função') ?></td>
                                        <td><?= (int) $v['quantidade'] ?></td>
                                        <td><?= (int) ($faltamPorVaga[(int) $v['id']] ?? 0) ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline">
                                                <?= csrfCampo() ?>
                                                <input type="hidden" name="acao" value="vaga_remover">
                                                <input type="hidden" name="dep" value="<?= $depId ?>">
                                                <input type="hidden" name="evento" value="<?= $eventoId ?>">
                                                <input type="hidden" name="vaga_id" value="<?= (int) $v['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        data-confirma="Remover esta vaga?">Remover</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="row g-2 mb-2">
                        <?= csrfCampo() ?>
                        <input type="hidden" name="acao" value="vaga_adicionar">
                        <input type="hidden" name="dep" value="<?= $depId ?>">
                        <input type="hidden" name="evento" value="<?= $eventoId ?>">
                        <div class="col-12 col-md-5">
                            <input type="text" name="funcao_vaga" class="form-control" maxlength="80"
                                   list="funcoes-dep" placeholder="Função (vazio = qualquer)">
                            <datalist id="funcoes-dep">
                                <?php foreach ($funcoesDoDep as $f): ?>
                                    <option value="<?= e($f) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-6 col-md-3">
                            <input type="number" name="quantidade" class="form-control" min="1" max="20" value="1" placeholder="Vagas" required>
                        </div>
                        <div class="col-6 col-md-4">
                            <button type="submit" class="btn btn-outline-secondary w-100">Salvar vaga</button>
                        </div>
                    </form>

                    <div class="d-flex gap-2 mb-4">
                        <form method="POST" class="d-inline">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="acao" value="vagas_copiar">
                            <input type="hidden" name="dep" value="<?= $depId ?>">
                            <input type="hidden" name="evento" value="<?= $eventoId ?>">
                            <button type="submit" class="btn btn-sm btn-outline-info"
                                    data-confirma="Copiar vagas para próximos eventos?">
                                Copiar vagas para próximos "<?= e($evento['nome']) ?>"
                            </button>
                        </form>
                        <form method="POST" class="d-inline">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="acao" value="vagas_remover_copias">
                            <input type="hidden" name="dep" value="<?= $depId ?>">
                            <input type="hidden" name="evento" value="<?= $eventoId ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    data-confirma="Desfazer cópia nos outros eventos?">
                                Desfazer cópias
                            </button>
                        </form>
                    </div>

                    <!-- Escalar manualmente -->
                    <h3 class="h6 mt-4">Escalar pessoa manualmente</h3>
                    <form method="POST" class="row g-2 mb-4">
                        <?= csrfCampo() ?>
                        <input type="hidden" name="acao" value="adicionar">
                        <input type="hidden" name="dep" value="<?= $depId ?>">
                        <input type="hidden" name="evento" value="<?= $eventoId ?>">

                        <div class="col-12 col-md-6">
                            <select name="usuario_id" class="form-select" required>
                                <option value="">Escolha a pessoa...</option>
                                <?php foreach ($disponiveis as $disp): ?>
                                    <option value="<?= (int) $disp['id'] ?>">
                                        <?= e($disp['nome']) ?> <?= !empty($disp['funcao']) ? '(' . e($disp['funcao']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <input type="text" name="funcao" class="form-control" maxlength="80" placeholder="Função específica (opcional)">
                        </div>
                        <div class="col-12 col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Adicionar</button>
                        </div>
                    </form>

                    <!-- Pessoas escaladas -->
                    <h3 class="h6 mt-4">Pessoas na escala deste evento</h3>
                    <?php if (!$escalados): ?>
                        <p class="text-muted">Ninguém na escala deste evento ainda.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Função</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($escalados as $esc): ?>
                                        <tr>
                                            <td><?= e($esc['nome']) ?></td>
                                            <td><?= e($esc['funcao'] ?: 'Geral') ?></td>
                                            <td>
                                                <span class="badge bg-<?= e($classeStatus[$esc['status']] ?? 'secondary') ?>">
                                                    <?= e($esc['status']) ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" class="d-inline">
                                                    <?= csrfCampo() ?>
                                                    <input type="hidden" name="acao" value="remover">
                                                    <input type="hidden" name="dep" value="<?= $depId ?>">
                                                    <input type="hidden" name="evento" value="<?= $eventoId ?>">
                                                    <input type="hidden" name="escala_id" value="<?= (int) $esc['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                                            data-confirma="Remover <?= e($esc['nome']) ?> da escala?">Remover</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($temRascunho): ?>
                            <form method="POST" class="mt-3">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="acao" value="publicar">
                                <input type="hidden" name="dep" value="<?= $depId ?>">
                                <input type="hidden" name="evento" value="<?= $eventoId ?>">
                                <button type="submit" class="btn btn-success"
                                        data-confirma="Publicar a escala deste evento para os voluntários?">
                                    Publicar escala deste evento
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/rodape.php'; ?>