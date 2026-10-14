<?php

// src/cron_avisos.php
// Roda a cada minuto (cron). Faz duas coisas:
//   1) Cria os lembretes automáticos (escalas que acontecem nas próximas 48h)
//   2) Envia as mensagens pendentes da tabela fila_envios
//
// Uso:   php /var/www/html/cron_avisos.php
//
// Este arquivo depende da função enviarMensagem() do notificar.php:
//   enviarMensagem(array $item): array
//   Devolve ['ok' => true/false, 'erro' => 'texto do erro ou null']

// Só pode rodar pelo terminal/cron, nunca pelo navegador.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

require __DIR__ . '/conexao.php';
require __DIR__ . '/notificar.php';

const MAX_TENTATIVAS      = 3;   // depois disso a mensagem vira ERRO
const TAMANHO_LOTE        = 50;  // mensagens por execução
const TEMPO_MAX_SEGUNDOS  = 50;  // para terminar antes da próxima execução
const LEMBRETE_HORAS      = 48;  // antecedência do lembrete
const PREFIXO_CHAVE       = 'lembrete-escala-';

// ---------------------------------------------------------------
// Evita duas execuções ao mesmo tempo
// ---------------------------------------------------------------
$trava = fopen(sys_get_temp_dir() . '/escalas_cron_avisos.lock', 'c');
if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) {
    exit(0); // já existe outra execução em andamento
}

function registrarLog(string $mensagem): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL;
}

// ---------------------------------------------------------------
// 1) Lembretes automáticos
// Cria um item na fila para cada escala publicada que começa nas
// próximas LEMBRETE_HORAS horas. A chave_unica impede duplicidade.
// ---------------------------------------------------------------
function criarLembretes(PDO $pdo): int
{
    $dias  = (int) ceil(LEMBRETE_HORAS / 24);
    $horas = (int) LEMBRETE_HORAS;

    $sql = "SELECT es.id AS escala_id, es.usuario_id, es.funcao,
                   ev.nome AS evento, ev.data AS data_evento, ev.hora_inicio,
                   d.nome AS departamento,
                   u.telefone, u.consentimento_whatsapp_em
              FROM escalas es
              JOIN eventos ev      ON ev.id = es.evento_id
              JOIN departamentos d ON d.id  = es.departamento_id
              JOIN usuarios u      ON u.id  = es.usuario_id AND u.ativo = 1
              LEFT JOIN fila_envios f
                     ON f.chave_unica = CONCAT('" . PREFIXO_CHAVE . "', es.id, '-usuario-', es.usuario_id)
             WHERE es.status IN ('ENVIADA', 'ACEITA')
               AND ev.data BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $dias DAY)
               AND TIMESTAMP(ev.data, ev.hora_inicio) > NOW()
               AND TIMESTAMP(ev.data, ev.hora_inicio) <= DATE_ADD(NOW(), INTERVAL $horas HOUR)
               AND f.id IS NULL";

    $inserir = $pdo->prepare(
        "INSERT INTO fila_envios (usuario_id, canal, template, mensagem, chave_unica)
         VALUES (?, ?, 'lembrete_escala', ?, ?)
         ON DUPLICATE KEY UPDATE id = id"
    );

    $criados = 0;

    foreach ($pdo->query($sql)->fetchAll() as $r) {
        // WhatsApp só para quem consentiu e tem telefone; os demais recebem e-mail
        $canal = (!empty($r['consentimento_whatsapp_em']) && !empty($r['telefone']))
            ? 'WHATSAPP'
            : 'EMAIL';

        $parametros = json_encode([
            'evento'       => $r['evento'],
            'departamento' => $r['departamento'],
            'funcao'       => $r['funcao'] ?? '',
            'data'         => date('d/m/Y', strtotime($r['data_evento'])),
            'hora'         => substr($r['hora_inicio'], 0, 5),
        ], JSON_UNESCAPED_UNICODE);

        $chave = PREFIXO_CHAVE . $r['escala_id'] . '-usuario-' . $r['usuario_id'];

        $inserir->execute([$r['usuario_id'], $canal, $parametros, $chave]);
        if ($inserir->rowCount() === 1) {
            $criados++;
        }
    }

    return $criados;
}

// ---------------------------------------------------------------
// 2) Envio da fila
// ---------------------------------------------------------------

// Mensagem que não adianta tentar de novo (ex.: sem consentimento)
function descartarItem(PDO $pdo, int $id, string $motivo): void
{
    $pdo->prepare("UPDATE fila_envios SET status = 'ERRO', erro = ? WHERE id = ?")
        ->execute([substr($motivo, 0, 255), $id]);
}

function registrarResultado(PDO $pdo, array $item, bool $ok, ?string $erro): void
{
    if ($ok) {
        $pdo->prepare(
            "UPDATE fila_envios
                SET status = 'ENVIADO', tentativas = tentativas + 1,
                    enviado_em = NOW(), erro = NULL
              WHERE id = ?"
        )->execute([$item['id']]);
        return;
    }

    $tentativas = (int) $item['tentativas'] + 1;
    $erro = substr($erro ?? 'Falha desconhecida', 0, 255);

    if ($tentativas >= MAX_TENTATIVAS) {
        $pdo->prepare(
            "UPDATE fila_envios SET status = 'ERRO', tentativas = ?, erro = ? WHERE id = ?"
        )->execute([$tentativas, $erro, $item['id']]);
    } else {
        // Tenta de novo mais tarde: 5 min depois da 1ª falha, 10 min depois da 2ª
        $pdo->prepare(
            "UPDATE fila_envios
                SET status = 'PENDENTE', tentativas = ?, erro = ?,
                    agendado_para = DATE_ADD(NOW(), INTERVAL ? MINUTE)
              WHERE id = ?"
        )->execute([$tentativas, $erro, 5 * $tentativas, $item['id']]);
    }
}

function processarFila(PDO $pdo): array
{
    $enviados = 0;
    $falhas   = 0;
    $inicio   = time();

    $sql = "SELECT f.*,
                   u.nome  AS usuario_nome,
                   u.email AS usuario_email,
                   u.telefone AS usuario_telefone,
                   u.ativo AS usuario_ativo,
                   u.consentimento_whatsapp_em AS usuario_consentimento
              FROM fila_envios f
              JOIN usuarios u ON u.id = f.usuario_id
             WHERE f.status = 'PENDENTE'
               AND f.agendado_para <= NOW()
               AND f.tentativas < " . MAX_TENTATIVAS . "
             ORDER BY f.agendado_para, f.id
             LIMIT " . TAMANHO_LOTE;

    $reservar = $pdo->prepare(
        "UPDATE fila_envios SET status = 'EM_ENVIO' WHERE id = ? AND status = 'PENDENTE'"
    );

    foreach ($pdo->query($sql)->fetchAll() as $item) {
        if (time() - $inicio > TEMPO_MAX_SEGUNDOS) {
            break; // o restante fica para a próxima execução
        }

        // Reserva o item. Se outro processo pegou antes, rowCount será 0.
        $reservar->execute([$item['id']]);
        if ($reservar->rowCount() !== 1) {
            continue;
        }

        // Verificações antes de enviar
        if (!(int) $item['usuario_ativo']) {
            descartarItem($pdo, (int) $item['id'], 'Usuário inativo.');
            $falhas++;
            continue;
        }
        if ($item['canal'] === 'WHATSAPP'
            && (empty($item['usuario_telefone']) || empty($item['usuario_consentimento']))) {
            descartarItem($pdo, (int) $item['id'], 'Sem telefone ou sem consentimento para WhatsApp.');
            $falhas++;
            continue;
        }
        if ($item['canal'] === 'EMAIL' && empty($item['usuario_email'])) {
            descartarItem($pdo, (int) $item['id'], 'Usuário sem e-mail.');
            $falhas++;
            continue;
        }

        // Envio (um erro aqui não pode derrubar o restante da fila)
        try {
            $resultado = enviarMensagem($item);
            $ok   = !empty($resultado['ok']);
            $erro = $resultado['erro'] ?? null;
        } catch (Throwable $t) {
            $ok   = false;
            $erro = $t->getMessage();
        }

        registrarResultado($pdo, $item, $ok, $erro);
        $ok ? $enviados++ : $falhas++;
    }

    return [$enviados, $falhas];
}

// ---------------------------------------------------------------
// Execução
// ---------------------------------------------------------------
try {
    // Com a trava ativa, qualquer EM_ENVIO é sobra de uma execução que
    // foi interrompida. Devolve para a fila.
    $pdo->exec("UPDATE fila_envios SET status = 'PENDENTE' WHERE status = 'EM_ENVIO'");

    $lembretes = criarLembretes($pdo);
    [$enviados, $falhas] = processarFila($pdo);

    // Só escreve no log quando algo aconteceu
    if ($lembretes || $enviados || $falhas) {
        registrarLog("Lembretes criados: $lembretes | Enviados: $enviados | Falhas: $falhas");
    }
} catch (Throwable $t) {
    error_log('cron_avisos: ' . $t->getMessage());
    registrarLog('ERRO: ' . $t->getMessage());
    exit(1);
}
