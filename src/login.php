<?php
// src/login.php
// Login por e-mail e senha. A página confere a senha por conta própria
// (não precisa mais do processa_login.php).

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';

// Quem já está logado não precisa ver o login
if (usuarioAtual() !== null) {
    redirecionar('/minhas_escalas.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();

    $email = mb_strtolower(post('email'));
    $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
    $ok    = false;
    $u     = null;

    // Limite de tamanho evita que alguém mande uma senha gigante só para travar o servidor
    if ($email !== '' && $senha !== '' && strlen($senha) <= 200) {
        $s = $pdo->prepare('SELECT id, senha_hash FROM usuarios WHERE email = ? AND ativo = 1');
        $s->execute([$email]);
        $u = $s->fetch() ?: null;

        // Se o e-mail não existe, confere contra um hash qualquer: assim a resposta
        // demora o mesmo tempo e ninguém descobre quais e-mails têm conta.
        $hash = $u ? $u['senha_hash'] : password_hash('tempo-igual', PASSWORD_DEFAULT);
        $ok   = password_verify($senha, $hash) && $u !== null;
    }

    if ($ok) {
        // Atualiza o hash se o PHP passar a usar um algoritmo/custo mais forte
        if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
                ->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
        }

        loginIniciar($u);   // troca o ID da sessão e cria um novo token CSRF
        auditar($pdo, (int) $u['id'], 'LOGIN', 'usuarios', (int) $u['id']);
        redirecionar('/minhas_escalas.php');
    }

    // Mesma mensagem para e-mail inexistente, senha errada ou conta desativada
    flash('danger', 'E-mail ou senha inválidos.');
}

$tituloPagina = 'Entrar';
include __DIR__ . '/includes/topo.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 text-center mb-4">Entrar</h1>

                <form method="POST" autocomplete="on">
                    <?= csrfCampo() ?>

                    <div class="mb-3">
                        <label for="email" class="form-label">E-mail</label>
                        <input type="email" class="form-control" id="email" name="email"
                               required autofocus autocomplete="username"
                               placeholder="seu@email.com" value="<?= antigo('email') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="senha" class="form-label">Senha</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="senha" name="senha"
                                   required autocomplete="current-password">
                            <button type="button" class="btn btn-outline-secondary"
                                    data-mostrar-senha="#senha">Mostrar</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" data-enviando="Entrando...">Entrar</button>
                </form>

                <p class="text-center mt-3 mb-0">
                    <a href="/recuperar_senha.php">Esqueci minha senha</a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>