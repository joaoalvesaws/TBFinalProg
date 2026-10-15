<?php
// src/equipe.php
// Cadastro de usuários da igreja. Só administradores acessam.
// O administrador cria, edita e exclui líderes e voluntários (administradores só aparecem na lista).

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/auxiliares.php';

$usuario = exigirPerfil('ADMIN_IGREJA');
$meuId   = (int) $usuario['id'];
$igreja  = igrejaDoUsuario($usuario);     // null = administrador geral (escolhe a igreja no cadastro)
$ehGeral = ($igreja === null);

const PERFIS_GERENCIAVEIS = ['LIDER', 'VOLUNTARIO'];

// Valores do formulário (usados para reabrir a janela se houver erro)
$form     = ['id' => 0, 'nome' => '', 'email' => '', 'perfil' => 'VOLUNTARIO', 'ativo' => '1', 'igreja_id' => ''];
$erroForm = '';

// ---------------------------------------------------------------
// Ações (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    $acao = (string) ($_POST['acao'] ?? '');

    // Busca o usuário alvo; só líder/voluntário da igreja do administrador pode ser alterado
    $buscarAlvo = function (int $id) use ($pdo, $usuario): ?array {
        $stmt = $pdo->prepare('SELECT id, nome, email, perfil, ativo, igreja_id FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        $alvo = $stmt->fetch();
        if (!$alvo || !in_array($alvo['perfil'], PERFIS_GERENCIAVEIS, true)) {
            return null;
        }
        if ($alvo['igreja_id'] === null || !podeAcessarIgreja($usuario, (int) $alvo['igreja_id'])) {
            return null;
        }
        return $alvo;
    };

    // ----- Criar / editar -----
    if ($acao === 'criar' || $acao === 'editar') {
        $form = [
            'id'        => (int) ($_POST['id'] ?? 0),
            'nome'      => trim((string) ($_POST['nome'] ?? '')),
            'email'     => trim((string) ($_POST['email'] ?? '')),
            'perfil'    => (string) ($_POST['perfil'] ?? ''),
            'ativo'     => (string) ($_POST['ativo'] ?? '1'),
            'igreja_id' => (string) ($_POST['igreja_id'] ?? ''),
        ];
        $senha = (string) ($_POST['senha'] ?? '');
        $alvo  = null;

        if ($acao === 'editar') {
            $alvo = $buscarAlvo($form['id']);
            if (!$alvo) {
                flash('danger', 'Usuário não encontrado ou sem permissão para editar.');
                redirecionar('equipe.php');
            }
        }

        // Igreja do novo usuário: a do administrador, ou a escolhida pelo administrador geral
        $igrejaNova = $igreja;
        if ($acao === 'criar' && $ehGeral) {
            $stmt = $pdo->prepare('SELECT id FROM igrejas WHERE id = ?');
            $stmt->execute([(int) $form['igreja_id']]);
            $igrejaNova = $stmt->fetchColumn() ?: null;
        }

        if (mb_strlen($form['nome']) < 2 || mb_strlen($form['nome']) > 120) {
            $erroForm = 'Informe o nome completo (2 a 120 caracteres).';
        } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 190) {
            $erroForm = 'Informe um e-mail válido.';
        } elseif (!in_array($form['perfil'], PERFIS_GERENCIAVEIS, true)) {
            $erroForm = 'Escolha o perfil: Líder ou Voluntário.';
        } elseif (!in_array($form['ativo'], ['0', '1'], true)) {
            $erroForm = 'Escolha o status.';
        } elseif ($acao === 'criar' && $igrejaNova === null) {
            $erroForm = 'Escolha a igreja.';
        } elseif ($acao === 'criar' && strlen($senha) < 8) {
            $erroForm = 'A senha precisa ter pelo menos 8 caracteres.';
        } elseif ($acao === 'editar' && $senha !== '' && strlen($senha) < 8) {
            $erroForm = 'A nova senha precisa ter pelo menos 8 caracteres.';
        }

        if ($erroForm === '') {
            try {
                if ($acao === 'criar') {
                    $pdo->prepare('INSERT INTO usuarios (igreja_id, nome, email, senha_hash, perfil, ativo) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$igrejaNova, $form['nome'], $form['email'], password_hash($senha, PASSWORD_DEFAULT), $form['perfil'], (int) $form['ativo']]);
                    auditar($pdo, $meuId, 'USUARIO_CRIADO', 'usuarios', (int) $pdo->lastInsertId(), ['email' => $form['email'], 'perfil' => $form['perfil']]);
                    flash('success', 'Usuário criado.');
                } else {
                    $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, perfil = ?, ativo = ? WHERE id = ?')
                        ->execute([$form['nome'], $form['email'], $form['perfil'], (int) $form['ativo'], $alvo['id']]);
                    if ($senha !== '') {
                        $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
                            ->execute([password_hash($senha, PASSWORD_DEFAULT), $alvo['id']]);
                    }
                    auditar($pdo, $meuId, 'USUARIO_EDITADO', 'usuarios', (int) $alvo['id'], ['perfil' => $form['perfil'], 'ativo' => (int) $form['ativo'], 'senha_trocada' => $senha !== '']);
                    flash('success', 'Usuário atualizado.');
                }
                redirecionar('equipe.php');
            } catch (PDOException $erro) {
                if ((int) ($erro->errorInfo[1] ?? 0) === 1062) {      // e-mail repetido
                    $erroForm = 'Já existe um usuário com este e-mail.';
                } else {
                    throw $erro;
                }
            }
        }
        // Com erro: não redireciona; a página abre de novo com a janela aberta e os dados preenchidos
    }

    // ----- Excluir -----
    if ($acao === 'excluir') {
        $alvo = $buscarAlvo((int) ($_POST['id'] ?? 0));
        if (!$alvo) {
            flash('danger', 'Usuário não encontrado ou sem permissão para excluir.');
            redirecionar('equipe.php');
        }

        $pdo->beginTransaction();
        try {
            foreach (['notificacoes', 'bloqueios', 'departamento_usuario'] as $tabela) {
                $pdo->prepare("DELETE FROM $tabela WHERE usuario_id = ?")->execute([$alvo['id']]);
            }
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$alvo['id']]);
            auditar($pdo, $meuId, 'USUARIO_EXCLUIDO', 'usuarios', (int) $alvo['id'], ['nome' => $alvo['nome'], 'email' => $alvo['email']]);
            $pdo->commit();
            flash('success', 'Usuário excluído.');
        } catch (PDOException $erro) {
            $pdo->rollBack();
            if ($erro->getCode() === '23000') {      // ligado a escalas, trocas ou histórico
                flash('danger', 'Este usuário já tem escalas, trocas ou histórico e não pode ser excluído. Edite-o e mude o status para Inativo.');
            } else {
                throw $erro;
            }
        }
        redirecionar('equipe.php');
    }
}

// ---------------------------------------------------------------
// Dados da tela
// ---------------------------------------------------------------
$sql = "SELECT u.id, u.nome, u.email, u.perfil, u.ativo, u.igreja_id, i.nome AS igreja
          FROM usuarios u
          LEFT JOIN igrejas i ON i.id = u.igreja_id";
$par = [];
if (!$ehGeral) {
    $sql .= ' WHERE u.igreja_id = ?';
    $par[] = $igreja;
}
$sql .= " ORDER BY FIELD(u.perfil, 'ADMIN_GERAL','ADMIN_IGREJA','LIDER','VOLUNTARIO'), u.nome";
$stmt = $pdo->prepare($sql);
$stmt->execute($par);
$usuarios = $stmt->fetchAll();

$igrejas = $ehGeral ? $pdo->query('SELECT id, nome FROM igrejas ORDER BY nome')->fetchAll() : [];

$modoEdicao = ($form['id'] > 0);

$tituloPagina = 'Cadastro de usuários';
$paginaAtiva  = 'equipe';

include __DIR__ . '/includes/topo.php';
?>
        <div class="k-conteudo">
          <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn k-btn-acao" data-bs-toggle="modal" data-bs-target="#modalUsuario">+ Novo usuário</button>
          </div>

          <section class="k-card" aria-label="Lista de usuários">
            <div class="table-responsive">
              <table class="table k-tabela align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">E-mail</th>
                    <th scope="col">Perfil</th>
                    <?php if ($ehGeral): ?><th scope="col">Igreja</th><?php endif; ?>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Ações</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($usuarios as $u):
                      $gerenciavel = in_array($u['perfil'], PERFIS_GERENCIAVEIS, true);
                  ?>
                    <tr>
                      <td><?= e($u['nome']) ?></td>
                      <td class="k-texto-suave"><?= e($u['email']) ?></td>
                      <td><span class="k-badge <?= $gerenciavel ? ($u['perfil'] === 'LIDER' ? 'k-badge-lider' : 'k-badge-voluntario') : 'k-badge-admin' ?>"><?= e(perfilRotulo($u['perfil'])) ?></span></td>
                      <?php if ($ehGeral): ?><td class="k-texto-suave"><?= e($u['igreja'] ?? '—') ?></td><?php endif; ?>
                      <td><span class="k-badge <?= $u['ativo'] ? 'k-badge-ok' : 'k-badge-neutro' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                      <td class="text-end text-nowrap">
                        <?php if ($gerenciavel): ?>
                          <button type="button" class="btn btn-sm k-btn-contorno"
                                  data-bs-toggle="modal" data-bs-target="#modalUsuario"
                                  data-id="<?= (int) $u['id'] ?>"
                                  data-nome="<?= e($u['nome']) ?>"
                                  data-email="<?= e($u['email']) ?>"
                                  data-perfil="<?= e($u['perfil']) ?>"
                                  data-ativo="<?= (int) $u['ativo'] ?>">Editar</button>
                          <form method="post" action="equipe.php" class="d-inline">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="btn btn-sm k-btn-perigo" data-confirma="Excluir <?= e($u['nome']) ?>? Esta ação não pode ser desfeita.">Excluir</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <!-- ===== Janela: novo usuário / editar usuário ===== -->
        <div class="modal fade" id="modalUsuario" tabindex="-1" aria-labelledby="tituloModalUsuario" aria-hidden="true"<?= $erroForm !== '' ? ' data-abrir-modal' : '' ?>>
          <div class="modal-dialog modal-dialog-centered">
            <form method="post" action="equipe.php" class="modal-content k-modal">
              <?= csrfCampo() ?>
              <input type="hidden" name="acao" value="<?= $modoEdicao ? 'editar' : 'criar' ?>">
              <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

              <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="tituloModalUsuario"><?= $modoEdicao ? 'Editar usuário' : 'Novo usuário' ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
              </div>

              <div class="modal-body">
                <?php if ($erroForm !== ''): ?>
                  <div class="alert alert-danger" role="alert"><?= e($erroForm) ?></div>
                <?php endif; ?>

                <label for="u-nome" class="form-label small">Nome completo</label>
                <input type="text" id="u-nome" name="nome" class="form-control k-campo mb-3" maxlength="120" value="<?= e($form['nome']) ?>" required>

                <label for="u-email" class="form-label small">E-mail</label>
                <input type="email" id="u-email" name="email" class="form-control k-campo mb-3" maxlength="190" value="<?= e($form['email']) ?>" required>

                <label for="u-senha" class="form-label small">Senha</label>
                <input type="password" id="u-senha" name="senha" class="form-control k-campo" minlength="8" autocomplete="new-password"<?= $modoEdicao ? '' : ' required' ?>>
                <div class="form-text mb-3" id="u-senha-ajuda"><?= $modoEdicao ? 'Deixe em branco para manter a senha atual.' : 'Mínimo de 8 caracteres.' ?></div>

                <?php if ($ehGeral): ?>
                  <div id="u-igreja-grupo"<?= $modoEdicao ? ' hidden' : '' ?>>
                    <label for="u-igreja" class="form-label small">Igreja</label>
                    <select id="u-igreja" name="igreja_id" class="form-select k-campo mb-3">
                      <option value="">Selecione</option>
                      <?php foreach ($igrejas as $i): ?>
                        <option value="<?= (int) $i['id'] ?>"<?= (string) $i['id'] === $form['igreja_id'] ? ' selected' : '' ?>><?= e($i['nome']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>

                <label for="u-perfil" class="form-label small">Perfil</label>
                <select id="u-perfil" name="perfil" class="form-select k-campo mb-3">
                  <option value="LIDER"<?= $form['perfil'] === 'LIDER' ? ' selected' : '' ?>>Líder</option>
                  <option value="VOLUNTARIO"<?= $form['perfil'] === 'VOLUNTARIO' ? ' selected' : '' ?>>Voluntário</option>
                </select>

                <label for="u-ativo" class="form-label small">Status</label>
                <select id="u-ativo" name="ativo" class="form-select k-campo">
                  <option value="1"<?= $form['ativo'] === '1' ? ' selected' : '' ?>>Ativo</option>
                  <option value="0"<?= $form['ativo'] === '0' ? ' selected' : '' ?>>Inativo</option>
                </select>
              </div>

              <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn k-btn-contorno" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn k-btn-acao">Salvar</button>
              </div>
            </form>
          </div>
        </div>
<?php include __DIR__ . '/includes/rodape.php'; ?>
