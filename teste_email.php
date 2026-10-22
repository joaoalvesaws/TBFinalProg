<?php
$apiKey = 'AQUI COLOCA A CHAVE DE API'; 

$payload = [
    'sender' => ['name' => 'Sistema', 'email' => 'kosmos.webservice@gmail.com'],
    'to' => [['email' => 'joaoalves.compucenter@gmail.com', 'name' => 'João']],
    'subject' => 'Teste de Envio Directo',
    'htmlContent' => '<p>Testando envio via API do Brevo</p>'
];

$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<strong>Código HTTP:</strong> " . $httpCode . "<br>";
echo "<strong>Resposta do Brevo:</strong> " . $response;