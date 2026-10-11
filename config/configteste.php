<?php

return [
    'ambiente'     => 'producao',
    'url_base'     => 'https://escalas.suaigreja.com.br',
    'fuso_horario' => 'America/Sao_Paulo',

    'banco' => [
        'host'    => 'localhost',
        'nome'    => 'NOME_DO_BANCO',
        'usuario' => 'USUARIO_DO_BANCO',
        'senha'   => 'SENHA_FORTE_AQUI',
        'charset' => 'utf8mb4',
    ],
]; //ESSE CONFIG AQUI SERVE SOMENTE PARA TESTE (ELE QUE VAI RODAR NO AR? NAO, VAI SER O CONFIG, MAS O CONFIG QUE SERA DE BASE (1 POR MAQUINA) ESTA NO GIT IGNORE)
// cada computador deve ter esse config para rodas, com as configuracoes de cada um, banco de dados e etc, porem nao deve ser feito nesse
// deve ser feito uma copia desse arquivo e colocado no gitignore (pq tem informacoes privadas de cada um) 