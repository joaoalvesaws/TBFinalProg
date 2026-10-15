<?php
// src/logout.php
// Só encerra a sessão se vier de um formulário POST com token CSRF
// (o botão "Sair" do menu). Acesso direto pela barra de endereço só redireciona.

require __DIR__ . '/includes/sessao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    logout();
}

header('Location: /login.php');
exit;
