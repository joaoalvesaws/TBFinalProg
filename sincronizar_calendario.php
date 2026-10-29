<?php

require __DIR__ . '/includes/sessao.php';

$usuario = exigirLogin();

$stmt = $pdo->prepare(
    "SELECT token
       FROM calendario_tokens
      WHERE usuario_id = ?"
);

$stmt->execute([$usuario['id']]);

$token = $stmt->fetchColumn();

if (!$token) {

    $token = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare(
        "INSERT INTO calendario_tokens (
            usuario_id,
            token
        ) VALUES (?, ?)"
    );

    $stmt->execute([
        $usuario['id'],
        $token
    ]);
}

$protocolo =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https://'
        : 'http://';

$urlCalendario =
    $protocolo .
    $_SERVER['HTTP_HOST'] .
    '/calendario.php?token=' .
    $token;

$titulo = 'Sincronizar Calendário';

include __DIR__ . '/includes/topo.php';
?>

<h1 class="h3 mb-4"> Sincronizar Calendário</h1>

<div class="card mb-4">
    <div class="card-body">

        <p>
            Este link sincroniza automaticamente suas escalas com seu calendário.
        </p>

        <div class="mb-3">
            <label class="form-label">
                Seu link privado
            </label>

            <input
                type="text"
                readonly
                id="linkCalendario"
                class="form-control"
                value="<?= e($urlCalendario) ?>">
        </div>

        <button
            type="button"
            class="btn btn-success"
            onclick="copiarLink()">
             Copiar link
        </button>

    </div>
</div>

<div class="card">
    <div class="card-body">

        <h2 class="h5">Como adicionar ao Google Agenda</h2>

        <ol>
            <li>Acesse o Google Agenda.</li>
            <li>No menu esquerdo clique em <strong>Outros calendários</strong>.</li>
            <li>Clique em <strong>Adicionar por URL</strong>.</li>
            <li>Cole o link copiado.</li>
            <li>Clique em <strong>Adicionar calendário</strong>.</li>
        </ol>

    </div>
</div>

<script>
function copiarLink() {
    const campo =
        document.getElementById('linkCalendario');

    navigator.clipboard.writeText(campo.value);

    alert('Link copiado!');
}
</script>

<?php include __DIR__ . '/includes/rodape.php'; ?>