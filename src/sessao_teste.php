<?php

require __DIR__ . '/includes/sessao.php';
echo 'Sessão OK. Token: ' . e(csrfToken()) . '<br>';
echo 'Logado? ' . (usuarioAtual() ? 'sim' : 'não');
