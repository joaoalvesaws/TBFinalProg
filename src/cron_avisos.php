<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        echo "<h3 style='color:red;'>ERRO FATAL NO CRON_AVISOS.PHP:</h3>";
        echo "<pre style='background:#f8d7da; color:#721c24; padding:15px; border:1px solid #f5c6cb;'>";
        echo "Mensagem: " . $error['message'] . "\n";
        echo "Arquivo:  " . $error['file'] . "\n";
        echo "Linha:    " . $error['line'] . "\n";
        echo "</pre>";
    }
});

// CHAVE DE SEGURANÇA
define('CRON_KEY', 'Kosmos2026@');

// Bloqueia se não for CLI e não passar a chave correta via GET
if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== CRON_KEY) {
    http_response_code(403);
    exit('Acesso negado.');
}

require __DIR__ . '/conexao.php';
require __DIR__ . '/notificar.php';

const MAX_TENTATIVAS      = 3;    // depois disso o item vira ERRO
const MINUTOS_ENTRE_TENT  = 5;    // espera antes de tentar de novo
const MINUTOS_TRAVADO     = 10;   // EM_ENVIO por mais tempo que isso é considerado travado

// 1. Destrava itens que ficaram em EM_ENVIO (ex.: o script caiu no meio)
$pdo->exec(
    "UPDATE fila_envios
        SET status = 'PENDENTE'
      WHERE status = 'EM_ENVIO'
        AND atualizado_em < (NOW() - INTERVAL " . MINUTOS_TRAVADO . " MINUTE)"
);

// 2. Busca os pendentes que já chegaram na hora
$pendentes = $pdo->query(
    "SELECT * FROM fila_envios
      WHERE status = 'PENDENTE'
        AND agendado_para <= NOW()
        AND tentativas < " . MAX_TENTATIVAS . "
      ORDER BY id
      LIMIT 50"
)->fetchAll();

$reservar = $pdo->prepare(
    "UPDATE fila_envios SET status = 'EM_ENVIO', atualizado_em = NOW()
      WHERE id = ? AND status = 'PENDENTE'"
);
$sucesso = $pdo->prepare(
    "UPDATE fila_envios
        SET status = 'ENVIADO', tentativas = ?, enviado_em = NOW(), atualizado_em = NOW()
      WHERE id = ?"
);
$falha = $pdo->prepare(
    "UPDATE fila_envios
        SET status = ?, tentativas = ?, atualizado_em = NOW(),
            agendado_para = NOW() + INTERVAL " . MINUTOS_ENTRE_TENT . " MINUTE
      WHERE id = ?"
);

$enviados = 0;
$falhas   = 0;

foreach ($pendentes as $item) {
    // 3. Reserva o item. Se outra execução do cron pegou antes,
    //    rowCount() fica 0 e a gente pula (evita mensagem duplicada).
    $reservar->execute([$item['id']]);
    if ($reservar->rowCount() !== 1) {
        continue;
    }

    $tentativas = (int) $item['tentativas'] + 1;

    if (enviarMensagem($pdo, $item)) {
        $sucesso->execute([$tentativas, $item['id']]);
        $enviados++;
    } else {
        $status = $tentativas >= MAX_TENTATIVAS ? 'ERRO' : 'PENDENTE';
        $falha->execute([$status, $tentativas, $item['id']]);
        $falhas++;
    }
}

echo date('d/m/Y H:i:s') . " - enviados: $enviados | falhas: $falhas\n";
