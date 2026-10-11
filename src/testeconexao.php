<?php

require __DIR__ . '/conexao.php';
echo 'Conexão OK. Hora do banco: ' . $pdo->query('SELECT NOW()')->fetchColumn();
