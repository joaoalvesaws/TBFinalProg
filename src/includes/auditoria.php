<?php
// src/includes/auditoria.php
// Este arquivo SÓ registra o que acontece no sistema: ações dos usuários e suas
// alterações, trocas, lançamento de escala, aprovação de trocas, tudo que
// envolva o id de um usuário. Não toma decisão nenhuma, só grava na tabela auditoria.
//
// Quem chama: as telas usam auditar() (em util.php), que chama esta função
// e não derruba a tela se a gravação falhar.
//
// Nomes de ação sugeridos (sempre MAIÚSCULAS, no padrão ENTIDADE_ACAO):
//   LOGIN, SENHA_DEFINIDA, RECUPERACAO_SOLICITADA
//   USUARIO_CRIADO, USUARIO_DESATIVADO, USUARIO_REATIVADO, CONVITE_REENVIADO
//   IGREJA_CRIADA, DEPARTAMENTO_CRIADO, EVENTO_CRIADO, EVENTO_EXCLUIDO
//   ESCALA_CRIADA, ESCALA_REMOVIDA, ESCALA_PUBLICADA
//   BLOQUEIO_CRIADO, BLOQUEIO_REMOVIDO
//   TROCA_SOLICITADA, TROCA_ACEITA, TROCA_RECUSADA, TROCA_AGUARDANDO_LIDER,
//   TROCA_APROVADA, TROCA_REJEITADA
//
// Não coloque senhas, tokens nem motivos de bloqueio em $dados (LGPD).

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