<?php

$config = require __DIR__ . '/config.php';

if (empty($config['app_debug'])) {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_nome']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log('Erro de conexão: ' . $e->getMessage());
    http_response_code(500);
    exit('Erro ao conectar no banco de dados.');
}
