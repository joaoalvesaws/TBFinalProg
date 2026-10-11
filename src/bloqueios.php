<?php
session_start();
require 'conexao.php';
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // (verificar o token CSRF aqui, ver seção de segurança)
    $sql = "INSERT INTO bloqueios (usuario_id, data_inicio, data_fim) VALUES (?, ?, ?)";
    $pdo->prepare($sql)->execute([$_SESSION['usuario_id'], $_POST['data_inicio'], $_POST['data_fim']]);
}
$stmt = $pdo->prepare("SELECT * FROM bloqueios WHERE usuario_id = ?");
$stmt->execute([$_SESSION['usuario_id']]);
$bloqueios = $stmt->fetchAll();
?>
<?php include 'includes/topo.php'; ?>
    <h1>Meus bloqueios</h1>
        <form method="POST">
            <input type="date" name="data_inicio" required>
            <input type="date" name="data_fim" required>
            <button type="submit">Bloquear</button>
        </form>
<ul>
    <?php foreach ($bloqueios as $b): ?>
        <li><?= htmlspecialchars($b['data_inicio']) ?> até <?= htmlspecialchars($b['data_fim']) ?></li>
    <?php endforeach; ?>
</ul>
    <?php include 'includes/rodape.php'; ?>