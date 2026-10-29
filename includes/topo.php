<?php
// htdocs/includes/topo.php
// Cabeçalho da página + menu. Opcional: defina $tituloPagina = 'Meu título';
// Carrega sessao.php e util.php (require_once previne repetições).

require_once __DIR__ . '/sessao.php';
require_once __DIR__ . '/util.php';

$tituloPagina = $tituloPagina ?? 'Sistema de Escalas';
$usuarioMenu  = function_exists('usuarioAtual') ? usuarioAtual() : null;
$nivelMenu    = $usuarioMenu ? nivelPerfil($usuarioMenu['perfil']) : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title><?= e($tituloPagina) ?> | Escalas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#212529">
    <link rel="apple-touch-icon" href="/assets/icons/libya_2060733.png">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="/index.php">Escalas</a>

        <?php if ($usuarioMenu): // Menu e botão Sair exibidos apenas para usuários autenticados ?>
            <button class="navbar-toggler" type="button"
                    data-bs-toggle="collapse" data-bs-target="#menuPrincipal"
                    aria-controls="menuPrincipal" aria-expanded="false" aria-label="Abrir menu">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="menuPrincipal">
                <ul class="navbar-nav me-auto">
                    <!-- Acesso geral -->
                    <li class="nav-item"><a class="nav-link" href="/minhas_escalas.php">Minhas escalas</a></li>
                    <li class="nav-item"><a class="nav-link" href="/bloqueios.php">Bloqueios</a></li>
                    <li class="nav-item"><a class="nav-link" href="/trocas.php">Trocas</a></li>

                    <?php if ($nivelMenu >= nivelPerfil('LIDER')): ?>
                        <li class="nav-item"><a class="nav-link" href="/lider_escalas.php">Montar escala</a></li>
                        <li class="nav-item"><a class="nav-link" href="/eventos.php">Eventos</a></li>
                        <li class="nav-item"><a class="nav-link" href="/usuarios.php">Pessoas</a></li>
                    <?php endif; ?>

                    <?php if ($nivelMenu >= nivelPerfil('ADMIN_IGREJA')): ?>
                        <li class="nav-item"><a class="nav-link" href="/eventos_padrao.php">Cultos padrão</a></li>
                        <li class="nav-item"><a class="nav-link" href="/departamentos.php">Departamentos</a></li>
                    <?php endif; ?>

                    <?php if ($nivelMenu >= nivelPerfil('ADMIN_GERAL')): ?>
                        <li class="nav-item"><a class="nav-link" href="/igrejas.php">Igrejas</a></li>
                    <?php endif; ?>
                </ul>

                <form method="POST" action="/logout.php" class="d-flex align-items-center gap-2 mb-0">
                    <span class="navbar-text small"><?= e($usuarioMenu['nome']) ?></span>
                    <?= csrfCampo() ?>
                    <button type="submit" class="btn btn-outline-light btn-sm">Sair</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</nav>

<main class="container pb-5">
<?php flashExibir(); ?>