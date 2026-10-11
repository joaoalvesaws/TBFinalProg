<?php
// carrega as configurações e cria a conexão com o banco ($pdo).

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['fuso_horario']);

// Erros: visíveis só em desenvolvimento
error_reporting(E_ALL);
if ($config['ambiente'] === 'desenvolvimento') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

try {
    $b = $config['banco'];

    $pdo = new PDO(
        "mysql:host={$b['host']};dbname={$b['nome']};charset={$b['charset']}",
        $b['usuario'],
        $b['senha'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Mesmo fuso horário no MySQL (para NOW() bater com o PHP)
    $pdo->exec("SET time_zone = '" . date('P') . "'");

} catch (PDOException $e) {
    error_log('Erro de conexão com o banco: ' . $e->getMessage());
    http_response_code(500);
    exit('Erro ao conectar no banco de dados.');
}