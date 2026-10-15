// src/assets/app.js
// JavaScript simples: confirmações e janelas (modal). Todo o resto é PHP.

// 1) Pedir confirmação antes de uma ação.
//    No HTML: <button data-confirma="Excluir este bloqueio?">Excluir</button>
document.querySelectorAll('[data-confirma]').forEach(function (el) {
  el.addEventListener('click', function (e) {
    if (!confirm(el.dataset.confirma)) e.preventDefault();
  });
});

// 2) Janela "Novo usuário / Editar usuário" (equipe.php)
var janelaUsuario = document.getElementById('modalUsuario');
if (janelaUsuario) {
  janelaUsuario.addEventListener('show.bs.modal', function (evento) {
    var botao = evento.relatedTarget;
    if (!botao) return;               // aberta pelo PHP (erro de validação): mantém o que já está preenchido

    var editando = !!botao.dataset.id;
    var campo = function (id) { return janelaUsuario.querySelector(id); };

    janelaUsuario.querySelector('[name="acao"]').value = editando ? 'editar' : 'criar';
    janelaUsuario.querySelector('[name="id"]').value   = editando ? botao.dataset.id : '0';
    campo('#tituloModalUsuario').textContent = editando ? 'Editar usuário' : 'Novo usuário';

    campo('#u-nome').value   = editando ? botao.dataset.nome : '';
    campo('#u-email').value  = editando ? botao.dataset.email : '';
    campo('#u-perfil').value = editando ? botao.dataset.perfil : 'VOLUNTARIO';
    campo('#u-ativo').value  = editando ? botao.dataset.ativo : '1';

    var senha = campo('#u-senha');
    senha.value = '';
    senha.required = !editando;
    campo('#u-senha-ajuda').textContent = editando
      ? 'Deixe em branco para manter a senha atual.'
      : 'Mínimo de 8 caracteres.';

    var grupoIgreja = campo('#u-igreja-grupo');   // só existe para o administrador geral
    if (grupoIgreja) {
      grupoIgreja.hidden = editando;
      campo('#u-igreja').value = '';
    }
    // Remove o aviso de erro de uma tentativa anterior
    var aviso = janelaUsuario.querySelector('.modal-body .alert');
    if (aviso) aviso.remove();
  });
}

// 3) Reabrir a janela quando o PHP devolveu erro de validação
var abrirSozinha = document.querySelector('[data-abrir-modal]');
if (abrirSozinha && window.bootstrap) {
  new bootstrap.Modal(abrirSozinha).show();
}
