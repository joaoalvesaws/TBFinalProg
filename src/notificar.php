<?php
// src/notificar.php
// Camada ÚNICA de notificações. O resto do sistema só chama enfileirar();
// quem envia de verdade é o cron_avisos.php, que chama enviarMensagem().
// Para trocar de provedor (e-mail ou WhatsApp), só este arquivo muda.
//
// MODO SIMULAÇÃO: enquanto o provedor não estiver definido, nada é enviado:
// a mensagem aparece no log (veja com: docker compose logs -f web).

require_once __DIR__ . '/conexao.php';

// ---------------------------------------------------------------
// 1. Colocar uma mensagem na fila (chamado pelas telas)
// ---------------------------------------------------------------
// $canal: 'EMAIL' ou 'WHATSAPP'
// $template: nome do modelo (ex.: 'convite_senha'). No WhatsApp é o nome
//            do template aprovado na Meta.
// $agendadoPara: 'AAAA-MM-DD HH:MM:SS' ou null para enviar já.
// Devolve false se não puder enfileirar (ex.: WhatsApp sem consentimento).
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
// Devolve true se enviou (ou simulou), false se falhou.
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

    $cfg     = $config['notificacoes']['email'] ?? [];
    $modo    = $cfg['modo'] ?? 'simulacao';   // 'simulacao' ou 'smtp'
    $assunto = assuntoEmail((string) ($item['template'] ?? ''));

    if ($modo !== 'smtp') {
        error_log("[SIMULACAO E-MAIL] Para: {$usuario['email']} | Assunto: $assunto\n{$item['mensagem']}");
        return true;
    }

    // Envio real por SMTP com PHPMailer (vendor/ montado no Docker em /var/www/vendor)
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('PHPMailer não encontrado (vendor/autoload.php). Rode o composer.');
        return false;
    }
    require_once $autoload;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);   // true = lança exceção em erro
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
    $mail->addAddress($usuario['email'], $usuario['nome']);
    $mail->Subject    = $assunto;
    $mail->Body       = $item['mensagem'];   // texto simples
    $mail->send();

    return true;
}

// ---------------------------------------------------------------
// 4. WhatsApp
// ---------------------------------------------------------------
function enviarWhatsapp(array $usuario, array $item): bool
{
    global $config;

    $modo = $config['notificacoes']['whatsapp']['modo'] ?? 'simulacao';  // 'simulacao' ou 'real'

    // Segurança extra: nunca envia sem consentimento (LGPD)
    if (empty($usuario['consentimento_whatsapp_em']) || empty($usuario['telefone'])) {
        error_log("Fila #{$item['id']}: usuário sem consentimento ou sem telefone para WhatsApp.");
        return false;
    }

    // Formato internacional: 55 + DDD + número
    $numero = '55' . preg_replace('/\D/', '', $usuario['telefone']);

    if ($modo !== 'real') {
        error_log("[SIMULACAO WHATSAPP] Para: $numero | Template: {$item['template']}\n{$item['mensagem']}");
        return true;
    }

    // TODO (quando o provedor for escolhido): chamada HTTP com cURL à API
    // oficial do WhatsApp (direto na Meta ou via provedor), usando o template
    // aprovado. Devolver true só se a API confirmar o recebimento.
    error_log('Envio real de WhatsApp ainda não implementado.');
    return false;
}
