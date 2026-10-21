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
