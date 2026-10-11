<?php

return [

    // 'desenvolvimento' (seu computador) ou 'producao' (servidor real)
    'ambiente' => 'desenvolvimento',
    'url_base' => 'http://localhost:8080',
    'fuso_horario' => 'America/Sao_Paulo',
    'banco' => [
        'host'    => 'db',            // no Docker é o nome do serviço, não "localhost"
        'nome'    => 'escalas',
        'usuario' => 'escalas',
        'senha'   => 'escalas_dev',
        'charset' => 'utf8mb4',
    ],

];