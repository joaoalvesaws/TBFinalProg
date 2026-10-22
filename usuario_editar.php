<?php
// src/usuario_editar.php
// Edição de uma pessoa: dados pessoais, departamentos, funções e liderança.
// Acesso: líder, admin da igreja e admin geral (cada um dentro do seu alcance).
//
// Regras:
// - só edita perfis ABAIXO do seu (e nunca a si mesmo por aqui);
// - e-mail e liderança de departamento: só admin da igreja ou acima
//   (o e-mail define para onde vai o link de senha, por isso é restrito);
// - trocar o telefone zera o consentimento de WhatsApp: a pessoa precisa aceitar de novo;
// - não tira a pessoa de um departamento se ela ainda tem escalas futuras nele;
// - o perfil LIDER acompanha as liderancas: tem departamento liderado = LIDER,
//   não tem nenhum = VOLUNTARIO (perfis de admin não são alterados aqui).
//
// Depende de: includes/sessao.php e includes/util.php (nivelPerfil, podeGerenciarUsuario,
//   post, flash, redirecionar, auditar, ROTULOS_PERFIL), includes/topo.php e rodape.php.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';

$usuario         = exigirPerfil('LIDER');
$meuNivel        = nivelPerfil($usuario['perfil']);
$podeEmailELider = $meuNivel >= nivelPerfil('ADMIN_IGREJA');

// ---------------------------------------------------------------
// Funções auxiliares (prefixo "ue" = usuario editar)
// ---------------------------------------------------------------

// Departamentos que quem está logado pode gerenciar, indexados pelo id
function ueDepartamentos(PDO $pdo, array $usuario): array
{
    $sql = 'SELECT d.id, d.nome, d.igreja_id, i.nome AS igreja
              FROM departamentos d
              JOIN igrejas i ON i.id = d.igreja_id';

    if ($usuario['perfil'] === 'ADMIN_GERAL') {
        $stmt = $pdo->query($sql . ' ORDER BY i.nome, d.nome');
    } elseif ($usuario['perfil'] === 'ADMIN_IGREJA') {
        $stmt = $pdo->prepare($sql . ' WHERE d.igreja_id = ? ORDER BY d.nome');
        $stmt->execute([$usuario['igreja_id']]);
    } else {   // LIDER: só os que ele lidera
        $stmt = $pdo->prepare(
            $sql . ' JOIN departamento_usuario du ON du.departamento_id = d.id
                     WHERE du.usuario_id = ? AND du.e_lider = 1
                     ORDER BY d.nome'
        );
        $stmt->execute([$usuario['id']]);
    }

    $lista = [];
    foreach ($stmt->fetchAll() as $d) {
        $lista[(int) $d['id']] = $d;
    }
    return $lista;
}

// Mantém o perfil coerente com as lideranças
function ueSincronizarPerfil(PDO $pdo, int $pessoaId): void
{
    $stmt = $pdo->prepare('SELECT perfil FROM usuarios WHERE id = ?');
    $stmt->execute([$pessoaId]);
    $perfil = $stmt->fetchColumn();

    if (!in_array($perfil, ['VOLUNTARIO', 'LIDER'], true)) {
        return;   // admins não são alterados aqui
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM departamento_usuario WHERE usuario_id = ? AND e_lider = 1');
    $stmt->execute([$pessoaId]);
    $novo = ((int) $stmt->fetchColumn() > 0) ? 'LIDER' : 'VOLUNTARIO';

    if ($novo !== $perfil) {
        $pdo->prepare('UPDATE usuarios SET perfil = ? WHERE id = ?')->execute([$novo, $pessoaId]);
    }
}

function ueEscalasFuturas(PDO $pdo, int $pessoaId, int $depId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM escalas es
           JOIN eventos ev ON ev.id = es.evento_id
          WHERE es.usuario_id = ? AND es.departamento_id = ?
            AND TIMESTAMP(ev.data, ev.hora_fim) >= NOW()'
    );
    $stmt->execute([$pessoaId, $depId]);
    return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------------
// Quem está sendo editado (e se pode)
// ---------------------------------------------------------------
$alvoId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT id, igreja_id, nome, email, telefone, perfil, ativo, consentimento_whatsapp_em
       FROM usuarios WHERE id = ?'
);
$stmt->execute([$alvoId]);
$alvo = $stmt->fetch();

if (!$alvo
    || $alvoId === (int) $usuario['id']
    || nivelPerfil($alvo['perfil']) >= $meuNivel
    || !podeGerenciarUsuario($pdo, $usuario, $alvoId)) {
    http_response_code(403);
    exit('Você não pode editar esta pessoa.');
}

$deps = ueDepartamentos($pdo, $usuario);

// ---------------------------------------------------------------
// Ações (todas por POST com token CSRF)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    $acao = post('acao');

    // ---------- Dados pessoais ----------
    if ($acao === 'dados') {
        $nome     = post('nome');
        $telefone = preg_replace('/\D/', '', post('telefone'));
        $email    = mb_strtolower(post('email'));
        $erros    = [];

        if ($nome === '' || mb_strlen($nome) > 120) {
            $erros[] = 'Informe o nome (até 120 caracteres).';
        }
        if ($telefone !== '' && !preg_match('/^\d{10,11}$/', $telefone)) {
            $erros[] = 'Telefone inválido: use DDD + número.';
        }
        if ($podeEmailELider && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
            $erros[] = 'Informe um e-mail válido.';
        }

        if ($erros) {
            foreach ($erros as $mensagem) {
                flash('danger', $mensagem);
            }
        } else {
            $telefoneMudou = ((string) ($alvo['telefone'] ?? '')) !== $telefone;
            $emailMudou    = $podeEmailELider && $email !== mb_strtolower($alvo['email']);

            $sets   = ['nome = ?', 'telefone = ?'];
            $params = [$nome, $telefone === '' ? null : $telefone];

            if ($emailMudou) {
                $sets[]   = 'email = ?';
                $params[] = $email;
            }
            if ($telefoneMudou) {
                $sets[] = 'consentimento_whatsapp_em = NULL';   // novo número, novo consentimento
            }
            $params[] = $alvoId;

            try {
                $pdo->prepare('UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = ?')
                    ->execute($params);

                // Só registra QUAIS campos mudaram, sem copiar dados pessoais para a auditoria
                auditar($pdo, (int) $usuario['id'], 'USUARIO_EDITADO', 'usuarios', $alvoId, [
                    'nome_mudou'     => $nome !== $alvo['nome'],
                    'email_mudou'    => $emailMudou,
                    'telefone_mudou' => $telefoneMudou,
                ]);

                flash('success', 'Dados salvos.');
                if ($telefoneMudou && !empty($alvo['consentimento_whatsapp_em'])) {
                    flash('warning', 'O telefone mudou, então a pessoa precisará aceitar de novo receber avisos por WhatsApp.');
                }
            } catch (PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062) {   // e-mail repetido
                    flash('danger', 'Já existe uma conta com este e-mail.');
                } else {
                    error_log('Erro ao editar usuário: ' . $ex->getMessage());
                    flash('danger', 'Não foi possível salvar. Tente novamente.');
                }
            }
        }

    // ---------- Vínculos com departamentos ----------
    } elseif (in_array($acao, ['vinculo_salvar', 'vinculo_remover', 'vinculo_incluir'], true)) {
        $depId  = (int) post('departamento_id');
        $dep    = $deps[$depId] ?? null;
        $funcao = post('funcao');

        $s = $pdo->prepare('SELECT e_lider FROM departamento_usuario WHERE departamento_id = ? AND usuario_id = ?');
        $s->execute([$depId, $alvoId]);
        $vinculo = $s->fetch();

        if (!$dep) {
            flash('danger', 'Departamento inválido.');
        } elseif ((int) $dep['igreja_id'] !== (int) $alvo['igreja_id']) {
            flash('danger', 'O departamento não pertence à igreja desta pessoa.');
        } elseif (mb_strlen($funcao) > 80) {
            flash('danger', 'A função pode ter até 80 caracteres.');

        } elseif ($acao === 'vinculo_incluir') {
            if ($vinculo) {
                flash('warning', 'A pessoa já faz parte deste departamento.');
            } else {
                $pdo->prepare(
                    'INSERT INTO departamento_usuario (departamento_id, usuario_id, funcao, e_lider)
                     VALUES (?, ?, ?, 0)'
                )->execute([$depId, $alvoId, $funcao === '' ? null : $funcao]);
                auditar($pdo, (int) $usuario['id'], 'USUARIO_VINCULADO_DEPARTAMENTO', 'usuarios', $alvoId,
                        ['departamento_id' => $depId]);
                flash('success', 'Pessoa incluída no departamento ' . $dep['nome'] . '.');
            }

        } elseif (!$vinculo) {
            flash('danger', 'A pessoa não faz parte deste departamento.');

        } elseif ($acao === 'vinculo_salvar') {
            try {
                $pdo->beginTransaction();

                if ($podeEmailELider) {
                    $lider = post('e_lider') === '1' ? 1 : 0;
                    $pdo->prepare(
                        'UPDATE departamento_usuario SET funcao = ?, e_lider = ?
                          WHERE departamento_id = ? AND usuario_id = ?'
                    )->execute([$funcao === '' ? null : $funcao, $lider, $depId, $alvoId]);
                } else {
                    $pdo->prepare(
                        'UPDATE departamento_usuario SET funcao = ?
                          WHERE departamento_id = ? AND usuario_id = ?'
                    )->execute([$funcao === '' ? null : $funcao, $depId, $alvoId]);
                }

                ueSincronizarPerfil($pdo, $alvoId);
                auditar($pdo, (int) $usuario['id'], 'USUARIO_VINCULO_ALTERADO', 'usuarios', $alvoId,
                        ['departamento_id' => $depId]);

                $pdo->commit();
                flash('success', 'Departamento ' . $dep['nome'] . ' atualizado.');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Erro ao salvar vínculo: ' . $ex->getMessage());
                flash('danger', 'Não foi possível salvar. Tente novamente.');
            }

        } else {   // vinculo_remover
            if (ueEscalasFuturas($pdo, $alvoId, $depId) > 0) {
                flash('danger', 'Esta pessoa ainda tem escalas futuras em ' . $dep['nome']
                    . '. Remova-as em "Montar escala" antes de tirá-la do departamento.');
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM departamento_usuario WHERE departamento_id = ? AND usuario_id = ?')
                        ->execute([$depId, $alvoId]);
                    ueSincronizarPerfil($pdo, $alvoId);
                    auditar($pdo, (int) $usuario['id'], 'USUARIO_DESVINCULADO_DEPARTAMENTO', 'usuarios',
                            $alvoId, ['departamento_id' => $depId]);
                    $pdo->commit();
                    flash('success', 'Pessoa removida do departamento ' . $dep['nome'] . '.');
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Erro ao remover vínculo: ' . $ex->getMessage());
                    flash('danger', 'Não foi possível remover. Tente novamente.');
                }
            }
        }
    }

    redirecionar('/usuario_editar.php?id=' . $alvoId);
    exit;
}

// ---------------------------------------------------------------
// Dados da tela
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT du.departamento_id, du.funcao, du.e_lider, d.nome AS departamento
       FROM departamento_usuario du
       JOIN departamentos d ON d.id = du.departamento_id
      WHERE du.usuario_id = ?
      ORDER BY d.nome'
);
$stmt->execute([$alvoId]);

$vinculos     = [];   // só os departamentos que quem edita pode gerenciar
$jaNosDeptos  = [];
foreach ($stmt->fetchAll() as $v) {
    $jaNosDeptos[(int) $v['departamento_id']] = true;
    if (isset($deps[(int) $v['departamento_id']])) {
        $vinculos[] = $v;
    }
}

$incluiveis = [];     // departamentos da mesma igreja onde a pessoa ainda não está
foreach ($deps as $d) {
    if ((int) $d['igreja_id'] === (int) $alvo['igreja_id'] && !isset($jaNosDeptos[(int) $d['id']])) {
        $incluiveis[] = $d;
    }
}

$tituloPagina = 'Editar pessoa';
include __DIR__ . '/includes/topo.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Editar pessoa</h1>
    <a href="/usuarios.php" class="btn btn-outline-secondary btn-sm">Voltar para Pessoas</a>
</div>

<div class="row g-4">

    <!-- Dados pessoais -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <h2 class="h5">Dados</h2>
                <p class="small text-muted">
                    Perfil: <?= e(ROTULOS_PERFIL[$alvo['perfil']] ?? $alvo['perfil']) ?>
                    · <?= $alvo['ativo'] ? 'Conta ativa' : 'Conta desativada' ?>
                </p>

                <form method="POST" autocomplete="off">
                    <?= csrfCampo() ?>
                    <input type="hidden" name="acao" value="dados">
                    <input type="hidden" name="id" value="<?= (int) $alvoId ?>">

                    <div class="mb-3">
                        <label for="nome" class="form-label">Nome</label>
                        <input type="text" class="form-control" id="nome" name="nome"
                               maxlength="120" required value="<?= e($alvo['nome']) ?>">
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">E-mail</label>
                        <input type="email" class="form-control" id="email" name="email"
                               maxlength="190" value="<?= e($alvo['email']) ?>"
                               <?= $podeEmailELider ? 'required' : 'disabled' ?>>
                        <?php if (!$podeEmailELider): ?>
                            <div class="form-text">Só o administrador da igreja pode alterar o e-mail.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="telefone" class="form-label">WhatsApp</label>
                        <input type="tel" class="form-control" id="telefone" name="telefone"
                               data-mascara="telefone" placeholder="(49) 99999-9999"
                               value="<?= e($alvo['telefone'] ?? '') ?>">
                        <div class="form-text">
                            Se o número mudar, a pessoa precisará aceitar de novo receber avisos por WhatsApp.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" data-enviando="Salvando...">Salvar dados</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Departamentos -->
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5">Departamentos</h2>

                <?php if (!$vinculos): ?>
                    <p class="text-muted">A pessoa não está em nenhum departamento que você gerencia.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Departamento</th>
                                    <th>Função</th>
                                    <?php if ($podeEmailELider): ?><th>Líder</th><?php endif; ?>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($vinculos as $v): ?>
                                <tr>
                                    <td>
                                        <?= e($v['departamento']) ?>
                                        <?php if (!$podeEmailELider && $v['e_lider']): ?>
                                            <span class="badge text-bg-info">Líder</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" id="v<?= (int) $v['departamento_id'] ?>">
                                            <?= csrfCampo() ?>
                                            <input type="hidden" name="acao" value="vinculo_salvar">
                                            <input type="hidden" name="id" value="<?= (int) $alvoId ?>">
                                            <input type="hidden" name="departamento_id" value="<?= (int) $v['departamento_id'] ?>">
                                            <input type="text" class="form-control form-control-sm" name="funcao"
                                                   maxlength="80" placeholder="ex.: Camera 1"
                                                   value="<?= e($v['funcao'] ?? '') ?>">
                                        </form>
                                    </td>
                                    <?php if ($podeEmailELider): ?>
                                        <td>
                                            <input type="checkbox" class="form-check-input" name="e_lider" value="1"
                                                   form="v<?= (int) $v['departamento_id'] ?>"
                                                   <?= $v['e_lider'] ? 'checked' : '' ?>>
                                        </td>
                                    <?php endif; ?>
                                    <td class="text-end text-nowrap">
                                        <button type="submit" class="btn btn-sm btn-outline-primary"
                                                form="v<?= (int) $v['departamento_id'] ?>">Salvar</button>
                                        <form method="POST" class="d-inline">
                                            <?= csrfCampo() ?>
                                            <input type="hidden" name="acao" value="vinculo_remover">
                                            <input type="hidden" name="id" value="<?= (int) $alvoId ?>">
                                            <input type="hidden" name="departamento_id" value="<?= (int) $v['departamento_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    data-confirma="Tirar esta pessoa do departamento <?= e($v['departamento']) ?>?">
                                                Tirar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($podeEmailELider): ?>
                        <p class="small text-muted mb-0">
                            Marcar "Líder" libera a tela de montar escala daquele departamento
                            e passa o perfil da pessoa para Líder. Sem nenhuma liderança, volta a ser Voluntário.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($incluiveis): ?>
            <div class="card">
                <div class="card-body">
                    <h2 class="h5">Incluir em outro departamento</h2>
                    <form method="POST" class="row g-2">
                        <?= csrfCampo() ?>
                        <input type="hidden" name="acao" value="vinculo_incluir">
                        <input type="hidden" name="id" value="<?= (int) $alvoId ?>">

                        <div class="col-12 col-md-5">
                            <select name="departamento_id" class="form-select" required>
                                <option value="">Escolha...</option>
                                <?php foreach ($incluiveis as $d): ?>
                                    <option value="<?= (int) $d['id'] ?>"><?= e($d['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <input type="text" name="funcao" class="form-control" maxlength="80"
                                   placeholder="Função (opcional)">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary" data-enviando="Incluindo...">Incluir</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>