
<?php
// src/login.php
// Tela de entrada. Login por e-mail e senha (contas criadas pelo administrador).

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/auxiliares.php';

// Quem já está logado vai direto para o dashboard
if (usuarioAtual() !== null) {
    redirecionar('dashboard.php');
}

$mensagem   = '';
$tipoAlerta = 'info';

if (isset($_GET['status']) && $_GET['status'] === 'logout') {
    $mensagem   = 'Você saiu do sistema com segurança.';
    $tipoAlerta = 'success';
}

$emailDigitado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();

    $emailDigitado = trim((string) ($_POST['email'] ?? ''));
    $senha         = (string) ($_POST['senha'] ?? '');

    $stmt = $pdo->prepare('SELECT id, senha_hash FROM usuarios WHERE email = ? AND ativo = 1');
    $stmt->execute([$emailDigitado]);
    $conta = $stmt->fetch();

    // Se o e-mail não existe, confere contra um hash de mentira (válido, de uma senha que
    // ninguém usa): o tempo de resposta fica parecido e não revela quais e-mails existem.
    $hash = $conta['senha_hash'] ?? '$2y$10$wuw9KU/6YsImWXJi5g7mgee1jmPnEkZX6Xc6/RiwJMfJ61uyugRsK';

    if (password_verify($senha, $hash) && $conta) {
        loginIniciar($conta);
        redirecionar('dashboard.php');
    }

    $mensagem   = 'E-mail ou senha incorretos.';
    $tipoAlerta = 'danger';
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrar | Kosmos</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
  <link href="assets/estilo.css" rel="stylesheet">
</head>
<body>
  <main class="container-fluid">
    <div class="row min-vh-100">

      <!-- Coluna esquerda: apresentação -->
      <section class="col-lg-6 k-fundo-login-esq d-flex align-items-center position-relative p-4 p-lg-5">
        <div class="k-login-texto">
          <h1 class="k-login-titulo mb-4">Kosmos : a rotina da equipe em harmonia</h1>
          <p class="k-texto-suave">Gestão de escalas e plantões. Administradores montam a equipe e o calendário; a equipe consulta turnos, informa indisponibilidade e solicita trocas.</p>
        </div>
        <svg class="k-logo-aneis d-none d-lg-block" viewBox="0 0 80 80" aria-hidden="true">
          <circle cx="40" cy="40" r="38"/><circle cx="40" cy="40" r="26"/><circle cx="40" cy="40" r="13"/>
        </svg>
      </section>

      <!-- Coluna direita: formulário -->
      <section class="col-lg-6 k-fundo-login-dir d-flex align-items-center p-4 p-lg-5">
        <div class="k-login-form w-100">
          <h2 class="h4 fw-normal mb-1">Entrar</h2>
          <p class="small mb-4">Use o e-mail e a senha fornecidos pelo administrador.</p>

          <?php if ($mensagem !== ''): ?>
            <div class="alert alert-<?= e($tipoAlerta) ?>" role="alert"><?= e($mensagem) ?></div>
          <?php endif; ?>

          <form action="login.php" method="post">
            <?= csrfCampo() ?>

            <label for="email" class="form-label small">E-mail</label>
            <input type="email" id="email" name="email" class="form-control k-input mb-3" value="<?= e($emailDigitado) ?>" autocomplete="username" required autofocus>

            <label for="senha" class="form-label small">Senha</label>
            <input type="password" id="senha" name="senha" class="form-control k-input mb-4" autocomplete="current-password" required>

            <button type="submit" class="btn k-btn-principal w-100">Entrar</button>
          </form>
        </div>
      </section>

    </div>
  </main>
</body>
</html>
