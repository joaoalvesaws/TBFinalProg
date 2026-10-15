/*
 * JavaScript simples do Sistema de Escalas.
 *
 * IMPORTANTE: tudo aqui é só conforto para o usuário.
 * A validação de verdade (datas, permissões, CSRF, regras de escala)
 * continua sendo feita no PHP, porque o JavaScript pode ser alterado
 * ou desligado por quem usa o navegador.
 *
 * Como incluir (em includes/rodape.php, antes de </body>):
 *   <script src="assets/app.js" defer></script>
 */

document.addEventListener('DOMContentLoaded', function () {

  // ------------------------------------------------------------
  // 1. Confirmação antes de ações perigosas (excluir, cancelar...)
  //    HTML: <button type="submit" data-confirma="Excluir este bloqueio?">Excluir</button>
  //    Dica: use dentro de um <form method="POST">, nunca em link GET.
  // ------------------------------------------------------------
  document.querySelectorAll('[data-confirma]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.dataset.confirma)) {
        e.preventDefault();
      }
    });
  });

  // ------------------------------------------------------------
  // 2. Evitar clique duplo no botão de enviar
  //    Impede criar o mesmo bloqueio/troca duas vezes.
  //    HTML (opcional): <button type="submit" data-enviando="Salvando...">Salvar</button>
  // ------------------------------------------------------------
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      // Se outro script já cancelou o envio, não faz nada
      if (e.defaultPrevented) return;

      var botao = form.querySelector('button[type="submit"]');
      if (!botao) return;

      // Espera um instante: desabilitar o botão no mesmo momento do
      // envio pode impedir que o navegador mande o valor do botão.
      setTimeout(function () {
        botao.disabled = true;
        if (botao.dataset.enviando) {
          botao.textContent = botao.dataset.enviando;
        }
      }, 0);
    });
  });

  // ------------------------------------------------------------
  // 3. Datas de início e fim (tela de bloqueios)
  //    HTML: <form data-intervalo-datas>
  //            <input type="date" name="data_inicio">
  //            <input type="date" name="data_fim">
  //          </form>
  //    - a data fim não pode ser anterior à data início
  //    - não permite escolher datas no passado
  // ------------------------------------------------------------
  document.querySelectorAll('[data-intervalo-datas]').forEach(function (form) {
    var inicio = form.querySelector('[name="data_inicio"]');
    var fim = form.querySelector('[name="data_fim"]');
    if (!inicio || !fim) return;

    var hoje = new Date();
    var hojeTexto = hoje.getFullYear() + '-' +
      String(hoje.getMonth() + 1).padStart(2, '0') + '-' +
      String(hoje.getDate()).padStart(2, '0');

    inicio.min = hojeTexto;
    fim.min = hojeTexto;

    inicio.addEventListener('change', function () {
      fim.min = inicio.value || hojeTexto;
      if (fim.value && fim.value < inicio.value) {
        fim.value = inicio.value;
      }
    });

    form.addEventListener('submit', function (e) {
      if (inicio.value && fim.value && fim.value < inicio.value) {
        e.preventDefault();
        alert('A data final não pode ser anterior à data inicial.');
      }
    });
  });

  // ------------------------------------------------------------
  // 4. Filtro de busca em listas e tabelas
  //    Útil para o líder achar voluntários rapidamente.
  //    HTML: <input type="search" data-filtro="#lista-voluntarios" placeholder="Buscar...">
  //          <ul id="lista-voluntarios"> <li>Maria</li> <li>João</li> </ul>
  //    Funciona com <li> ou <tr> dentro do alvo.
  // ------------------------------------------------------------
  document.querySelectorAll('[data-filtro]').forEach(function (campo) {
    var alvo = document.querySelector(campo.dataset.filtro);
    if (!alvo) return;

    campo.addEventListener('input', function () {
      var termo = campo.value.trim().toLowerCase();
      alvo.querySelectorAll('li, tbody tr').forEach(function (item) {
        var combina = item.textContent.toLowerCase().indexOf(termo) !== -1;
        item.style.display = combina ? '' : 'none';
      });
    });
  });

  // ------------------------------------------------------------
  // 5. Máscara de telefone brasileiro: (49) 99999-9999
  //    HTML: <input type="tel" name="telefone" data-mascara="telefone">
  //    O PHP deve guardar só os números (remover o que não for dígito).
  // ------------------------------------------------------------
  document.querySelectorAll('[data-mascara="telefone"]').forEach(function (campo) {
    campo.addEventListener('input', function () {
      var n = campo.value.replace(/\D/g, '').slice(0, 11);
      var texto = n;
      if (n.length > 10) {
        texto = '(' + n.slice(0, 2) + ') ' + n.slice(2, 7) + '-' + n.slice(7);
      } else if (n.length > 6) {
        texto = '(' + n.slice(0, 2) + ') ' + n.slice(2, 6) + '-' + n.slice(6);
      } else if (n.length > 2) {
        texto = '(' + n.slice(0, 2) + ') ' + n.slice(2);
      } else if (n.length > 0) {
        texto = '(' + n;
      }
      campo.value = texto;
    });
  });

  // ------------------------------------------------------------
  // 6. Mostrar/ocultar senha (tela de login)
  //    HTML: <input type="password" id="senha" name="senha">
  //          <button type="button" data-mostrar-senha="#senha">Mostrar</button>
  // ------------------------------------------------------------
  document.querySelectorAll('[data-mostrar-senha]').forEach(function (botao) {
    var campo = document.querySelector(botao.dataset.mostrarSenha);
    if (!campo) return;

    botao.addEventListener('click', function () {
      var mostrando = campo.type === 'text';
      campo.type = mostrando ? 'password' : 'text';
      botao.textContent = mostrando ? 'Mostrar' : 'Ocultar';
    });
  });

  // ------------------------------------------------------------
  // 7. Mensagens de aviso que somem sozinhas
  //    HTML: <div class="alert alert-success" data-fecha-auto="5000">Salvo!</div>
  //    (o número é o tempo em milissegundos)
  // ------------------------------------------------------------
  document.querySelectorAll('[data-fecha-auto]').forEach(function (aviso) {
    var tempo = parseInt(aviso.dataset.fechaAuto, 10) || 5000;
    setTimeout(function () {
      aviso.style.display = 'none';
    }, tempo);
  });

});