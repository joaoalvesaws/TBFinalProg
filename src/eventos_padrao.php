<?php
// src/eventos_padrao.php
// Cultos padrão: o administrador define UMA vez os cultos que se repetem
// (ex.: domingo 10h, quarta 19h30, sábado 9h). O botão "Gerar eventos" cria
// todos os eventos de um período de uma vez, sem duplicar os que já existem.
//
// Depende de:
//   includes/sessao.php
//   includes/util.php  (post, flash, antigo, redirecionar, auditar,
//                       igrejaDoContexto, listarIgrejas, dataValida)
//   tabela eventos_padrao (db/004_eventos_padrao.sql)
//   includes/topo.php e includes/rodape.php

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';

$usuario = exigirPerfil('ADMIN_IGREJA');
$ehGeral = $usuario['perfil'] === 'ADMIN_GERAL';

const MAX_DIAS_GERACAO = 180;   // maior período por geração

$diasSemana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira',
               'Quinta-feira', 'Sexta-feira', 'Sábado'];

function horaPadraoValida(string $hora): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora);
}

function buscarPadrao(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM eventos_padrao WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------
// Ações (todas por POST com token CSRF)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    $acao = post('acao');

    // ---------- Cadastrar um culto padrão ----------
    if ($acao === 'criar_padrao') {
        $nome     = post('nome');
        $dia      = post('dia_semana');
        $inicio   = post('hora_inicio');
        $fim      = post('hora_fim');
        $igrejaId = igrejaDoContexto($pdo, $usuario, $_POST['igreja_id'] ?? 0);
        $erros    = [];

        if (!$igrejaId) {
            $erros[] = $ehGeral ? 'Escolha a igreja.' : 'Sua conta não está vinculada a uma igreja.';
        }
        if ($nome === '' || mb_strlen($nome) > 120) {
            $erros[] = 'Informe o nome do culto (até 120 caracteres).';
        }
        if (!ctype_digit($dia) || (int) $dia > 6) {
            $erros[] = 'Escolha o dia da semana.';
        }
                if (!horaPadraoValida($inicio) || !horaPadraoValida($fim)) {
            $erros[] = 'Informe o horário de início e de fim.';
        } elseif ($fim <= $inicio) {
            $erros[] = 'O horário de fim deve ser depois do início. Para culto que passa da meia-noite, cadastre o evento manualmente.';
        }

        if ($erros) {
            foreach ($erros as $mensagem) {
                flash('danger', $mensagem);
            }
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO eventos_padrao (igreja_id, nome, dia_semana, hora_inicio, hora_fim)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$igrejaId, $nome, (int) $dia, $inicio . ':00', $fim . ':00']);

                auditar($pdo, (int) $usuario['id'], 'EVENTO_PADRAO_CRIADO', 'eventos_padrao',
                        (int) $pdo->lastInsertId(),
                        ['nome' => $nome, 'dia_semana' => (int) $dia, 'igreja_id' => $igrejaId]);

                flash('success', 'Culto padrão cadastrado.');
                redirecionar('/eventos_padrao.php');
            } catch (PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062) {   // já existe igual
                    flash('danger', 'Já existe um culto padrão com esse nome, dia e horário.');
                } else {
                    error_log('Erro ao criar culto padrão: ' . $ex->getMessage());
                    flash('danger', 'Não foi possível salvar. Tente novamente.');
                }
            }
        }

    // ---------- Ativar/desativar ou remover um culto padrão ----------
    } elseif ($acao === 'alternar_padrao' || $acao === 'remover_padrao') {
        $padrao = buscarPadrao($pdo, (int) post('id'));

        if (!$padrao || !podeAcessarIgreja($usuario, (int) $padrao['igreja_id'])) {
            flash('danger', 'Culto padrão não encontrado.');
        } elseif ($acao === 'alternar_padrao') {
            $novo = $padrao['ativo'] ? 0 : 1;
            $pdo->prepare('UPDATE eventos_padrao SET ativo = ? WHERE id = ?')->execute([$novo, $padrao['id']]);
            auditar($pdo, (int) $usuario['id'], $novo ? 'EVENTO_PADRAO_ATIVADO' : 'EVENTO_PADRAO_DESATIVADO',
                    'eventos_padrao', (int) $padrao['id']);
            flash('success', $novo ? 'Culto padrão ativado.' : 'Culto padrão desativado.');
        } else {
            $pdo->prepare('DELETE FROM eventos_padrao WHERE id = ?')->execute([$padrao['id']]);
            auditar($pdo, (int) $usuario['id'], 'EVENTO_PADRAO_REMOVIDO', 'eventos_padrao',
                    (int) $padrao['id'], ['nome' => $padrao['nome']]);
            flash('success', 'Culto padrão removido. Os eventos já gerados continuam.');
        }
        redirecionar('/eventos_padrao.php');

    // ---------- Gerar os eventos de um período ----------
    } elseif ($acao === 'gerar') {
        $igrejaId = igrejaDoContexto($pdo, $usuario, $_POST['igreja_id'] ?? 0);
        $inicio   = post('data_inicio');
        $fim      = post('data_fim');
        $hoje     = date('Y-m-d');
        $erros    = [];

        if (!$igrejaId) {
            $erros[] = $ehGeral ? 'Escolha a igreja.' : 'Sua conta não está vinculada a uma igreja.';
        }
        if (!dataValida($inicio) || !dataValida($fim)) {
            $erros[] = 'Informe as datas de início e de fim.';
        } else {
            if ($inicio < $hoje) {
                $inicio = $hoje;   // nunca cria evento no passado
            }
            if ($fim < $inicio) {
                $erros[] = 'A data final não pode ser anterior à inicial (nem estar no passado).';
            } elseif ((new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($fim))->days > MAX_DIAS_GERACAO) {
                $erros[] = 'Escolha um período de no máximo ' . MAX_DIAS_GERACAO . ' dias.';
            }
        }

        $padroes = [];
        if (!$erros) {
            $stmt = $pdo->prepare('SELECT * FROM eventos_padrao WHERE igreja_id = ? AND ativo = 1');
            $stmt->execute([$igrejaId]);
            foreach ($stmt->fetchAll() as $p) {
                $padroes[(int) $p['dia_semana']][] = $p;
            }
            if (!$padroes) {
                $erros[] = 'Esta igreja não tem nenhum culto padrão ativo. Cadastre ao menos um.';
            }
        }

        if ($erros) {
            foreach ($erros as $mensagem) {
                flash('danger', $mensagem);
            }
        } else {
            $criados      = 0;
            $jaExistiam   = 0;

            try {
                $pdo->beginTransaction();

                $existe = $pdo->prepare(
                    'SELECT 1 FROM eventos WHERE igreja_id = ? AND nome = ? AND data = ? LIMIT 1'
                );
                $inserir = $pdo->prepare(
                    'INSERT INTO eventos (igreja_id, nome, data, hora_inicio, hora_fim)
                     VALUES (?, ?, ?, ?, ?)'
                );

                $dia    = new DateTimeImmutable($inicio);
                $limite = new DateTimeImmutable($fim);

                while ($dia <= $limite) {
                    $dataSql = $dia->format('Y-m-d');

                    foreach ($padroes[(int) $dia->format('w')] ?? [] as $p) {
                        // Mesmo nome na mesma data = já existe (evita duplicar)
                        $existe->execute([$igrejaId, $p['nome'], $dataSql]);
                        $achou = (bool) $existe->fetchColumn();
                        $existe->closeCursor();

                        if ($achou) {
                            $jaExistiam++;
                            continue;
                        }

                        $inserir->execute([$igrejaId, $p['nome'], $dataSql, $p['hora_inicio'], $p['hora_fim']]);
                        $criados++;
                    }

                    $dia = $dia->modify('+1 day');
                }

                auditar($pdo, (int) $usuario['id'], 'EVENTOS_GERADOS', 'igrejas', $igrejaId, [
                    'periodo'      => [$inicio, $fim],
                    'criados'      => $criados,
                    'ja_existiam'  => $jaExistiam,
                ]);

                $pdo->commit();
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Erro ao gerar eventos: ' . $ex->getMessage());
                flash('danger', 'Não foi possível gerar os eventos. Nada foi criado. Tente novamente.');
                redirecionar('/eventos_padrao.php');
            }

            if ($criados === 0) {
                flash('warning', 'Nenhum evento novo: todos os eventos desse período já existiam.');
            } else {
                $texto = $criados . ' evento(s) criado(s) de ' . date('d/m/Y', strtotime($inicio))
                       . ' a ' . date('d/m/Y', strtotime($fim)) . '.';
                if ($jaExistiam > 0) {
                    $texto .= ' ' . $jaExistiam . ' já existia(m) e foi(ram) mantido(s).';
                }
                flash('success', $texto);
            }
            redirecionar('/eventos_padrao.php');
        }
    }
}

// ---------------------------------------------------------------
// Dados da tela
// ---------------------------------------------------------------
$sql = 'SELECT p.id, p.nome, p.dia_semana, p.hora_inicio, p.hora_fim, p.ativo, i.nome AS igreja
          FROM eventos_padrao p
          JOIN igrejas i ON i.id = p.igreja_id';

if ($ehGeral) {
    $stmt = $pdo->query($sql . ' ORDER BY i.nome, p.dia_semana, p.hora_inicio');
} else {
    $stmt = $pdo->prepare($sql . ' WHERE p.igreja_id = ? ORDER BY p.dia_semana, p.hora_inicio');
    $stmt->execute([$usuario['igreja_id']]);
}
$padroes = $stmt->fetchAll();
$igrejas = $ehGeral ? listarIgrejas($pdo) : [];

$periodoInicio = date('Y-m-d');
$periodoFim    = date('Y-m-d', strtotime('+90 days'));

$tituloPagina = 'Cultos padrão';
include __DIR__ . '/includes/topo.php';
?>

<h1 class="h3 mb-3">Cultos padrão</h1>
<p class="text-muted">
    Cadastre uma vez os cultos que se repetem toda semana e gere os eventos de vários
    meses com um clique.
</p>

<div class="row g-4">

    <!-- Coluna 1: cadastrar culto padrão e gerar eventos -->
    <div class="col-lg-5">

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5">1. Cadastrar culto padrão</h2>

                <form method="POST" autocomplete="off" class="row g-2">
                    <?= csrfCampo() ?>
                    <input type="hidden" name="acao" value="criar_padrao">

                    <?php if ($ehGeral): ?>
                        <div class="col-12">
                            <label for="padrao_igreja" class="form-label">Igreja</label>
                            <select class="form-select" id="padrao_igreja" name="igreja_id" required>
                                <option value="">Escolha...</option>
                                <?php foreach ($igrejas as $i): ?>
                                    <option value="<?= (int) $i['id'] ?>"><?= e($i['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="col-12">
                        <label for="nome" class="form-label">Nome (ex.: Culto de Domingo)</label>
                        <input type="text" class="form-control" id="nome" name="nome"
                               maxlength="120" required value="<?= antigo('nome') ?>">
                    </div>

                    <div class="col-12">
                        <label for="dia_semana" class="form-label">Dia da semana</label>
                        <select class="form-select" id="dia_semana" name="dia_semana" required>
                            <option value="">Escolha...</option>
                            <?php foreach ($diasSemana as $numero => $rotulo): ?>
                                <option value="<?= (int) $numero ?>"><?= e($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6">
                        <label for="hora_inicio" class="form-label">Início</label>
                        <input type="time" class="form-control" id="hora_inicio" name="hora_inicio" required>
                    </div>
                    <div class="col-6">
                        <label for="hora_fim" class="form-label">Fim</label>
                        <input type="time" class="form-control" id="hora_fim" name="hora_fim" required>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary" data-enviando="Salvando...">
                            Cadastrar culto padrão
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-primary">
            <div class="card-body">
                <h2 class="h5">2. Gerar eventos do período</h2>
                <p class="small text-muted">
                    Cria um evento para cada culto padrão <strong>ativo</strong> no período. Eventos com
                    o mesmo nome na mesma data não são duplicados, e datas passadas são ignoradas.
                </p>

                <form method="POST" data-intervalo-datas class="row g-2">
                    <?= csrfCampo() ?>
                    <input type="hidden" name="acao" value="gerar">

                    <?php if ($ehGeral): ?>
                        <div class="col-12">
                            <label for="gerar_igreja" class="form-label">Igreja</label>
                            <select class="form-select" id="gerar_igreja" name="igreja_id" required>
                                <option value="">Escolha...</option>
                                <?php foreach ($igrejas as $i): ?>
                                    <option value="<?= (int) $i['id'] ?>"><?= e($i['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="col-6">
                        <label for="data_inicio" class="form-label">De</label>
                        <input type="date" class="form-control" id="data_inicio" name="data_inicio"
                               value="<?= e($periodoInicio) ?>" required>
                    </div>
                    <div class="col-6">
                        <label for="data_fim" class="form-label">Até</label>
                        <input type="date" class="form-control" id="data_fim" name="data_fim"
                               value="<?= e($periodoFim) ?>" required>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-success"
                                data-enviando="Gerando eventos..."
                                data-confirma="Criar os eventos dos cultos padrão ativos neste período?">
                            Gerar eventos
                        </button>
                        <div class="form-text">Período de no máximo <?= (int) MAX_DIAS_GERACAO ?> dias.</div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Coluna 2: lista de cultos padrão -->
    <div class="col-lg-7">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <?php if ($ehGeral): ?><th>Igreja</th><?php endif; ?>
                        <th>Culto</th>
                        <th>Dia</th>
                        <th>Horário</th>
                        <th>Situação</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($padroes as $p): ?>
                    <tr>
                        <?php if ($ehGeral): ?><td><?= e($p['igreja']) ?></td><?php endif; ?>
                        <td><?= e($p['nome']) ?></td>
                        <td><?= e($diasSemana[(int) $p['dia_semana']] ?? '-') ?></td>
                        <td>
                            <?= e(substr($p['hora_inicio'], 0, 5)) ?> às
                            <?= e(substr($p['hora_fim'], 0, 5)) ?>
                        </td>
                        <td>
                            <?php if ($p['ativo']): ?>
                                <span class="badge text-bg-success">Ativo</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Desativado</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <form method="POST" class="d-inline">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="acao" value="alternar_padrao">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <?= $p['ativo'] ? 'Desativar' : 'Ativar' ?>
                                </button>
                            </form>
                            <form method="POST" class="d-inline">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="acao" value="remover_padrao">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        data-confirma="Remover este culto padrão? Os eventos já gerados continuam.">
                                    Remover
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$padroes): ?>
                    <tr>
                        <td colspan="<?= $ehGeral ? 6 : 5 ?>" class="text-muted">
                            Nenhum culto padrão cadastrado ainda.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>