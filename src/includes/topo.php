<?php
// src/includes/topo.php
// Abre a página: <head>, menu lateral, barra superior e mensagens.
// Repetido em todas as páginas internas. Antes de incluir, a página define:
//     $usuario      (retorno de exigirLogin())
//     $tituloPagina (ex.: 'Calendário')
//     $paginaAtiva  (ex.: 'calendario', igual à 1ª coluna do menu abaixo)
//     $semTopbar    (opcional, true = a página desenha o próprio cabeçalho; usado no dashboard)
// O rodape.php fecha o que este arquivo abre.

$semTopbar = $semTopbar ?? false;

// [id, arquivo, texto, nível mínimo do perfil que enxerga o item]
$itensMenu = [
    ['dashboard',    'dashboard.php',    'Dashboard',    NIVEIS_PERFIL['VOLUNTARIO']],
    ['calendario',   'calendario.php',   'Calendário',   NIVEIS_PERFIL['VOLUNTARIO']],
    ['solicitacoes', 'solicitacoes.php', 'Solicitações', NIVEIS_PERFIL['VOLUNTARIO']],
    ['notificacoes', 'notificacoes.php', 'Notificações', NIVEIS_PERFIL['ADMIN_IGREJA']],
    ['equipe',       'equipe.php',       'Equipe',       NIVEIS_PERFIL['ADMIN_IGREJA']],
    ['auditoria',    'auditoria.php',    'Auditoria',    NIVEIS_PERFIL['ADMIN_IGREJA']],
];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($tituloPagina) ?> | Kosmos</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
  <link href="assets/estilo.css" rel="stylesheet">
</head>
<body>
  <div class="d-flex min-vh-100">

    <!-- ===== MENU LATERAL ===== -->
    <aside class="offcanvas-lg offcanvas-start k-lateral p-3 d-flex flex-column" tabindex="-1" id="menuLateral" aria-label="Menu principal">
      <div class="mb-4 px-2">
        <div class="h5 fw-bold mb-0">Kosmos</div>
        <small class="k-texto-suave">Gestão de Plantões</small>
      </div>

      <nav class="nav flex-column gap-1">
        <?php foreach ($itensMenu as [$id, $arquivo, $texto, $nivelMinimo]):
            if (nivelDe($usuario) < $nivelMinimo) { continue; }
            $ativo = ($id === $paginaAtiva);
        ?>
          <a class="nav-link<?= $ativo ? ' active' : '' ?>" href="<?= e($arquivo) ?>"<?= $ativo ? ' aria-current="page"' : '' ?>><?= e($texto) ?></a>
        <?php endforeach; ?>

        <!-- Sair é POST com token CSRF (um link simples poderia ser acionado por outro site) -->
        <form method="post" action="sair.php">
          <?= csrfCampo() ?>
          <button type="submit" class="nav-link w-100 text-start" data-confirma="Deseja sair do sistema?">Sair</button>
        </form>
      </nav>

      <div class="k-usuario mt-auto pt-3 px-2">
        <div class="fw-bold"><?= e($usuario['nome']) ?></div>
        <small class="k-texto-suave"><?= e(perfilRotulo($usuario['perfil'])) ?></small>
      </div>
    </aside>

    <!-- ===== CONTEÚDO ===== -->
    <div class="flex-grow-1 k-coluna-conteudo">

      <?php if (!$semTopbar): ?>
        <header class="k-topbar d-flex align-items-center justify-content-between px-3 px-md-4 py-3">
          <div class="d-flex align-items-center gap-3">
            <button class="btn btn-outline-light btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral" aria-controls="menuLateral">Menu</button>
            <h1 class="h5 fw-bold mb-0"><?= e($tituloPagina) ?></h1>
          </div>
          <span class="k-selo"><?= e(perfilSelo($usuario['perfil'])) ?></span>
        </header>
      <?php endif; ?>

      <main class="p-3 p-md-4">
        <?php if ($semTopbar): ?>
          <button class="btn btn-outline-light btn-sm d-lg-none mb-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral" aria-controls="menuLateral">Menu</button>
        <?php endif; ?>

        <?php foreach (flashPegar() as $mensagem): ?>
          <div class="alert alert-<?= e($mensagem['tipo']) ?> k-conteudo" role="status"><?= e($mensagem['texto']) ?></div>
        <?php endforeach; ?>
