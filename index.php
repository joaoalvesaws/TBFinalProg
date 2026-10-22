<?php


session_start();

if (isset($_SESSION['usuario_id'])) {
    header('Location: minhas_escalas.php');
} else {
    header('Location: login.php');
}
exit;
