<?php

try {
    $pdo = new PDO(
        "mysql:host=sql109.infinityfree.com;dbname=if0_43239028_escalas;charset=utf8mb4",
        "if0_43239028",
        "CHAVE DE SENHA DE ACESSO"
    );

    echo "Conectado com sucesso!";
} catch (PDOException $e) {
    die("Erro: " . $e->getMessage());
}