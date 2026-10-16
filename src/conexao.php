<?php
// src/conexao.php
// Carrega as configurações e cria a conexão com o banco ($pdo).
//
// De onde vêm as configurações:
//  1) No seu computador (Docker): do arquivo config/config.php, como antes.
//  2) No Render (ou qualquer servidor sem esse arquivo): das VARIÁVEIS DE AMBIENTE
//     cadastradas no painel. Assim as senhas nunca ficam no Git.

$arquivoConfig = __DIR__ . '/../config/config.php';

if (is_file($arquivoConfig)) {
    $config = require $arquivoConfig;
} else {
    $config = [
        'ambiente'     => getenv('APP_ENV') ?: 'producao',          // 'desenvolvimento' ou 'producao'
        'fuso_horario' => getenv('APP_TZ') ?: 'America/Sao_Paulo',
        'app_url'      => getenv('APP_URL') ?: '',                  // ex.: https://escalas.onrender.com
        'banco' => [
            'host'    => getenv('DB_HOST') ?: 'db',
            'porta'   => (int) (getenv('DB_PORT') ?: 3306),
            'nome'    => getenv('DB_NAME') ?: 'escalas',
            'charset' => 'utf8mb4',
            'usuario' => getenv('DB_USER') ?: '',
            'senha'   => getenv('DB_PASS') ?: '',
        ],
        'notificacoes' => [
            'email' => [
                'modo'      => getenv('EMAIL_MODO') ?: 'simulacao',   // 'simulacao' ou 'smtp'
                'host'      => getenv('SMTP_HOST') ?: '',
                'porta'     => (int) (getenv('SMTP_PORTA') ?: 587),
                'seguranca' => getenv('SMTP_SEGURANCA') ?: 'tls',
                'usuario'   => getenv('SMTP_USUARIO') ?: '',
                'senha'     => getenv('SMTP_SENHA') ?: '',
                'de_email'  => getenv('SMTP_DE_EMAIL') ?: '',
                'de_nome'   => getenv('SMTP_DE_NOME') ?: 'Sistema de Escalas',
            ],
            'whatsapp' => ['modo' => getenv('WHATSAPP_MODO') ?: 'simulacao'],
        ],
    ];
}

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
    $b     = $config['banco'];
    $porta = (int) ($b['porta'] ?? 3306);

    $pdo = new PDO(
        "mysql:host={$b['host']};port={$porta};dbname={$b['nome']};charset={$b['charset']}",
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