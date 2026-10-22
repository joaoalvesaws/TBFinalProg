<?php
// src/recuperar_senha.php
// "Esqueci minha senha". Pública.
// Por segurança, a resposta é SEMPRE a mesma, exista o e-mail ou não
// (assim ninguém descobre quais e-mails têm conta).

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';

$pedidoFeito = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    $email = mb_strtolower(post('email'));

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $s = $pdo->prepare('SELECT id, nome FROM usuarios WHERE email = ? AND ativo = 1');
        $s->execute([$email]);
        $alvo = $s->fetch();

        if ($alvo) {
    // Limites contra abuso (a cota de e-mails do plano gratuito é pequena):
    //  - por pessoa: 1 pedido a cada 2 minutos e no máximo 3 por hora
    //  - no sistema todo: no máximo 20 pedidos de recuperação por hora
    // Passou do limite? Não envia nada, mas a resposta continua igual.
    $porPessoa = $pdo->prepare(
        "SELECT COUNT(*) AS na_hora,
                COALESCE(SUM(criado_em > (NOW() - INTERVAL 2 MINUTE)), 0) AS nos_2min
           FROM tokens_senha
          WHERE usuario_id = ? AND tipo = 'RECUPERACAO'
            AND criado_em > (NOW() - INTERVAL 1 HOUR)"
    );
    $porPessoa->execute([$alvo['id']]);
    $p = $porPessoa->fetch();

    $geral = (int) $pdo->query(
        "SELECT COUNT(*) FROM tokens_senha
          WHERE tipo = 'RECUPERACAO' AND criado_em > (NOW() - INTERVAL 1 HOUR)"
    )->fetchColumn();

    if ((int) $p['na_hora'] < 3 && (int) $p['nos_2min'] === 0 && $geral < 20) {
        $link = enviarConviteSenha($pdo, $alvo, 'RECUPERACAO');
        auditar($pdo, (int) $alvo['id'], 'RECUPERACAO_SOLICITADA', 'usuarios', (int) $alvo['id']);
        avisarLinkEmDesenvolvimento($link);
    }
}
    }

    $pedidoFeito = true;   // mesma resposta em qualquer caso
}

$tituloPagina = 'Recuperar senha';
include __DIR__ . '/includes/topo.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card">
            <div class="card-body">
                <h1 class="h4">Recuperar senha</h1>

                <?php if ($pedidoFeito): ?>
                    <div class="alert alert-success">
                        Se este e-mail tiver uma conta ativa, enviamos um link para redefinir a senha.
                        Ele vale por 2 horas. Confira também a caixa de spam.
                    </div>
                    <a href="/login.php">Voltar para o login</a>
                <?php else: ?>
                    <p class="text-muted">Informe o e-mail da sua conta para receber o link.</p>
                    <form method="POST" autocomplete="off">
                        <?= csrfCampo() ?>
                        <div class="mb-3">
                            <label for="email" class="form-label">E-mail</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   maxlength="190" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" data-enviando="Enviando...">
                            Enviar link
                        </button>
                    </form>
                    <p class="mt-3 mb-0"><a href="/login.php">Voltar para o login</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>
