<?php
session_start();

// Captura mensagens enviadas via URL (ex: login.php?status=erro)
$mensagem = '';
$tipoAlerta = 'info';

if (isset($_GET['status'])) {
    switch ($_GET['status']) {
        case 'erro':
            $mensagem = 'Usuário ou senha inválidos. Tente novamente.';
            $tipoAlerta = 'danger';
            break;
        case 'sucesso':
            $mensagem = 'Conta criada com sucesso! Faça seu login.';
            $tipoAlerta = 'success';
            break;
        case 'logout':
            $mensagem = 'Você saiu do sistema com segurança.';
            $tipoAlerta = 'warning';
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Meu Sistema</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center vh-100">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow p-4 rounded-3">
                    <h4 class="text-center mb-4">Faz o login ai (nao ta funcionando) so pra mostrar algo</h4>
                    <?php if (!empty($mensagem)): ?>
                        <div class="alert alert-<?= $tipoAlerta ?> alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($mensagem) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form action="processa_login.php" method="POST">
                        <div class="mb-3">
                            <label for="email" class="form-label">E-mail</label>
                            <input type="email" class="form-control" id="email" name="email" required placeholder="seu@email.com">
                        </div>
                        <div class="mb-3">
                            <label for="senha" class="form-label">Senha</label>
                            <input type="password" class="form-control" id="senha" name="senha" required placeholder="******">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Entrar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS (necessário para fechar o alerta no botão "x") -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>