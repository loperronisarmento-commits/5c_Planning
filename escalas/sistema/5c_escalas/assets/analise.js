// 5C Escalas — Análise (WFM): dica dos gráficos.
// Os gráficos já vêm prontos em SVG do PHP, com <title> em cada marca (dica nativa).
// Aqui o <title> vira uma dica que aparece na hora, segue o mouse e funciona com toque.
(function () {
  var raiz = document.querySelector('.analise');
  if (!raiz) {
    return;
  }
  Array.prototype.forEach.call(raiz.querySelectorAll('svg.graf title'), function (t) {
    t.parentNode.setAttribute('data-dica', t.textContent);
    t.parentNode.removeChild(t);
  });
  Array.prototype.forEach.call(raiz.querySelectorAll('.mapa [title]'), function (el) {
    el.setAttribute('data-dica', el.getAttribute('title'));
    el.removeAttribute('title');
  });

  var dica = document.createElement('div');
  dica.className = 'dica-graf';
  dica.setAttribute('role', 'tooltip');
  dica.hidden = true;
  document.body.appendChild(dica);
  var atual = null;

  function posicionar(x, y) {
    var w = dica.offsetWidth;
    var a = dica.offsetHeight;
    var vw = document.documentElement.clientWidth;
    var esq = x + 14;
    if (esq + w > vw - 8) {
      esq = x - w - 14;
    }
    if (esq < 8) {
      esq = 8;
    }
    var topo = y - a - 12;
    if (topo < 8) {
      topo = y + 18;
    }
    dica.style.left = esq + 'px';
    dica.style.top = topo + 'px';
  }
  function mostrar(el, x, y) {
    if (el !== atual) {
      atual = el;
      dica.textContent = el.getAttribute('data-dica');
    }
    dica.hidden = false;
    posicionar(x, y);
  }
  function esconder() {
    atual = null;
    dica.hidden = true;
  }
  function alvo(ev) {
    var el = ev.target && ev.target.closest ? ev.target.closest('[data-dica]') : null;
    return el && raiz.contains(el) ? el : null;
  }

  raiz.addEventListener('mousemove', function (ev) {
    var el = alvo(ev);
    if (el) {
      mostrar(el, ev.clientX, ev.clientY);
    } else if (atual) {
      esconder();
    }
  });
  raiz.addEventListener('mouseleave', esconder);
  // Toque: um toque mostra, tocar fora esconde.
  document.addEventListener('click', function (ev) {
    var el = alvo(ev);
    if (el) {
      mostrar(el, ev.clientX, ev.clientY);
    } else if (atual) {
      esconder();
    }
  });
  window.addEventListener('scroll', esconder, { passive: true });
})();
