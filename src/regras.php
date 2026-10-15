<?php
// src/regras.php
// TODAS as regras de escala ficam aqui (montagem manual, trocas e gerador usam as mesmas).
// Regras do briefing:
//   1. Ninguém atua em 2 dias seguidos no mesmo departamento.
//   2. Ninguém pode estar em 2 funções no mesmo dia e horário.
//   3. Bloqueios de data informados pelo voluntário devem ser respeitados.
// Uma troca que viola alguma regra não é rejeitada: segue para aprovação do líder.

// Confere se o destinatário pode assumir a escala $escala (linha com id, evento_id,
// departamento_id, data, hora_inicio, hora_fim). Devolve o texto do 1º problema
// encontrado, ou null se está tudo certo.
function trocaViolaRegras(PDO $pdo, array $escala, int $destinatarioId): ?string
{
    // Regra 3: indisponibilidade do destinatário na data
    $stmt = $pdo->prepare(
        'SELECT 1 FROM bloqueios
          WHERE usuario_id = ? AND data_inicio <= ? AND data_fim >= ? LIMIT 1'
    );
    $stmt->execute([$destinatarioId, $escala['data'], $escala['data']]);
    if ($stmt->fetchColumn()) {
        return 'O voluntário destinatário informou indisponibilidade nesta data.';
    }

    // Regra 2: já escalado no mesmo dia e horário (horários que se sobrepõem)
    $stmt = $pdo->prepare(
        'SELECT 1
           FROM escalas s
           JOIN eventos ev ON ev.id = s.evento_id
          WHERE s.usuario_id = ? AND ev.data = ?
            AND ev.hora_inicio < ? AND ev.hora_fim > ?
          LIMIT 1'
    );
    $stmt->execute([$destinatarioId, $escala['data'], $escala['hora_fim'], $escala['hora_inicio']]);
    if ($stmt->fetchColumn()) {
        return 'O voluntário destinatário já possui plantão neste mesmo dia e horário.';
    }

    // Regra 1: plantão no dia anterior ou seguinte, no mesmo departamento
    $stmt = $pdo->prepare(
        'SELECT 1
           FROM escalas s
           JOIN eventos ev ON ev.id = s.evento_id
          WHERE s.usuario_id = ? AND s.departamento_id = ?
            AND ev.data IN (DATE_SUB(?, INTERVAL 1 DAY), DATE_ADD(?, INTERVAL 1 DAY))
          LIMIT 1'
    );
    $stmt->execute([$destinatarioId, $escala['departamento_id'], $escala['data'], $escala['data']]);
    if ($stmt->fetchColumn()) {
        return 'O voluntário destinatário já possui plantão no dia anterior ou seguinte. A troca criaria dias seguidos de plantão.';
    }

    return null;
}
