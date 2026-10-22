<?php
// TODAS as regras de escala ficam neste arquivo.
// É usado pela montagem manual (lider_escala.php), pelas trocas
// (trocas.php) e, no futuro, pelo gerador automático.
//
// Regra nova? Crie a função aqui e chame-a dentro de violacoes().
// Nenhuma outra tela deve repetir estas conferências.
//
// Este arquivo só define funções. Quem o inclui precisa ter o $pdo
// (já vem do conexao.php, carregado pelo includes/sessao.php).

// ---------------------------------------------------------------
// Regra 3: bloqueio de data informado pelo voluntário
// Devolve true se a data cai dentro de algum período bloqueado.
// ---------------------------------------------------------------
function temBloqueio(PDO $pdo, int $usuarioId, string $data): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
           FROM bloqueios
          WHERE usuario_id = ? AND data_inicio <= ? AND data_fim >= ?
          LIMIT 1'
    );
    $stmt->execute([$usuarioId, $data, $data]);

    return (bool) $stmt->fetchColumn();
}

// ---------------------------------------------------------------
// Regra 1: ninguém atua em 2 dias seguidos no MESMO departamento
// "Dias seguidos" = dias do calendário (sábado e domingo, por exemplo).
// Rascunhos também contam, para o líder não montar um plano que já
// nasce em conflito.
// Devolve a data (AAAA-MM-DD) do dia vizinho em conflito, ou null.
// ---------------------------------------------------------------
function escaladoDiaAnteriorOuSeguinte(PDO $pdo, int $usuarioId, int $depId, string $data): ?string
{
    $dia      = new DateTimeImmutable($data);
    $anterior = $dia->modify('-1 day')->format('Y-m-d');
    $seguinte = $dia->modify('+1 day')->format('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT ev.data
           FROM escalas es
           JOIN eventos ev ON ev.id = es.evento_id
          WHERE es.usuario_id = ?
            AND es.departamento_id = ?
            AND ev.data IN (?, ?)
          ORDER BY ev.data
          LIMIT 1'
    );
    $stmt->execute([$usuarioId, $depId, $anterior, $seguinte]);

    $encontrada = $stmt->fetchColumn();

    return $encontrada === false ? null : $encontrada;
}

// ---------------------------------------------------------------
// Regra 2: ninguém em 2 cargos/funções no mesmo dia e horário
// Vale para QUALQUER departamento e qualquer igreja: a pessoa não
// consegue estar em dois lugares ao mesmo tempo.
// Dois eventos se sobrepõem quando um começa antes de o outro terminar.
// Evento que termina "antes" de começar (passa da meia-noite) é
// tratado como se fosse até 24:00.
// ---------------------------------------------------------------
function temOutraFuncaoNoMesmoHorario(PDO $pdo, int $usuarioId, array $evento): bool
{
    $inicio = $evento['hora_inicio'];
    $fim    = $evento['hora_fim'] > $evento['hora_inicio'] ? $evento['hora_fim'] : '24:00:00';

    $stmt = $pdo->prepare(
        "SELECT 1
           FROM escalas es
           JOIN eventos ev ON ev.id = es.evento_id
          WHERE es.usuario_id = ?
            AND ev.data = ?
            AND ev.hora_inicio < ?
            AND IF(ev.hora_fim > ev.hora_inicio, ev.hora_fim, '24:00:00') > ?
          LIMIT 1"
    );
    $stmt->execute([$usuarioId, $evento['data'], $fim, $inicio]);

    return (bool) $stmt->fetchColumn();
}

// ---------------------------------------------------------------
// Função principal
// $evento = linha inteira da tabela eventos (precisa de data,
//           hora_inicio e hora_fim)
// Devolve uma lista de textos com as regras violadas.
// Lista vazia = pode escalar.
// ---------------------------------------------------------------
function violacoes(PDO $pdo, int $usuarioId, array $evento, int $depId): array
{
    $erros = [];

    if (temBloqueio($pdo, $usuarioId, $evento['data'])) {
        $erros[] = 'Marcou esta data como indisponível.';
    }

    $diaVizinho = escaladoDiaAnteriorOuSeguinte($pdo, $usuarioId, $depId, $evento['data']);
    if ($diaVizinho !== null) {
        $erros[] = 'Já atua neste departamento em dia seguido ('
                 . date('d/m/Y', strtotime($diaVizinho)) . ').';
    }

    if (temOutraFuncaoNoMesmoHorario($pdo, $usuarioId, $evento)) {
        $erros[] = 'Já está escalado(a) em outra função neste horário.';
    }

    return $erros;
}