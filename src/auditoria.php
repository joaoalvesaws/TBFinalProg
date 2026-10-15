//este arquivo aqui vai somente registrar todas as acoes do sistema, acoes dos usuarios e tambem suas alteracoes, trocas, lancamento de escala, aprovacao de trocas, tudo que envolva um id de usuario
<?php

function registrarAuditoria(
    PDO $pdo,
    ?int $usuarioId,
    string $acao,
    string $entidade,
    ?int $entidadeId = null,
    array $dados = []
): void {
    $pdo->prepare(
        'INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id, dados)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $usuarioId,
        $acao,
        $entidade,
        $entidadeId,
        $dados ? json_encode($dados, JSON_UNESCAPED_UNICODE) : null,
    ]);
}