// 5C Escalas — copiar CSV para colar no Schedule Ajustment.
document.addEventListener('click', function (ev) {
  var botao = ev.target.closest('[data-copiar]');
  if (!botao) {
    return;
  }
  var alvo = document.getElementById(botao.getAttribute('data-copiar'));
  if (!alvo) {
    return;
  }
  var texto = alvo.textContent;
  var avisar = function () {
    var original = botao.textContent;
    botao.textContent = 'Copiado';
    setTimeout(function () { botao.textContent = original; }, 1500);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(texto).then(avisar);
  } else {
    // http sem TLS: usa a seleção antiga.
    var area = document.createElement('textarea');
    area.value = texto;
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); avisar(); } catch (e) { /* sem cópia */ }
    document.body.removeChild(area);
  }
});

// Grade: abrir e fechar a janela de ajuste sem a página pular (guarda a rolagem,
// inclusive a horizontal da tabela) e Esc fecha a janela.
(function () {
  var chave = 'esc-rolagem:' + location.pathname + ':' + (new URLSearchParams(location.search).get('p') || '');
  function guardar() {
    var t = document.querySelector('.tabela-rolagem');
    try { sessionStorage.setItem(chave, JSON.stringify({ y: window.scrollY, x: t ? t.scrollLeft : 0, ty: t ? t.scrollTop : 0 })); } catch (e) { /* sem storage */ }
  }
  try {
    var r = JSON.parse(sessionStorage.getItem(chave) || 'null');
    if (r) {
      sessionStorage.removeItem(chave);
      var t = document.querySelector('.tabela-rolagem');
      if (t) { t.scrollLeft = r.x; t.scrollTop = r.ty || 0; }
      window.scrollTo(0, r.y);
    }
  } catch (e) { /* sem storage */ }
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('a[data-manter-rolagem]')) { guardar(); }
  });
  document.addEventListener('submit', function (ev) {
    if (ev.target.matches('form[data-manter-rolagem]')) { guardar(); }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      if (ev.target.matches && ev.target.matches('input[type="search"]') && ev.target.value) {
        return; // primeiro Esc limpa a busca; o segundo fecha a janela
      }
      var fechar = document.querySelector('[data-fechar-modal]');
      if (fechar) { guardar(); fechar.click(); }
    }
  });
})();

// Listas grandes (12 opções ou mais): uma caixa de busca em cima filtra as opções.
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('select:not([multiple])').forEach(function (sel) {
    if (sel.options.length < 12 || sel.dataset.semBusca !== undefined) {
      return;
    }
    var busca = document.createElement('input');
    busca.type = 'search';
    busca.placeholder = 'buscar…';
    busca.className = 'busca-select';
    busca.setAttribute('aria-label', 'Buscar na lista');
    sel.parentNode.insertBefore(busca, sel);
    var tirarAcento = function (t) { return t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); };
    busca.addEventListener('input', function () {
      var q = tirarAcento(busca.value.trim());
      var primeira = null;
      Array.prototype.forEach.call(sel.options, function (op) {
        var ok = !q || tirarAcento(op.text).indexOf(q) !== -1;
        op.hidden = !ok;
        if (ok && !primeira && op.value !== '') { primeira = op; }
      });
      if (q && primeira) { sel.value = primeira.value; }
    });
    busca.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
  });
});
