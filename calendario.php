<?php

require __DIR__ . '/includes/sessao.php';

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare("
    SELECT usuario_id
    FROM calendario_tokens
    WHERE token = ?
");

$stmt->execute([$token]);

$usuarioId = $stmt->fetchColumn();

if (!$usuarioId) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        es.id,
        es.funcao,
        es.status,

        ev.nome AS evento,
        ev.data,
        ev.hora_inicio,
        ev.hora_fim,

        d.nome AS departamento,
        i.nome AS igreja

    FROM escalas es

    JOIN eventos ev
        ON ev.id = es.evento_id

    JOIN departamentos d
        ON d.id = es.departamento_id

    JOIN igrejas i
        ON i.id = ev.igreja_id

    WHERE es.usuario_id = ?
      AND es.status IN ('ENVIADA', 'ACEITA')
      AND TIMESTAMP(ev.data, ev.hora_fim) >= NOW()

    ORDER BY ev.data, ev.hora_inicio
");

$stmt->execute([$usuarioId]);

$escalas = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/calendar; charset=utf-8');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Kosmos//Calendario//PT-BR\r\n";
echo "CALSCALE:GREGORIAN\r\n";

foreach ($escalas as $escala) {

    $inicio = strtotime(
        $escala['data'] . ' ' . $escala['hora_inicio']
    );

    $fim = strtotime(
        $escala['data'] . ' ' . $escala['hora_fim']
    );

    echo "BEGIN:VEVENT\r\n";

    echo "UID:escala-{$escala['id']}@kosmos\r\n";

    echo "SUMMARY:" .
        str_replace(
            ["\r", "\n"],
            '',
            $escala['evento'] . ' - ' . $escala['departamento']
        )
        . "\r\n";

    echo "DTSTART:" .
        gmdate('Ymd\THis\Z', $inicio)
        . "\r\n";

    echo "DTEND:" .
        gmdate('Ymd\THis\Z', $fim)
        . "\r\n";

    echo "LOCATION:" .
        str_replace(
            ["\r", "\n"],
            '',
            $escala['igreja']
        )
        . "\r\n";

    $descricao =
        'Departamento: ' . $escala['departamento'];

    if (!empty($escala['funcao'])) {
        $descricao .= ' | Funcao: ' . $escala['funcao'];
    }

    echo "DESCRIPTION:" .
        str_replace(
            ["\r", "\n"],
            '',
            $descricao
        )
        . "\r\n";

    echo "STATUS:CONFIRMED\r\n";

    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";