<?php
// src/usuarios.php
// Cadastro de pessoas. Só líder, admin da igreja e admin geral acessam.
// A pessoa NÃO recebe senha: recebe um e-mail com um link para definir a própria.
//
// Regras:
// - cada um só cadastra perfis abaixo do seu (ver perfisQuePodeCriar em util.php)
// - líder só cadastra voluntários nos departamentos que ele lidera
// - admin da igreja só mexe na própria igreja
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        $log = "Data: " . date('Y-m-d H:i:s') . "\n";
        $log .= "Erro: " . $error['message'] . "\n";
        $log .= "Arquivo: " . $error['file'] . " na linha " . $error['line'] . "\n\n";
        file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/erro_php.txt', $log, FILE_APPEND);
    }
});

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';

$usuario          = exigirPerfil('LIDER');
$ehGeral          = $usuario['perfil'] === 'ADMIN_GERAL';
$meuNivel         = nivelPerfil($usuario['perfil']);
$perfisPermitidos = perfisQuePodeCriar($usuario);

// ---------------------------------------------------------------
// Departamentos que esta pessoa pode usar ao cadastrar
// ---------------------------------------------------------------
$sqlDeptos = 'SELECT d.id, d.nome, d.igreja_id, i.nome AS igreja
                FROM departamentos d
                JOIN igrejas i ON i.id = d.igreja_id';

if ($ehGeral) {
    $stmt = $pdo->query($sqlDeptos . ' ORDER BY i.nome, d.nome');
} elseif ($usuario['perfil'] === 'ADMIN_IGREJA') {
    $stmt = $pdo->prepare($sqlDeptos . ' WHERE d.igreja_id = ? ORDER BY d.nome');
    $stmt->execute([$usuario['igreja_id']]);
} else {   // LIDER: só os departamentos que ele lidera
    $stmt = $pdo->prepare(
        $sqlDeptos . ' JOIN departamento_usuario du ON du.departamento_id = d.id
                      WHERE du.usuario_id = ? AND du.e_lider = 1
                      ORDER BY d.nome'
    );
    $stmt->execute([$usuario['id']]);
}
$departamentos = $stmt->fetchAll();

// ---------------------------------------------------------------
// Ações (todas por POST com token CSRF)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    $acao = post('acao');

    // ---------- Cadastrar ----------
    if ($acao === 'criar') {
        $nome     = post('nome');
        $email    = mb_strtolower(post('email'));
        $telefone = preg_replace('/\D/', '', post('telefone'));   // só números
        $perfil   = post('perfil');
        $funcao   = post('funcao');
        $deptoId  = (int) post('departamento_id');
        $erros    = [];

        if ($nome === '' || mb_strlen($nome) > 120) {
            $erros[] = 'Informe o nome (até 120 caracteres).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $erros[] = 'Informe um e-mail válido.';
        }
        if ($telefone !== '' && !preg_match('/^\d{10,11}$/', $telefone)) {
            $erros[] = 'Telefone inválido: use DDD + número.';
        }
        if (!in_array($perfil, $perfisPermitidos, true)) {
            $erros[] = 'Você não pode cadastrar esse perfil.';
        }
        if (mb_strlen($funcao) > 80) {
            $erros[] = 'A função pode ter até 80 caracteres.';
        }

        // O departamento escolhido precisa estar na lista que esta pessoa pode usar
        $depto = null;
        foreach ($departamentos as $d) {
            if ((int) $d['id'] === $deptoId) {
                $depto = $d;
                break;
            }
        }
        if ($deptoId > 0 && !$depto) {
            $erros[] = 'Departamento inválido.';
        }
        if (!$depto && ($usuario['perfil'] === 'LIDER' || $perfil === 'LIDER')) {
            $erros[] = 'Escolha o departamento (todo líder e todo voluntário cadastrado por líder precisa de um).';
        }

        // Igreja: admin geral escolhe; os outros usam a própria
        $igrejaId = igrejaDoContexto($pdo, $usuario, $_POST['igreja_id'] ?? 0);
        if (!$igrejaId) {
            $erros[] = $ehGeral ? 'Escolha a igreja.' : 'Sua conta não está vinculada a uma igreja.';
        } elseif ($depto && (int) $depto['igreja_id'] !== $igrejaId) {
            $erros[] = 'O departamento não pertence à igreja escolhida.';
        }
                // O e-mail já tem conta? Então não cria outra: só inclui a pessoa no departamento escolhido.
        if (!$erros) {
            $s = $pdo->prepare('SELECT id, nome, igreja_id, perfil, ativo FROM usuarios WHERE email = ?');
            $s->execute([$email]);
            $existente = $s->fetch();

            if ($existente) {
                if (!$depto || (int) $existente['igreja_id'] !== (int) $depto['igreja_id']) {
                    // Sem departamento ou de outra igreja: não revela nada sobre a conta
                    $erros[] = 'Já existe uma conta com este e-mail.';
                } elseif (nivelPerfil($existente['perfil']) >= $meuNivel) {
                    $erros[] = 'Esta pessoa já tem conta e você não pode alterar o vínculo dela.';
                } elseif (!$existente['ativo']) {
                    $erros[] = 'Esta pessoa tem conta, mas ela está desativada. Reative antes de incluí-la em outro departamento.';
                } else {
                    $ja = $pdo->prepare(
                        'SELECT 1 FROM departamento_usuario WHERE departamento_id = ? AND usuario_id = ?'
                    );
                    $ja->execute([$depto['id'], $existente['id']]);

                    if ($ja->fetchColumn()) {
                        $erros[] = $existente['nome'] . ' já faz parte deste departamento.';
                    } else {
                        $pdo->prepare(
                            'INSERT INTO departamento_usuario (departamento_id, usuario_id, funcao, e_lider)
                             VALUES (?, ?, ?, 0)'
                        )->execute([$depto['id'], $existente['id'], $funcao === '' ? null : $funcao]);

                        auditar($pdo, (int) $usuario['id'], 'USUARIO_VINCULADO_DEPARTAMENTO', 'usuarios',
                                (int) $existente['id'], ['departamento_id' => (int) $depto['id']]);

                        flash('success', $existente['nome'] . ' já tinha conta e foi incluído(a) no departamento '
                            . $depto['nome'] . '. Nenhum convite foi enviado e o perfil não mudou.');
                        redirecionar('/usuarios.php');
                        exit;
                    }
                }
            }
        }

        if ($erros) {
            foreach ($erros as $mensagem) {
                flash('danger', $mensagem);
            }
        } else {
            try {
                $pdo->beginTransaction();

                // Senha "inutilizável": ninguém consegue entrar até definir a própria pelo link
                $senhaInutil = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

                $pdo->prepare(
                    'INSERT INTO usuarios (igreja_id, nome, email, telefone, senha_hash, perfil)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$igrejaId, $nome, $email, $telefone === '' ? null : $telefone,
                            $senhaInutil, $perfil]);
                $novoId = (int) $pdo->lastInsertId();

                if ($depto) {
                    $pdo->prepare(
                        'INSERT INTO departamento_usuario (departamento_id, usuario_id, funcao, e_lider)
                         VALUES (?, ?, ?, ?)'
                    )->execute([$depto['id'], $novoId, $funcao === '' ? null : $funcao,
                                $perfil === 'LIDER' ? 1 : 0]);
                }

                $link = enviarConviteSenha($pdo, ['id' => $novoId, 'nome' => $nome], 'CONVITE');

                auditar($pdo, (int) $usuario['id'], 'USUARIO_CRIADO', 'usuarios', $novoId,
                        ['perfil' => $perfil, 'igreja_id' => $igrejaId,
                         'departamento_id' => $depto['id'] ?? null]);

                $pdo->commit();

                flash('success', 'Pessoa cadastrada. Um convite foi colocado na fila de e-mails.');
                avisarLinkEmDesenvolvimento($link);
                redirecionar('/usuarios.php');

            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (($ex->errorInfo[1] ?? 0) === 1062) {   // e-mail repetido
                    flash('danger', 'Já existe uma conta com este e-mail.');
                } else {
                    error_log('Erro ao cadastrar usuário: ' . $ex->getMessage());
                    flash('danger', 'Não foi possível salvar. Tente novamente.');
                }
            }
        }

    // ---------- Reenviar convite / Ativar-desativar ----------
    } elseif ($acao === 'reenviar' || $acao === 'alternar_ativo') {
        $alvoId = (int) post('id');

        if (!podeGerenciarUsuario($pdo, $usuario, $alvoId)) {
            flash('danger', 'Você não pode gerenciar esta pessoa.');
        } else {
            $s = $pdo->prepare('SELECT id, nome, ativo FROM usuarios WHERE id = ?');
            $s->execute([$alvoId]);
            $alvo = $s->fetch();

            if ($acao === 'reenviar') {
                if (!$alvo['ativo']) {
                    flash('warning', 'Esta conta está desativada. Reative antes de reenviar o convite.');
                } else {
                    $link = enviarConviteSenha($pdo, $alvo, 'CONVITE');
                    auditar($pdo, (int) $usuario['id'], 'CONVITE_REENVIADO', 'usuarios', $alvoId);
                    flash('success', 'Novo convite colocado na fila de e-mails.');
                    avisarLinkEmDesenvolvimento($link);
                }
            } else {
                $novo = $alvo['ativo'] ? 0 : 1;
                $pdo->prepare('UPDATE usuarios SET ativo = ? WHERE id = ?')->execute([$novo, $alvoId]);
                auditar($pdo, (int) $usuario['id'], $novo ? 'USUARIO_REATIVADO' : 'USUARIO_DESATIVADO',
                        'usuarios', $alvoId);
                flash('success', $novo ? 'Conta reativada.' : 'Conta desativada.');
            }
        }
        redirecionar('/usuarios.php');
    }
}

// ---------------------------------------------------------------
// Lista de pessoas (cada perfil vê só o seu alcance)
// ---------------------------------------------------------------
// Departamentos de cada pessoa (o líder só vê os que ele lidera)
$filtroLider = $usuario['perfil'] === 'LIDER'
    ? ' AND d2.id IN (SELECT departamento_id FROM departamento_usuario WHERE usuario_id = '
      . (int) $usuario['id'] . ' AND e_lider = 1)'
    : '';

$colunas = "u.id, u.nome, u.email, u.perfil, u.ativo, i.nome AS igreja,
              (SELECT GROUP_CONCAT(
                        CONCAT(d2.nome, IF(COALESCE(du2.funcao, '') = '', '', CONCAT(' (', du2.funcao, ')')))
                        ORDER BY d2.nome SEPARATOR ', ')
                 FROM departamento_usuario du2
                 JOIN departamentos d2 ON d2.id = du2.departamento_id
                WHERE du2.usuario_id = u.id$filtroLider) AS deptos
              FROM usuarios u LEFT JOIN igrejas i ON i.id = u.igreja_id";

if ($ehGeral) {
    $stmt = $pdo->query("SELECT $colunas ORDER BY u.nome");
} elseif ($usuario['perfil'] === 'ADMIN_IGREJA') {
    $stmt = $pdo->prepare("SELECT $colunas WHERE u.igreja_id = ? ORDER BY u.nome");
    $stmt->execute([$usuario['igreja_id']]);
} else {   // LIDER: quem está em departamentos que ele lidera
    $stmt = $pdo->prepare(
        "SELECT DISTINCT $colunas
           JOIN departamento_usuario du ON du.usuario_id = u.id
          WHERE du.departamento_id IN (
                SELECT departamento_id FROM departamento_usuario
                 WHERE usuario_id = ? AND e_lider = 1)
          ORDER BY u.nome"
    );
    $stmt->execute([$usuario['id']]);
}
$pessoas = $stmt->fetchAll();
$igrejas = $ehGeral ? listarIgrejas($pdo) : [];

$tituloPagina = 'Pessoas';
include __DIR__ . '/includes/topo.php';
?>

<h1 class="h3 mb-3">Pessoas</h1>

<div class="row g-4">
    <!-- Formulário de cadastro -->
    <div class="col-lg-3">
        <div class="card">
            <div class="card-body">
                <h2 class="h5">Cadastrar pessoa</h2>
                                <p class="small text-muted">
                    A pessoa recebe um e-mail com um link para definir a própria senha.
                    Se o e-mail já tiver conta, ela só é incluída no departamento escolhido.
                </p>

                <form method="POST" autocomplete="off">
                    <?= csrfCampo() ?>
                    <input type="hidden" name="acao" value="criar">

                    <div class="mb-3">
                        <label for="nome" class="form-label">Nome</label>
                        <input type="text" class="form-control" id="nome" name="nome"
                               maxlength="120" required value="<?= antigo('nome') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">E-mail</label>
                        <input type="email" class="form-control" id="email" name="email"
                               maxlength="190" required value="<?= antigo('email') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="telefone" class="form-label">WhatsApp (opcional)</label>
                        <input type="tel" class="form-control" id="telefone" name="telefone"
                               data-mascara="telefone" placeholder="(49) 99999-9999"
                               value="<?= antigo('telefone') ?>">
                        <div class="form-text">
                            Os avisos por WhatsApp só são enviados depois que a própria pessoa aceitar.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="perfil" class="form-label">Perfil</label>
                        <select class="form-select" id="perfil" name="perfil" required>
                            <?php foreach ($perfisPermitidos as $p): ?>
                                <option value="<?= e($p) ?>" <?= post('perfil') === $p ? 'selected' : '' ?>>
                                    <?= e(ROTULOS_PERFIL[$p]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

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
                        <label for="departamento_id" class="form-label">
                            Departamento<?= $usuario['perfil'] === 'LIDER' ? '' : ' (obrigatório para líder)' ?>
                        </label>
                        <select class="form-select" id="departamento_id" name="departamento_id"
                                <?= $usuario['perfil'] === 'LIDER' ? 'required' : '' ?>>
                            <option value="">Nenhum</option>
                            <?php foreach ($departamentos as $d): ?>
                                <option value="<?= (int) $d['id'] ?>"
                                    <?= (int) post('departamento_id') === (int) $d['id'] ? 'selected' : '' ?>>
                                    <?= e($ehGeral ? $d['igreja'] . ' - ' . $d['nome'] : $d['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="funcao" class="form-label">Função no departamento (opcional)</label>
                        <input type="text" class="form-control" id="funcao" name="funcao"
                               maxlength="80" placeholder="ex.: Violão, Recepção"
                               value="<?= antigo('funcao') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary" data-enviando="Salvando...">Cadastrar</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Lista -->
    <div class="col-lg-8">
        <input type="search" class="form-control mb-3" data-filtro="#tabela-pessoas"
               placeholder="Buscar por nome ou e-mail...">

        <div class="table-responsive">
            <table class="table table-striped align-middle" id="tabela-pessoas">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Perfil</th>
                        <th>Departamentos</th>
                        <?php if ($ehGeral): ?><th>Igreja</th><?php endif; ?>
                                                    
                        <th>Situação</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pessoas as $p): ?>
                    <tr>
                        <td>
                            <?= e($p['nome']) ?><br>
                            <small class="text-muted"><?= e($p['email']) ?></small>
                        </td>
                        <td><?= e(ROTULOS_PERFIL[$p['perfil']] ?? $p['perfil']) ?></td>
                                                <td><small><?= e($p['deptos'] ?? '') ?: '-' ?></small></td>
                        <?php if ($ehGeral): ?><td><?= e($p['igreja'] ?? '-') ?></td><?php endif; ?>
                        <td>
                            <?php if ($p['ativo']): ?>
                                <span class="badge text-bg-success">Ativa</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Desativada</span>
                            <?php endif; ?>
                        </td>
                        <td>
                         <div class="d-flex flex-wrap gap-1 justify-content-end">
                            <?php if (nivelPerfil($p['perfil']) < $meuNivel): ?>
                                <?php if ($p['ativo']): ?>
                                        <a href="/usuario_editar.php?id=<?= (int)$p['id'] ?>"
           class="btn btn-sm btn-outline-primary">
            Editar
        </a>
                                    <form method="POST" class="d-inline">
                                        <?= csrfCampo() ?>
                                        <input type="hidden" name="acao" value="reenviar">
                                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                data-confirma="Enviar um novo convite por e-mail para esta pessoa?">
                                            Reenviar convite
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" class="d-inline">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="acao" value="alternar_ativo">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit"
                                            class="btn btn-sm <?= $p['ativo'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                            <?= $p['ativo'] ? 'data-confirma="Desativar esta conta? A pessoa não conseguirá mais entrar."' : '' ?>>
                                        <?= $p['ativo'] ? 'Desativar' : 'Reativar' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pessoas): ?>
                    <tr><td colspan="6" class="text-muted">Nenhuma pessoa cadastrada ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>
