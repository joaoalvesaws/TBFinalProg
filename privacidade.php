<?php
// src/privacidade.php
// Política de privacidade (LGPD). Página pública.
//
// ATENÇÃO: este texto é um MODELO. Preencha os trechos entre [COLCHETES]
// e peça para a liderança (e, se possível, um advogado ou o encarregado
// de dados da organização) revisar antes de usar de verdade.

require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/util.php';

$tituloPagina = 'Política de privacidade';
include __DIR__ . '/includes/topo.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <h1 class="h3 mb-3">Política de privacidade e proteção de dados</h1>
        <p class="text-muted">Última atualização: 15/10/2026</p>

        <h2 class="h5 mt-4">1. Quem é o responsável</h2>
        <p>
            Os dados deste sistema são tratados por (Aqui coloca o nome da organizacao), CNPJ tal,
            que usa o Sistema de Escalas para organizar o trabalho voluntário.
            Contato para assuntos de dados: aqui coloca o email do responsável.
        </p>

        <h2 class="h5 mt-4">2. Quais dados coletamos</h2>
        <ul>
            <li>Nome, e-mail e, se você informar, telefone/WhatsApp.</li>
            <li>A igreja e os departamentos em que você serve, e as escalas em que está incluído.</li>
            <li>Os períodos em que você avisa que está indisponível. Não pedimos nem guardamos o motivo.</li>
            <li>Registros de uso do sistema (quem fez cada alteração e quando), por segurança.</li>
        </ul>
        <p>
            Saber que alguém serve em uma igreja pode revelar a sua crença religiosa, que a lei considera
            dado sensível. Por isso o acesso a esses dados é limitado a administradores e líderes,
            e só dentro da sua própria igreja ou departamento.
        </p>

        <h2 class="h5 mt-4">3. Para que usamos</h2>
        <ul>
            <li>Montar escalas e permitir que você veja seus dias, bloqueie datas e peça trocas.</li>
            <li>Enviar avisos sobre escalas e trocas, por e-mail e, se você aceitar, por WhatsApp.</li>
            <li>Manter a segurança do sistema e o histórico das alterações.</li>
        </ul>

        <h2 class="h5 mt-4">4. WhatsApp</h2>
        <p>
            Só enviamos mensagens por WhatsApp se você autorizar expressamente ao definir a sua senha.
            Você pode retirar essa autorização quando quiser, pedindo a um administrador ou líder
            [ou pela sua página de perfil, quando disponível]. Os avisos por e-mail continuam.
        </p>

        <h2 class="h5 mt-4">5. Com quem compartilhamos</h2>
        <p>
            Não vendemos nem divulgamos seus dados. Para o sistema funcionar, usamos prestadores de serviço:
            hospedagem RENDER, envio de e-mail (ainda nao foi definido) e,
            se você autorizar, envio de WhatsApp ([ainda nao foi definido).
            Eles recebem apenas o necessário para entregar a mensagem.
        </p>

        <h2 class="h5 mt-4">6. Por quanto tempo guardamos</h2>
        <p>
            Enquanto você for voluntário. Quando sair, a conta é desativada e os dados são
            apagados em 1 ano. Registros de auditoria podem ser mantidos por mais tempo
            para segurança.
        </p>

        <h2 class="h5 mt-4">7. Seus direitos</h2>
        <p>
            Você pode pedir: confirmação de que tratamos seus dados, acesso, correção, exclusão,
            portabilidade e informações sobre o compartilhamento, além de retirar consentimentos.
            Fale com o responsavel.
        </p>

        <h2 class="h5 mt-4">8. Segurança</h2>
        <p>
            Senhas são guardadas de forma protegida (nunca em texto aberto), o acesso é feito por conexão
            segura e cada pessoa só vê o que o seu perfil permite.
        </p>
    </div>
</div>

<?php include __DIR__ . '/includes/rodape.php'; ?>
