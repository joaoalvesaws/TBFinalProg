<?php
// src/notificar.php
// Camada ÚNICA de notificações. O resto do sistema só chama enfileirar();
// quem envia de verdade é o cron_avisos.php, que chama enviarMensagem().
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/conexao.php';

// ---------------------------------------------------------------
// 1. Colocar uma mensagem na fila (chamado pelas telas)
// ---------------------------------------------------------------
function enfileirar(PDO $pdo, int $usuarioId, string $canal, string $template,
                    string $mensagem, ?string $agendadoPara = null): bool
{
    if ($canal === 'WHATSAPP') {
        // LGPD: só envia WhatsApp para quem aceitou e tem telefone
        $s = $pdo->prepare(
            'SELECT telefone, consentimento_whatsapp_em
               FROM usuarios WHERE id = ? AND ativo = 1'
        );
        $s->execute([$usuarioId]);
        $u = $s->fetch();

        if (!$u || empty($u['telefone']) || empty($u['consentimento_whatsapp_em'])) {
            return false;
        }
    }

    $pdo->prepare(
        'INSERT INTO fila_envios (usuario_id, canal, template, mensagem, agendado_para)
         VALUES (?, ?, ?, ?, COALESCE(?, NOW()))'
    )->execute([$usuarioId, $canal, $template, $mensagem, $agendadoPara]);

    return true;
}

// ---------------------------------------------------------------
// 2. Enviar um item da fila (chamado pelo cron_avisos.php)
// ---------------------------------------------------------------
function enviarMensagem(PDO $pdo, array $item): bool
{
    $s = $pdo->prepare(
        'SELECT nome, email, telefone, consentimento_whatsapp_em
           FROM usuarios WHERE id = ? AND ativo = 1'
    );
    $s->execute([$item['usuario_id']]);
    $usuario = $s->fetch();

    if (!$usuario) {
        error_log("Fila #{$item['id']}: usuário {$item['usuario_id']} não existe ou está inativo.");
        return false;
    }

    try {
        if ($item['canal'] === 'EMAIL') {
            return enviarEmail($usuario, $item);
        }
        if ($item['canal'] === 'WHATSAPP') {
            return enviarWhatsapp($usuario, $item);
        }
    } catch (Throwable $ex) {
        error_log("Fila #{$item['id']}: erro ao enviar: " . $ex->getMessage());
        return false;
    }

    error_log("Fila #{$item['id']}: canal desconhecido ({$item['canal']}).");
    return false;
}

// ---------------------------------------------------------------
// 3. E-mail
// ---------------------------------------------------------------
function assuntoEmail(string $template): string
{
    $assuntos = [
        'convite_senha'   => 'Defina sua senha no Sistema de Escalas',
        'recuperar_senha' => 'Redefinição de senha - Sistema de Escalas',
    ];
    return $assuntos[$template] ?? 'Aviso do Sistema de Escalas';
}

function enviarEmail(array $usuario, array $item): bool
{
    global $config;

    if (empty($usuario['email'])) {
        error_log("[AVISO NOTIFICAÇÃO] Envio cancelado: e-mail do usuário está vazio.");
        return false;
    }

    $cfg     = $config['notificacoes']['email'] ?? [];
    $modo    = $cfg['modo'] ?? 'simulacao';   // 'simulacao', 'api' ou 'smtp'
    $assunto = assuntoEmail((string) ($item['template'] ?? ''));

    // Modo 1: Simulação (apenas grava log)
    if ($modo === 'simulacao') {
        error_log("[SIMULACAO E-MAIL] Para: {$usuario['email']} | Assunto: $assunto\n{$item['mensagem']}");
        return true;
    }

    // Modo 2: API HTTP Brevo (Ideal para InfinityFree)
    if ($modo === 'api') {
        $apiKey = $cfg['api_key'] ?? '';
        if (empty($apiKey)) {
            error_log('[ERRO E-MAIL] API Key do Brevo não configurada.');
            return false;
        }

        $payload = [
            'sender' => [
                'name'  => $cfg['de_nome'] ?? 'Sistema de Escalas',
                'email' => $cfg['de_email'] ?? 'joaoalves.compucenter@gmail.com'
            ],
            'to' => [
                [
                    'email' => $usuario['email'],
                    'name'  => $usuario['nome'] ?? ''
                ]
            ],
            'subject'     => $assunto,
            'htmlContent' => nl2br(htmlspecialchars($item['mensagem']))
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
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno) {
            error_log("[ERRO cURL BREVO] {$curlError}");
            return false;
        }

        if ($httpCode === 201 || $httpCode === 200) {
            return true;
        }

        error_log("[ERRO BREVO API] HTTP $httpCode - Resposta: $response");
        return false;
    }

    // Modo 3: SMTP tradicional via PHPMailer
    if ($modo === 'smtp') {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!is_file($autoload)) {
            error_log('PHPMailer não encontrado (vendor/autoload.php).');
            return false;
        }
        require_once $autoload;

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->Port       = (int) ($cfg['porta'] ?? 587);
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['usuario'];
            $mail->Password   = $cfg['senha'];
            $mail->SMTPSecure = ($cfg['seguranca'] ?? 'tls') === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($cfg['de_email'], $cfg['de_nome'] ?? 'Sistema de Escalas');
            $mail->addAddress($usuario['email'], $usuario['nome'] ?? '');
            $mail->Subject    = $assunto;
            $mail->Body       = $item['mensagem'];
            $mail->send();

            return true;
        } catch (\Exception $e) {
            error_log('[ERRO SMTP] ' . $e->getMessage());
            return false;
        }
    }

    return false;
}

// ---------------------------------------------------------------
// 4. WhatsApp
// ---------------------------------------------------------------
function enviarWhatsapp(array $usuario, array $item): bool
{
    global $config;

    $modo = $config['notificacoes']['whatsapp']['modo'] ?? 'simulacao';

    if (empty($usuario['consentimento_whatsapp_em']) || empty($usuario['telefone'])) {
        error_log("Fila #{$item['id']}: usuário sem consentimento ou sem telefone para WhatsApp.");
        return false;
    }

    $numero = '55' . preg_replace('/\D/', '', $usuario['telefone']);

    if ($modo !== 'real') {
        error_log("[SIMULACAO WHATSAPP] Para: $numero | Template: {$item['template']}\n{$item['mensagem']}");
        return true;
    }

    error_log('Envio real de WhatsApp ainda não implementado.');
    return false;
}