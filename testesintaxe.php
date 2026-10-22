<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Captura erros fatais do compilar/executar
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        echo "<h3 style='color:red;'>ERRO FATAL ENCONTRADO:</h3>";
        echo "<pre style='background:#f8d7da; color:#721c24; padding:15px; border:1px solid #f5c6cb;'>";
        echo "Mensagem: " . $error['message'] . "\n";
        echo "Arquivo:  " . $error['file'] . "\n";
        echo "Linha:    " . $error['line'] . "\n";
        echo "</pre>";
    }
});

echo "<h2>Testando leitura do arquivo usuarios.php:</h2>";

try {
    require_once __DIR__ . '/usuarios.php';
    echo "<p style='color:green;'>Sintaxe OK! O arquivo carregou sem erros fatais.</p>";
} catch (\Throwable $e) {
    echo "<h3 style='color:red;'>EXCEÇÃO CAPTURADA:</h3>";
    echo "<pre style='background:#f8d7da; color:#721c24; padding:15px; border:1px solid #f5c6cb;'>";
    echo "Mensagem: " . $e->getMessage() . "\n";
    echo "Arquivo:  " . $e->getFile() . "\n";
    echo "Linha:    " . $e->getLine() . "\n";
    echo "</pre>";
}