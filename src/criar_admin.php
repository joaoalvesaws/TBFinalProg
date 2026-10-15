<?php
// src/criar_admin.php
// Cria o PRIMEIRO administrador geral. Só roda pelo terminal, nunca pelo navegador.
//
// Uso:
//   docker compose exec web php /var/www/html/criar_admin.php "Seu Nome" seu@email.com
// Depois ele pede a senha (mínimo 8 caracteres).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

require __DIR__ . '/conexao.php';

$nome  = trim($argv[1] ?? '');
$email = mb_strtolower(trim($argv[2] ?? ''));

if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Uso: php criar_admin.php \"Seu Nome\" seu@email.com\n";
    exit(1);
}

$existe = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ?');
$existe->execute([$email]);
if ($existe->fetch()) {
    echo "Já existe um usuário com o e-mail $email.\n";
    exit(1);
}

// Lê a senha escondendo o que é digitado (quando o terminal permite)
echo 'Digite a senha (mínimo 8 caracteres): ';
$ocultar = function_exists('posix_isatty') && posix_isatty(STDIN);
if ($ocultar) {
    shell_exec('stty -echo');
}
$senha = rtrim((string) fgets(STDIN), "\r\n");
if ($ocultar) {
    shell_exec('stty echo');
    echo "\n";
}

if (strlen($senha) < 8 || strlen($senha) > 72) {
    echo "A senha precisa ter entre 8 e 72 caracteres.\n";
    exit(1);
}

$pdo->prepare(
    "INSERT INTO usuarios (igreja_id, nome, email, senha_hash, perfil)
     VALUES (NULL, ?, ?, ?, 'ADMIN_GERAL')"
)->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);

echo "Administrador geral criado: $nome <$email>\n";
