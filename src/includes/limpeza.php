<?php
// src/includes/limpeza.php
// Exclusões em cadeia: escalas de um departamento e o departamento em si.
//
// IMPORTANTE: estas funções NÃO abrem transação. Quem chama usa
// $pdo->beginTransaction() antes e $pdo->commit() depois, para que tudo seja
// desfeito se algo falhar no meio.

// ---------------------------------------------------------------
// Apaga as escalas de um departamento. Devolve quantas foram apagadas.
//   $apenasFuturas = true  -> só eventos que ainda não terminaram
//   $apenasFuturas = false -> todas, inclusive o histórico
// Também apaga os pedidos de troca ligados a essas escalas e cancela os
// lembretes que ainda não foram enviados (para ninguém receber aviso de
// uma escala que não existe mais).
// ---------------------------------------------------------------
function limpezaApagarEscalas(PDO $pdo, int $depId, bool $apenasFuturas): int
{
    $filtro = $apenasFuturas ? ' AND TIMESTAMP(ev.data, ev.hora_fim) >= NOW()' : '';

    // 1) Lembretes pendentes dessas escalas (a chave é lembrete-escala-ID-usuario-ID)
    $pdo->prepare(
        "UPDATE fila_envios f
           JOIN escalas es ON f.chave_unica LIKE CONCAT('lembrete-escala-', es.id, '-usuario-%')
           JOIN eventos ev ON ev.id = es.evento_id
            SET f.status = 'ERRO', f.erro = 'Escala apagada'
          WHERE es.departamento_id = ? AND f.status = 'PENDENTE'" . $filtro
    )->execute([$depId]);

    // 2) Pedidos de troca (têm chave estrangeira para a escala)
    $pdo->prepare(
        'DELETE t
           FROM trocas t
           JOIN escalas es ON es.id = t.escala_id
           JOIN eventos ev ON ev.id = es.evento_id
          WHERE es.departamento_id = ?' . $filtro
    )->execute([$depId]);

    // 3) As escalas
    $stmt = $pdo->prepare(
        'DELETE es
           FROM escalas es
           JOIN eventos ev ON ev.id = es.evento_id
          WHERE es.departamento_id = ?' . $filtro
    );
    $stmt->execute([$depId]);

    return $stmt->rowCount();
}

// ---------------------------------------------------------------
// Exclui o departamento, as vagas e os vínculos das pessoas com ele.
// As pessoas continuam cadastradas.
// Antes, as escalas do departamento precisam ter sido apagadas
// (o banco recusa se ainda existir alguma).
// Quem liderava só este departamento volta a ser Voluntário.
// ---------------------------------------------------------------
function limpezaExcluirDepartamento(PDO $pdo, int $depId): void
{
    $stmt = $pdo->prepare(
        'SELECT usuario_id FROM departamento_usuario WHERE departamento_id = ? AND e_lider = 1'
    );
    $stmt->execute([$depId]);
    $lideres = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $pdo->prepare('DELETE FROM vagas WHERE departamento_id = ?')->execute([$depId]);
    $pdo->prepare('DELETE FROM departamento_usuario WHERE departamento_id = ?')->execute([$depId]);
    $pdo->prepare('DELETE FROM departamentos WHERE id = ?')->execute([$depId]);

    foreach ($lideres as $lider) {
        $pdo->prepare(
            "UPDATE usuarios SET perfil = 'VOLUNTARIO'
              WHERE id = ? AND perfil = 'LIDER'
                AND NOT EXISTS (SELECT 1 FROM departamento_usuario
                                 WHERE usuario_id = ? AND e_lider = 1)"
        )->execute([$lider, $lider]);
    }
}