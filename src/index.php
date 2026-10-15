<?php


session_start();

if (isset($_SESSION['usuario_id'])) {
    header('Location: minhas_escalas.php');
} else {
    header('Location: login.php');
}
exit;
///////////////////////------- rever --------/////////////////////////////
<?php
// src/index.php
// Só redireciona: quem está logado vai para o dashboard; os demais, para o login.

require __DIR__ . '/includes/sessao.php';


redirecionar(usuarioAtual() !== null ? 'dashboard.php' : 'login.php');