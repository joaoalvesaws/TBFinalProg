<?php
// src/logout.php
// Encerra a sessão. O botão "Sair" do menu envia um POST com token CSRF.

require __DIR__ . '/includes/sessao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enviado = $_POST['csrf'] ?? '';

    if (is_string($enviado) && hash_equals(csrfToken(), $enviado)) {
        logout();   // limpa os dados da sessão e apaga o cookie

        // Evita que o botão "voltar" do navegador mostre páginas da conta que saiu
        header('Cache-Control: no-store');
        header('Location: /login.php');
        exit;
    }
}

// Acesso direto (GET) ou token que não vale mais, por exemplo uma aba antiga
// depois de entrar com outro usuário em outra aba. Não encerra nada: volta à
// página inicial da sessão atual, onde o botão "Sair" já traz um token novo.
header('Location: /index.php');
exit;