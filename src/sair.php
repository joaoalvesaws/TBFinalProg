<?php
// src/sair.php
// Encerra a sessão. Só aceita POST com token CSRF (botão "Sair" do menu).

require __DIR__ . '/includes/sessao.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerificar();
    logout();
    redirecionar('login.php?status=logout');
}

redirecionar('dashboard.php');
