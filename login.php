<?php

// src/login.php
// Login por e-mail e senha. A página confere a senha por conta própria
// (não precisa mais do processa_login.php).
register_shutdown_function(function () {
    $error = error_get_last();
    if (
        $error !== null
        && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])
    ) {
        $log  = "Data: " . date('Y-m-d H:i:s') . "\n";
        $log .= "Erro: " . $error['message'] . "\n";
        $log .= "Arquivo: " . $error['file'] . " na linha " . $error['line'] . "\n\n";
        file_put_contents(
            $_SERVER['DOCUMENT_ROOT'] . '/erro_php.txt',
            $log,
            FILE_APPEND
        );
    }
});

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';
require __DIR__ . '/conexao.php';

// Quem já está logado não precisa ver o login
if (usuarioAtual() !== null) {
    redirecionar('/minhas_escalas.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();

    $email = mb_strtolower(post('email'));
    $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
    $ok = false;
    $u  = null;

    // Limite de tamanho evita que alguém mande uma senha gigante só para travar o servidor
    if ($email !== '' && $senha !== '' && strlen($senha) <= 200) {
        $s = $pdo->prepare(
            'SELECT id, senha_hash FROM usuarios WHERE email = ? AND ativo = 1'
        );
        $s->execute([$email]);
        $u = $s->fetch() ?: null;

        // Se o e-mail não existe, confere contra um hash qualquer: assim a resposta
        // demora o mesmo tempo e ninguém descobre quais e-mails têm conta.
        $hash = $u
            ? $u['senha_hash']
            : password_hash('tempo-igual', PASSWORD_DEFAULT);
        $ok = password_verify($senha, $hash) && $u !== null;
    }

    if ($ok) {
        // Atualiza o hash se o PHP passar a usar um algoritmo/custo mais forte
        if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
                ->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
        }

        loginIniciar($u); // troca o ID da sessão e cria um novo token CSRF
        auditar($pdo, (int) $u['id'], 'LOGIN', 'usuarios', (int) $u['id']);
        redirecionar('/minhas_escalas.php');
    }

    // Mesma mensagem para e-mail inexistente, senha errada ou conta desativada
    flash('danger', 'E-mail ou senha inválidos.');
}

$tituloPagina = 'Entrar';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title><?= e($tituloPagina) ?> | Escalas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white">
<header class="bg-dark border-bottom border-primary border-3">
    <div class="container-fluid py-1">
        <span class="text-info">Gestão de Escalas para Igrejas</span>
    </div>
</header>

<main class="container-fluid p-0">
    <div class="row g-0 min-vh-100">
        <section class="col-12 col-md-6 bg-dark d-flex align-items-center">
            <div class="p-4 p-lg-5">
                <h1 class="display-6 fw-bold mb-4">
                    Kosmos: a rotina da equipe em harmonia
                </h1>
                <p class="fs-5 mb-0">
                    Gestão de escalas e plantões. Administradores montam a equipe e o calendário;
                    a equipe consulta turnos, informa indisponibilidade e solicita trocas.
                </p>
            </div>
        </section>

        <section
            class="col-12 col-md-6 bg-secondary d-flex align-items-center justify-content-center"
        >
            <div class="col-11 col-sm-9 col-lg-7 py-5">
                <?php flashExibir(); ?>

                <h2 class="h3 mb-2">Entrar</h2>
                <p class="mb-4">Use o usuário e a senha fornecidos pelo administrador.</p>

                <form method="POST" autocomplete="on">
                    <?= csrfCampo() ?>

                    <div class="mb-3">
                        <label for="email" class="form-label">E-mail</label>
                        <input
                            type="email"
                            class="form-control form-control-lg bg-primary text-white border-0 rounded-4"
                            id="email"
                            name="email"
                            required
                            autofocus
                            autocomplete="username"
                            placeholder="seu@email.com"
                            value="<?= antigo('email') ?>"
                        >
                    </div>

                    <div class="mb-4">
                        <label for="senha" class="form-label">Senha</label>
                        <div class="input-group input-group-lg">
                            <input
                                type="password"
                                class="form-control bg-primary text-white border-0 rounded-start-4"
                                id="senha"
                                name="senha"
                                required
                                autocomplete="current-password"
                            >
                            <button
                                type="button"
                                class="btn btn-outline-light rounded-end-4"
                                data-mostrar-senha="#senha"
                            >
                                Mostrar
                            </button>
                        </div>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary btn-lg w-100 rounded-4"
                        data-enviando="Entrando..."
                    >
                        Entrar
                    </button>
                </form>

                <p class="text-center mt-3 mb-0">
                    <a class="link-light" href="/recuperar_senha.php">Esqueci minha senha</a>
                </p>
            </div>
        </section>
    </div>
</main>

<?php include __DIR__ . '/includes/rodape.php';