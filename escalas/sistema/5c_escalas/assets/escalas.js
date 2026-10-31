// Idioma: window.ESC_I18N (index.php) traz as traduções dos textos deste arquivo; o texto em
// português é a chave. T('Ordenar por {coluna}', {coluna: 'Dia'}).
window.T = function (pt, vars) {
  var txt = (window.ESC_I18N && window.ESC_I18N[pt]) || pt;
  if (vars) { Object.keys(vars).forEach(function (k) { txt = txt.split('{' + k + '}').join(String(vars[k])); }); }
  return txt;
};
var EM_INGLES = (document.documentElement.lang || '').indexOf('en') === 0;

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
    botao.textContent = T('Copiado');
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

// Listas grandes (12 opções ou mais): uma caixa de busca filtra as opções. A caixa entra depois
// do select no HTML (o rótulo continua sendo do select) e aparece em cima pelo CSS. Digitar só
// filtra; Enter escolhe a primeira opção que sobrou.
document.addEventListener('DOMContentLoaded', function () {
  var n = 0;
  document.querySelectorAll('select:not([multiple])').forEach(function (sel) {
    if (sel.options.length < 12 || sel.dataset.semBusca !== undefined) {
      return;
    }
    if (!sel.id) { sel.id = 'lista-busca-' + (++n); }
    var lab = sel.closest('label'), rotulo = '';
    if (lab) { Array.prototype.forEach.call(lab.childNodes, function (nd) { if (nd.nodeType === 3) { rotulo += nd.textContent; } }); }
    rotulo = rotulo.replace(/\s+/g, ' ').trim() || sel.getAttribute('aria-label') || T('lista');
    var busca = document.createElement('input');
    busca.type = 'search';
    busca.placeholder = T('buscar…');
    busca.className = 'busca-select';
    busca.setAttribute('aria-label', T('Filtrar {lista}', { lista: rotulo }));
    busca.setAttribute('aria-controls', sel.id);
    var aviso = document.createElement('span');
    aviso.className = 'so-leitor';
    aviso.setAttribute('aria-live', 'polite');
    sel.insertAdjacentElement('afterend', busca);
    busca.insertAdjacentElement('afterend', aviso);
    sel.classList.add('com-busca');
    var tirarAcento = function (t) { return t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
    var primeira = null;
    busca.addEventListener('input', function () {
      var q = tirarAcento(busca.value.trim());
      var visiveis = 0;
      primeira = null;
      Array.prototype.forEach.call(sel.options, function (op) {
        var ok = !q || op.value === '' || tirarAcento(op.text).indexOf(q) !== -1;
        op.hidden = !ok;
        if (ok && op.value !== '') { visiveis++; if (!primeira) { primeira = op; } }
      });
      aviso.textContent = q ? T('{n} opção(ões). Enter escolhe a primeira.', { n: visiveis }) : '';
    });
    busca.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        if (primeira) {
          sel.value = primeira.value;
          aviso.textContent = T('Escolhido: {opcao}', { opcao: primeira.text });
          sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
    });
  });
});

// Tabelas: clicar no título de qualquer coluna ordena as linhas (de novo inverte).
// Números no formato brasileiro (1.234,5 · 97% · US$ 10), datas dd/mm e texto; vazio e "—" ficam
// sempre no fim. Fica de fora a tabela com linhas agrupadas (célula com rowspan ou colspan no
// corpo) ou marcada com data-sem-ordem.
document.addEventListener('DOMContentLoaded', function () {
  var vazio = /^(|—|-|–|n\/a)$/i;
  // A região que anuncia a ordem já existe vazia (leitor de tela só anuncia mudança).
  var avisoOrdem = document.createElement('div');
  avisoOrdem.className = 'so-leitor';
  avisoOrdem.setAttribute('aria-live', 'polite');
  document.body.appendChild(avisoOrdem);
  function anunciar(texto) { avisoOrdem.textContent = texto; }
  function chave(cel) {
    if (!cel) { return null; }
    var t = (cel.dataset.valor !== undefined ? cel.dataset.valor : cel.textContent).replace(/\s+/g, ' ').trim();
    if (vazio.test(t)) { return null; }
    var d = t.match(/^(?:[a-zà-ú]{3}\s)?(\d{2})\/(\d{2})(?:\/(\d{4}))?(?:\s+(\d{2}):(\d{2}))?/i);
    if (d) {
      // pt-BR dd/mm · en mm/dd
      var dia = EM_INGLES ? d[2] : d[1], mes = EM_INGLES ? d[1] : d[2];
      return { n: (+(d[3] || 0)) * 1e8 + (+mes) * 1e6 + (+dia) * 1e4 + (+(d[4] || 0)) * 100 + (+(d[5] || 0)) };
    }
    var s = t.replace(/^(US\$|R\$|\$)\s*/i, '');
    var m = EM_INGLES ? s.match(/^[-+]?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?/) : s.match(/^[-+]?(\d{1,3}(\.\d{3})+|\d+)(,\d+)?/);
    if (m) {
      return { n: parseFloat(EM_INGLES ? m[0].replace(/,/g, '') : m[0].replace(/\./g, '').replace(',', '.')) };
    }
    return { s: t };
  }
  function comparar(a, b) {
    if (a.n !== undefined && b.n !== undefined) { return a.n - b.n; }
    if (a.n !== undefined) { return -1; }
    if (b.n !== undefined) { return 1; }
    return a.s.localeCompare(b.s, EM_INGLES ? 'en' : 'pt-BR', { numeric: true, sensitivity: 'base' });
  }
  function celulaDaColuna(linha, col) {
    var i = 0;
    for (var k = 0; k < linha.cells.length; k++) {
      var c = linha.cells[k];
      if (i === col) { return c; }
      i += c.colSpan || 1;
      if (i > col) { return null; }
    }
    return null;
  }
  document.querySelectorAll('table').forEach(function (tabela) {
    if (tabela.dataset.semOrdem !== undefined || !tabela.tHead || !tabela.tBodies.length) { return; }
    var corpos = Array.prototype.slice.call(tabela.tBodies);
    var agrupada = corpos.some(function (tb) {
      return Array.prototype.some.call(tb.querySelectorAll('td, th'), function (c) { return c.rowSpan > 1 || c.colSpan > 1; });
    });
    if (agrupada) { return; }
    var cab = tabela.tHead.rows[tabela.tHead.rows.length - 1];
    var col = 0;
    Array.prototype.forEach.call(cab.cells, function (th) {
      var minha = col;
      col += th.colSpan || 1;
      if (th.colSpan > 1 || th.dataset.semOrdem !== undefined || !th.textContent.trim()) { return; }
      th.classList.add('ordenavel');
      // Botão dentro do título: dá para ordenar pelo teclado (Tab + Enter/Espaço).
      var botao = document.createElement('button');
      botao.type = 'button';
      botao.className = 'ordenar';
      while (th.firstChild) { botao.appendChild(th.firstChild); }
      th.appendChild(botao);
      var rotulo = botao.textContent.replace(/\s+/g, ' ').trim();
      botao.title = T('Ordenar por {coluna}', { coluna: rotulo });
      botao.addEventListener('click', function () {
        var asc = th.getAttribute('aria-sort') !== 'ascending';
        Array.prototype.forEach.call(cab.cells, function (o) { o.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        anunciar(T(asc ? 'Ordenado por {coluna}, crescente' : 'Ordenado por {coluna}, decrescente', { coluna: rotulo }));
        corpos.forEach(function (tb) {
          var linhas = Array.prototype.map.call(tb.rows, function (r, i) { return { r: r, i: i, k: chave(celulaDaColuna(r, minha)) }; });
          linhas.sort(function (a, b) {
            if (a.k === null || b.k === null) { return a.k === b.k ? a.i - b.i : (a.k === null ? 1 : -1); }
            var c = comparar(a.k, b.k);
            return (asc ? c : -c) || a.i - b.i;
          });
          linhas.forEach(function (x) { tb.appendChild(x.r); });
        });
      });
    });
  });
});

// Ação longa: formulário (ou botão) com data-longo="Recalculando… pode levar até 1 min".
// Ao enviar, o botão fica ocupado com o texto, a barra do topo corre e um segundo envio
// é bloqueado. Confirmação: data-confirmar="texto" no botão ou no formulário.
// Navegação: links internos também acendem a barra (telas pesadas como Análise).
(function () {
  var barra = null;
  function acenderBarra() {
    if (!barra) {
      barra = document.createElement('div');
      barra.className = 'barra-progresso';
      barra.setAttribute('aria-hidden', 'true');
      document.body.appendChild(barra);
    }
    barra.classList.add('ativa');
  }
  function apagarBarra() { if (barra) { barra.classList.remove('ativa'); } }

  // Confirmação no diálogo próprio: o envio para, o diálogo abre e, se confirmar, o mesmo
  // botão envia de novo (marcado como já confirmado).
  document.addEventListener('submit', function (ev) {
    if (ev.defaultPrevented) { return; }
    var form = ev.target;
    var botao = ev.submitter || null;
    var texto = (botao && botao.getAttribute('data-confirmar')) || form.getAttribute('data-confirmar');
    if (!texto) { return; } // sem data-confirmar: a tela cuida (ex.: lote com escConfirmar)
    if (form.dataset.confirmado !== '1') {
      ev.preventDefault();
      ev.stopImmediatePropagation();
      var perigo = !!(botao && botao.classList.contains('perigo'));
      window.escConfirmar(texto, { botao: botao ? botao.textContent.replace(/[…\s]+$/, '').trim() : T('Confirmar'), perigo: perigo, origem: botao }).then(function (ok) {
        if (!ok) { return; }
        form.dataset.confirmado = '1';
        if (form.requestSubmit) { form.requestSubmit(botao || undefined); } else { form.submit(); }
      });
      return;
    }
    delete form.dataset.confirmado;
  }, true);

  document.addEventListener('submit', function (ev) {
    if (ev.defaultPrevented) { return; }
    var form = ev.target;
    var botao = ev.submitter || null;
    var longo = (botao && botao.getAttribute('data-longo')) || form.getAttribute('data-longo');
    if (form.dataset.enviando === '1') {
      ev.preventDefault(); // segundo clique: já está enviando
      return;
    }
    if ((form.method || '').toLowerCase() !== 'post' && longo === null) {
      return; // filtro (GET): não trava
    }
    form.dataset.enviando = '1';
    acenderBarra();
    if (longo !== null) {
      form.setAttribute('aria-busy', 'true');
      var alvos = botao ? [botao] : Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], button:not([type])'));
      alvos.forEach(function (b) {
        b.dataset.textoOriginal = b.textContent;
        if (longo) { b.textContent = longo; }
        b.classList.add('ocupado');
        b.setAttribute('aria-disabled', 'true');
      });
      if (longo) {
        var aviso = document.createElement('div');
        aviso.className = 'aviso-ocupado';
        aviso.setAttribute('role', 'status');
        aviso.textContent = longo + ' ' + T('Não feche nem recarregue a página.');
        (botao && botao.closest('.acoes, .lote-barra, form') || form).appendChild(aviso);
      }
    }
  });

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('a[href]');
    if (!a || ev.defaultPrevented || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) { return; }
    if (a.target === '_blank' || a.hasAttribute('download') || a.getAttribute('href').charAt(0) === '#') { return; }
    if (a.origin !== location.origin || /[?&]baixar=/.test(a.search)) { return; }
    acenderBarra();
  });

  // Voltar pelo navegador (cache da página): desfaz o estado de "enviando".
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) { return; }
    apagarBarra();
    document.querySelectorAll('form[data-enviando]').forEach(function (f) { delete f.dataset.enviando; f.removeAttribute('aria-busy'); });
    document.querySelectorAll('.ocupado').forEach(function (b) {
      if (b.dataset.textoOriginal) { b.textContent = b.dataset.textoOriginal; }
      b.classList.remove('ocupado');
      b.removeAttribute('aria-disabled');
    });
    document.querySelectorAll('.aviso-ocupado').forEach(function (d) { d.remove(); });
  });
})();

// Formulário com erro: o servidor devolve o que foi digitado (flash) e a tela reabre o mesmo
// formulário preenchido, com o erro em cima dele. Cada POST leva "_form" (id ou posição na
// tela) para achar o formulário de volta.
(function () {
  function chaveDoForm(form) {
    if (form.id) { return form.id; }
    var posts = Array.prototype.filter.call(document.forms, function (f) { return (f.method || '').toLowerCase() === 'post'; });
    return 'f' + posts.indexOf(form);
  }
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if ((form.method || '').toLowerCase() !== 'post' || ev.defaultPrevented) { return; }
    var campo = form.querySelector('input[name="_form"]');
    if (!campo) {
      campo = document.createElement('input');
      campo.type = 'hidden';
      campo.name = '_form';
      form.appendChild(campo);
    }
    campo.value = chaveDoForm(form);
  }, true);

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-fechar-aviso]');
    if (b) { b.closest('.aviso').remove(); }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var dado = document.getElementById('esc-flash-post');
    if (!dado) { return; }
    var flash;
    try { flash = JSON.parse(dado.textContent); } catch (e) { return; }
    var post = flash.post || {};
    var chave = post._form;
    var form = null;
    if (chave) {
      form = document.getElementById(chave);
      if (!form && /^f\d+$/.test(chave)) {
        var posts = Array.prototype.filter.call(document.forms, function (f) { return (f.method || '').toLowerCase() === 'post'; });
        form = posts[+chave.slice(1)] || null;
      }
    }
    // Confere que é o mesmo formulário: mesma ação e os mesmos campos ocultos do registro
    // (id, evento_id, page_id...). Se a lista mudou, procura entre todos os formulários.
    var bate = function (f) {
      var ok = true;
      Array.prototype.forEach.call(f.querySelectorAll('input[type="hidden"]'), function (c) {
        if (c.name && c.name !== 'csrf' && c.name !== '_form' && post[c.name] !== undefined && typeof post[c.name] !== 'object' && String(post[c.name]) !== c.value) { ok = false; }
      });
      return ok;
    };
    if (form && !bate(form)) {
      form = Array.prototype.filter.call(document.forms, function (f) { return (f.method || '').toLowerCase() === 'post' && bate(f) && f.querySelector('input[type="hidden"]'); })[0] || null;
    }
    if (!form) { return; }
    var avisoTopo = document.querySelector('.avisos .aviso.erro');
    if (avisoTopo) { avisoTopo.removeAttribute('role'); } // o erro é lido uma vez, no formulário
    Object.keys(post).forEach(function (nome) {
      if (nome === '_form' || nome === 'acao') { return; }
      var valor = post[nome];
      var lista = Array.isArray(valor) || (valor && typeof valor === 'object');
      var valores = lista ? Object.keys(valor).map(function (k) { return String(valor[k]); }) : [String(valor)];
      var campos = form.querySelectorAll('[name="' + (lista ? nome + '[]' : nome) + '"]');
      var i = 0;
      Array.prototype.forEach.call(campos, function (c) {
        if (c.type === 'hidden' || c.type === 'submit' || c.type === 'button') { return; }
        if (c.type === 'checkbox' || c.type === 'radio') {
          c.checked = valores.indexOf(c.value) !== -1;
        } else if (c.tagName === 'SELECT' && c.multiple) {
          Array.prototype.forEach.call(c.options, function (o) { o.selected = valores.indexOf(o.value) !== -1; });
        } else {
          c.value = lista ? (valores[i++] || '') : valores[0];
        }
      });
    });
    var det = form.closest('details');
    if (det) { det.open = true; }
    var msg = document.createElement('p');
    msg.className = 'erro-form';
    msg.setAttribute('role', 'alert');
    msg.textContent = flash.msg;
    form.insertBefore(msg, form.firstChild);
    form.scrollIntoView({ block: 'center' });
    var primeiro = form.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea');
    if (primeiro) { primeiro.focus({ preventScroll: true }); }
  });
})();

// Diálogo de confirmação (<dialog> nativo: prende o foco, Esc cancela, o fundo fica inerte).
// escConfirmar(texto, {titulo, botao, perigo, origem}) → Promise<boolean>. Linha em branco no
// texto separa parágrafos.
window.escConfirmar = function (texto, op) {
  op = op || {};
  return new Promise(function (resolver) {
    var d = document.getElementById('esc-dialogo');
    if (!d) {
      d = document.createElement('dialog');
      d.id = 'esc-dialogo';
      d.className = 'dialogo';
      d.setAttribute('aria-labelledby', 'esc-dialogo-titulo');
      d.setAttribute('aria-describedby', 'esc-dialogo-texto');
      d.innerHTML = '<form method="dialog"><h2 id="esc-dialogo-titulo"></h2><div class="dialogo-texto" id="esc-dialogo-texto"></div>'
        + '<div class="acoes dialogo-acoes"><button value="nao" class="botao"></button><button value="sim" class="botao primario"></button></div></form>';
      document.body.appendChild(d);
    }
    if (typeof d.showModal !== 'function') { resolver(window.confirm(texto)); return; }
    var partes = String(texto).split(/\n\s*\n/);
    var titulo = op.titulo || (partes.length > 1 ? partes.shift() : T('Confirmar'));
    d.querySelector('button[value="nao"]').textContent = T('Cancelar');
    d.querySelector('h2').textContent = titulo;
    var corpo = d.querySelector('.dialogo-texto');
    corpo.innerHTML = '';
    partes.forEach(function (t) {
      var pp = document.createElement('p');
      pp.textContent = t;
      corpo.appendChild(pp);
    });
    var sim = d.querySelector('button[value="sim"]');
    sim.textContent = op.botao || T('Confirmar');
    sim.className = 'botao ' + (op.perigo ? 'perigo-cheio' : 'primario');
    d.returnValue = '';
    d.onclose = function () {
      resolver(d.returnValue === 'sim');
      if (op.origem && op.origem.focus) { op.origem.focus(); }
    };
    d.showModal();
    d.querySelector('button[value="nao"]').focus();
  });
};

// Grade: janela de ajuste da célula. Fundo inerte (o Tab fica dentro), foco no 1º campo e, ao
// fechar, o foco volta para a célula que abriu.
(function () {
  var chave = 'esc-celula-foco';
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('td.celula a[href]');
    if (a) { try { sessionStorage.setItem(chave, a.getAttribute('href')); } catch (e) { /* sem storage */ } }
  });
  document.addEventListener('DOMContentLoaded', function () {
    var fundo = document.querySelector('.modal-fundo');
    if (fundo) {
      // Sobe da janela até o body deixando inerte tudo o que está ao lado em cada nível.
      var no = fundo;
      while (no && no.parentNode && no !== document.body) {
        Array.prototype.forEach.call(no.parentNode.children, function (el) {
          if (el !== no && el.tagName !== 'SCRIPT' && el.tagName !== 'DATALIST') { el.setAttribute('inert', ''); }
        });
        no = no.parentNode;
      }
      var campo = fundo.querySelector('form input:not([type="hidden"]), form select, form textarea') || fundo.querySelector('button, a[href]');
      if (campo && !document.getElementById('esc-flash-post')) { campo.focus(); }
      return;
    }
    var href = null;
    try { href = sessionStorage.getItem(chave); sessionStorage.removeItem(chave); } catch (e) { /* sem storage */ }
    if (href) {
      var cel = document.querySelector('td.celula a[href="' + href.replace(/"/g, '\\"') + '"]');
      if (cel) { cel.focus({ preventScroll: true }); }
    }
  });
})();

// Tema: Claro / Escuro / Automático (segue o sistema). Troca na hora, sem recarregar; fica
// salvo no navegador e num cookie (o servidor já manda o tema certo na próxima tela).
(function () {
  function aplicar(tema) {
    document.documentElement.setAttribute('data-tema', tema);
    document.querySelectorAll('[data-tema-escolha]').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-tema-escolha') === tema ? 'true' : 'false');
    });
  }
  try {
    var salvo = localStorage.getItem('esc-tema');
    if (salvo && salvo !== document.documentElement.getAttribute('data-tema')) { aplicar(salvo); }
  } catch (e) { /* sem storage */ }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-tema-escolha]');
    if (!b) { return; }
    var tema = b.getAttribute('data-tema-escolha');
    aplicar(tema);
    try { localStorage.setItem('esc-tema', tema); } catch (e) { /* sem storage */ }
    document.cookie = 'esc_tema=' + tema + '; path=/; max-age=31536000; SameSite=Lax';
  });
})();

// Celular: o menu abre e fecha pelo botão "Menu" (Esc fecha e devolve o foco ao botão).
(function () {
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('.menu-botao');
    if (!b) { return; }
    var menu = b.closest('.menu');
    var aberto = !menu.classList.contains('aberto');
    menu.classList.toggle('aberto', aberto);
    b.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    if (aberto) {
      var atual = menu.querySelector('.grupo a.ativo') || menu.querySelector('.grupo a');
      if (atual) { atual.focus(); }
    }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') { return; }
    var menu = document.querySelector('.menu.aberto');
    if (!menu) { return; }
    menu.classList.remove('aberto');
    var b = menu.querySelector('.menu-botao');
    b.setAttribute('aria-expanded', 'false');
    b.focus();
  });
})();

// Celular: tabela simples vira cartões (cada célula com o nome da coluna). Fica de fora a grade
// da escala, tabela com linhas agrupadas e a marcada com data-sem-cartao.
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('table').forEach(function (t) {
    if (t.classList.contains('grade') || t.dataset.semCartao !== undefined || !t.tHead || !t.tBodies.length) { return; }
    var agrupada = Array.prototype.some.call(t.querySelectorAll('tbody td, tbody th'), function (c) { return c.rowSpan > 1; });
    if (agrupada) { return; }
    var cab = t.tHead.rows[t.tHead.rows.length - 1];
    var rotulos = [];
    Array.prototype.forEach.call(cab.cells, function (th) {
      var txt = th.textContent.replace(/\s+/g, ' ').trim();
      for (var k = 0; k < (th.colSpan || 1); k++) { rotulos.push(txt); }
    });
    Array.prototype.forEach.call(t.tBodies, function (tb) {
      Array.prototype.forEach.call(tb.rows, function (r) {
        var col = 0;
        Array.prototype.forEach.call(r.cells, function (c) {
          if (c.colSpan > 1) { c.classList.add('cel-larga'); } else if (rotulos[col]) {
            c.setAttribute('data-rotulo', rotulos[col]);
            // Conteúdo num bloco só, ao lado do rótulo (no desktop não muda nada).
            var v = document.createElement('div');
            v.className = 'cel-valor';
            while (c.firstChild) { v.appendChild(c.firstChild); }
            c.appendChild(v);
          }
          col += c.colSpan || 1;
        });
      });
    });
    t.classList.add('vira-cartao');
  });
});

// Idioma: PT / EN no menu. Grava o cookie e recarrega a tela no mesmo lugar (a rolagem volta).
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-idioma-escolha]');
  if (!b) { return; }
  var idioma = b.getAttribute('data-idioma-escolha');
  document.cookie = 'esc_idioma=' + idioma + '; path=/; max-age=31536000; SameSite=Lax';
  try { localStorage.setItem('esc-idioma', idioma); sessionStorage.setItem('esc-rolagem-idioma', String(window.scrollY)); } catch (e) { /* sem storage */ }
  location.reload();
});
(function () {
  try {
    var y = sessionStorage.getItem('esc-rolagem-idioma');
    if (y !== null) { sessionStorage.removeItem('esc-rolagem-idioma'); window.scrollTo(0, +y); }
  } catch (e) { /* sem storage */ }
})();

// Tour guiado (Ajuda › Tutorial): window.ESC_TOUR (index.php) traz os passos no idioma da tela.
// O progresso fica neste navegador (localStorage esc-tour) e o cookie esc_tour avisa o servidor
// para mandar os passos nas outras telas enquanto o tour estiver ligado.
(function () {
  function ler() { try { return JSON.parse(localStorage.getItem('esc-tour') || 'null') || { vistos: [] }; } catch (e) { return { vistos: [] }; } }
  function gravar(e) { try { localStorage.setItem('esc-tour', JSON.stringify(e)); } catch (x) { /* sem storage */ } }
  function cookie(ligado) { document.cookie = 'esc_tour=' + (ligado ? '1' : '') + '; path=/; max-age=' + (ligado ? 2592000 : 0) + '; SameSite=Lax'; }
  function ir(i) {
    var t = window.ESC_TOUR;
    if (!t || !t.passos[i]) { return; }
    var e = ler();
    e.vistos = e.vistos || [];
    e.ativo = true; e.passo = i;
    gravar(e); cookie(true);
    location.href = t.passos[i].url;
  }
  function sair(concluiu) {
    var e = ler();
    e.ativo = false;
    if (concluiu) { e.passo = 0; }
    gravar(e); cookie(false);
    var c = document.getElementById('tour-cartao');
    if (c) { c.remove(); }
    document.querySelectorAll('.tour-alvo').forEach(function (el) { el.classList.remove('tour-alvo'); });
  }
  // "Começar do início" fora do treino é um formulário que liga o Modo Treino: marca o tour
  // como em andamento antes de enviar, para o cartão aparecer no primeiro passo.
  document.addEventListener('submit', function (ev) {
    if (!ev.target.matches('[data-tour-preparar]')) { return; }
    var e = ler();
    e.vistos = []; e.ativo = true; e.passo = 0;
    gravar(e); cookie(true);
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-tour-iniciar]');
    if (b) { ir(+b.getAttribute('data-tour-iniciar')); return; }
    if (ev.target.closest('[data-tour-retomar]')) { ir(ler().passo || 0); }
  });
  document.addEventListener('DOMContentLoaded', function () {
    var t = window.ESC_TOUR, e = ler();
    if (!t || !e.ativo) { return; }
    e.vistos = e.vistos || [];
    var i = e.passo || 0, passo = t.passos[i];
    if (!passo) { sair(true); return; }
    var esc = function (s) { var d = document.createElement('span'); d.textContent = s; return d.innerHTML; };
    var lista = function (titulo, itens) {
      if (!itens || !itens.length) { return ''; }
      return '<div><strong>' + esc(titulo) + '</strong><ul>' + itens.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
    };
    var c = document.createElement('section');
    c.id = 'tour-cartao';
    c.className = 'tour-cartao';
    c.setAttribute('role', 'region');
    c.setAttribute('aria-label', T('Tour guiado'));
    if (t.tela !== passo.tela) {
      c.innerHTML = '<p class="small"></p><div class="acoes"><button type="button" class="botao primario" data-tour-voltar></button><button type="button" class="botao" data-tour-sair></button></div>';
      c.querySelector('p').textContent = T('Tour em andamento: passo {n} de {total}', { n: i + 1, total: t.passos.length });
      c.querySelector('[data-tour-voltar]').textContent = T('Continuar o tour');
      c.querySelector('[data-tour-sair]').textContent = T('Sair do tour');
    } else {
      if (e.vistos.indexOf(i) === -1) { e.vistos.push(i); gravar(e); }
      c.innerHTML = '<p class="eyebrow"></p><h2 tabindex="-1"></h2><p class="small tour-texto"></p><div class="colunas-acoes small">'
        + lista(T('Na plataforma'), passo.plataforma) + lista(T('No site 5C'), passo.site) + '</div>'
        + '<div class="acoes"><button type="button" class="botao" data-tour-anterior></button><button type="button" class="botao primario" data-tour-proximo></button><button type="button" class="botao" data-tour-sair></button></div>';
      c.querySelector('.eyebrow').textContent = T('Passo {n} de {total}', { n: i + 1, total: t.passos.length });
      // Fora do Modo Treino o tour mostra as telas reais: avisa que clicar nelas muda o sistema oficial.
      if (!t.treino) {
        var av = document.createElement('p');
        av.className = 'tour-aviso small';
        av.textContent = T('Você está no sistema real: o tour só mostra as telas, mas o que você clicar nelas muda a escala oficial. Para praticar sem risco, ligue o Modo Treino. ');
        var lk = document.createElement('a');
        lk.href = t.treino_url;
        lk.textContent = T('Ligar o Modo Treino');
        av.appendChild(lk);
        c.insertBefore(av, c.querySelector('.tour-texto'));
      }
      c.querySelector('h2').textContent = passo.titulo;
      c.querySelector('.tour-texto').textContent = passo.texto;
      var ant = c.querySelector('[data-tour-anterior]');
      ant.textContent = T('Anterior');
      ant.disabled = i === 0;
      c.querySelector('[data-tour-proximo]').textContent = i === t.passos.length - 1 ? T('Concluir') : T('Próximo');
      c.querySelector('[data-tour-sair]').textContent = T('Sair do tour');
      var alvo = passo.alvo ? document.querySelector(passo.alvo) : null;
      if (alvo) { alvo.classList.add('tour-alvo'); alvo.scrollIntoView({ block: 'center' }); }
    }
    document.body.appendChild(c);
    var foco = c.querySelector('h2') || c.querySelector('button');
    if (foco) { foco.focus({ preventScroll: true }); }
    c.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-tour-sair]')) { sair(false); return; }
      if (ev.target.closest('[data-tour-voltar]')) { ir(i); return; }
      if (ev.target.closest('[data-tour-anterior]')) { ir(i - 1); return; }
      if (ev.target.closest('[data-tour-proximo]')) {
        if (i === t.passos.length - 1) { sair(true); location.href = t.fim; } else { ir(i + 1); }
      }
    });
  });
})();
