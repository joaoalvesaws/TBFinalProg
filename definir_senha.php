<?php
// src/definir_senha.php
// Página aberta pelo link do e-mail (convite ou recuperação de senha).
// Pública: não exige login, mas exige um token válido, não usado e não expirado.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';

// Busca o token no banco (pelo hash). Devolve null se for inválido, usado,
// expirado ou se a conta estiver desativada.
function buscarToken(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $s = $pdo->prepare(
        'SELECT t.id AS token_id, t.tipo,
                u.id, u.nome, u.telefone, u.consentimento_whatsapp_em
           FROM tokens_senha t
           JOIN usuarios u ON u.id = t.usuario_id
          WHERE t.token_hash = ?
            AND t.usado_em IS NULL
            AND t.expira_em > NOW()
            AND u.ativo = 1'
    );
    $s->execute([hash('sha256', $token)]);

    return $s->fetch() ?: null;
}

$tokenRecebido = $_POST['token'] ?? $_GET['token'] ?? '';
$token         = is_string($tokenRecebido) ? $tokenRecebido : '';
$dados         = buscarToken($pdo, $token);

if ($dados && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();

    $senha  = $_POST['senha']  ?? '';
    $senha2 = $_POST['senha2'] ?? '';
    $erro   = null;

    if (!is_string($senha) || !is_string($senha2)) {
        $erro = 'Dados inválidos.';
    } elseif (($msg = validarSenha($senha)) !== null) {
        $erro = $msg;
    } elseif ($senha !== $senha2) {
        $erro = 'As duas senhas não são iguais.';
    }

    if ($erro) {
        flash('danger', $erro);
    } else {
        // Consentimento de WhatsApp: só se a pessoa marcou, tem telefone e ainda não tinha aceitado
        $aceitouWhatsapp = !empty($_POST['aceita_whatsapp'])
            && !empty($dados['telefone'])
            && empty($dados['consentimento_whatsapp_em']);

        try {
            $pdo->beginTransaction();

            // Marca o token como usado de forma atômica: se outro clique já usou, rowCount() = 0
            $usar = $pdo->prepare('UPDATE tokens_senha SET usado_em = NOW() WHERE id = ? AND usado_em IS NULL');
            $usar->execute([$dados['token_id']]);

            if ($usar->rowCount() !== 1) {
                $pdo->rollBack();
                $dados = null;   // cai na tela de "link inválido"
            } else {
                $novoHash = password_hash($senha, PASSWORD_DEFAULT);

                if ($aceitouWhatsapp) {
                    $pdo->prepare('UPDATE usuarios SET senha_hash = ?, consentimento_whatsapp_em = NOW() WHERE id = ?')
                        ->execute([$novoHash, $dados['id']]);
                } else {
                    $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
                        ->execute([$novoHash, $dados['id']]);
                }

                // Invalida qualquer outro link pendente desta pessoa
                $pdo->prepare('UPDATE tokens_senha SET usado_em = NOW() WHERE usuario_id = ? AND usado_em IS NULL')
                    ->execute([$dados['id']]);

                auditar($pdo, (int) $dados['id'], 'SENHA_DEFINIDA', 'usuarios', (int) $dados['id'],
                        ['origem' => $dados['tipo'], 'consentiu_whatsapp' => $aceitouWhatsapp]);

                $pdo->commit();

                flash('success', 'Senha definida! Entre com seu e-mail e a nova senha.');
                redirecionar('/login.php');
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Erro ao definir senha: ' . $ex->getMessage());
            flash('danger', 'Não foi possível salvar. Tente novamente.');
        }
    }
}

$tituloPagina = 'Definir senha';
include __DIR__ . '/includes/topo.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">

    <?php if (!$dados): ?>
        <div class="alert alert-warning">
            Este link é inválido ou expirou.
        </div>
        <a href="/recuperar_senha.php" class="btn btn-primary">Pedir um novo link</a>

    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <h1 class="h4">Olá, <?= e($dados['nome']) ?>!</h1>
                <p class="text-muted">Escolha uma senha para acessar o sistema (mínimo 8 caracteres).</p>

                <form method="POST" autocomplete="off">
                    <?= csrfCampo() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="mb-3">
                        <label for="senha" class="form-label">Nova senha</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="senha" name="senha"
                                   minlength="8" maxlength="72" required autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary"
                                    data-mostrar-senha="#senha">Mostrar</button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="senha2" class="form-label">Repita a senha</label>
                        <input type="password" class="form-control" id="senha2" name="senha2"
                               minlength="8" maxlength="72" required autocomplete="new-password">
                    </div>

                    <?php if (!empty($dados['telefone']) && empty($dados['consentimento_whatsapp_em'])): ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="aceita_whatsapp" name="aceita_whatsapp" value="1">
                            <label class="form-check-label" for="aceita_whatsapp">
                                Aceito receber avisos de escala por WhatsApp no número cadastrado.
                                Posso desistir quando quiser. Veja a
                                <a href="/privacidade.php" target="_blank" rel="noopener">política de privacidade</a>.
                            </label>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary w-100" data-enviando="Salvando...">
                        Salvar senha
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>
