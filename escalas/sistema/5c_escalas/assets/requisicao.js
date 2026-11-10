// 5C Escalas — Requisição de vagas: o motor do Simulações 5C (sim.py de 26/10) no navegador.
// Os dados vêm do PHP (#req-dados): bases do Capacity, quadro de hoje, premissas e turmas.
// As quantidades editadas só mudam a simulação (e os formulários de requisição) até o Salvar da
// linha ou o "Gravar turmas planejadas", que gravam no plano (fc_contratacoes) pelo POST da tela.
(function () {
  'use strict';
  var el = document.getElementById('req-dados');
  if (!el) {
    return;
  }
  var D = JSON.parse(el.textContent);
  var TX = D.txt;
  var T = ['A', 'C', 'D'];
  var $ = function (id) { return document.getElementById(id); };

  // ---------- datas: número de dias (UTC) ----------
  function dia(iso) { var p = iso.split('-'); return Math.round(Date.UTC(+p[0], +p[1] - 1, +p[2]) / 86400000); }
  function iso(n) { return new Date(n * 86400000).toISOString().slice(0, 10); }
  function fmt(n, ano) {
    var d = new Date(n * 86400000);
    var dd = String(d.getUTCDate()).padStart(2, '0'), mm = String(d.getUTCMonth() + 1).padStart(2, '0');
    var s = D.en ? mm + '/' + dd : dd + '/' + mm;
    return ano ? s + '/' + String(d.getUTCFullYear()).slice(2) : s;
  }
  function num1(v) {
    var s = (Math.round(v * 10) / 10).toFixed(1);
    return (D.en ? s : s.replace('.', ',')).replace('-', '−');
  }
  function tt(s, v) { return s.replace(/\{(\w+)\}/g, function (m, k) { return v[k] !== undefined ? v[k] : m; }); }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var HOJE = dia(D.hoje);

  // ---------- estado ----------
  var estado = { base: 0, alvo: 'precisa', partida: 'hoje', turmas: [] };
  function copiaTurmas() {
    return D.turmas.map(function (x) {
      // travada: os números são das pessoas reais (requisição, contratados, treinamento) e não se editam aqui.
      // fixa: o "Sugerir tamanhos" não mexe (travada ou marcada como fixa no plano).
      return { ini: dia(x.ini), opera: x.opera ? dia(x.opera) : null, etapa: x.etapa, travada: x.etapa !== 'PLANEJADA',
        fixa: x.fixa === 1 || x.etapa !== 'PLANEJADA', q: { A: x.q.A, C: x.q.C, D: x.q.D }, editada: false };
    });
  }
  estado.turmas = copiaTurmas();

  function premissas() {
    var v = function (id, pad) { var x = parseFloat($(id).value); return isFinite(x) && x >= 0 ? x : pad; };
    var P = D.prem;
    return { tx: { A: v('p-txA', P.txA) / 100, C: v('p-txC', P.txC) / 100, D: v('p-txD', P.txD) / 100 },
      n30: v('p-n30', P.n30), n90: v('p-n90', P.n90), rend: Math.min(100, v('p-rend', P.rend)), rampa: Math.round(v('p-rampa', P.rampa)),
      trein: Math.round(v('p-trein', P.trein)), recr: Math.round(v('p-recr', P.recr)), teto: Math.max(1, Math.round(v('p-teto', P.tetoTurno))),
      aprov: Math.min(1, Math.max(0.5, v('p-aprov', 0.94))), fechada: v('p-fechada', P.fechada) };
  }

  function base(prem) {
    var b = D.bases[estado.base];
    var alvo = estado.alvo === 'titulares' ? b.titulares : b.precisa;
    var h0 = estado.partida === 'capacity' ? b.tem : D.quadroHoje;
    // O quadro de hoje parte de hoje; o "tem" do Capacity parte do dia do cálculo.
    var ini = estado.partida === 'capacity' ? dia(b.data) : HOJE;
    return { ini: ini, h0: { A: h0.A || 0, C: h0.C || 0, D: h0.D || 0 }, alvo: { A: alvo.A || 0, C: alvo.C || 0, D: alvo.D || 0 } };
  }
  function opera(c, prem) { return c.opera !== null ? c.opera : c.ini + prem.trein; }

  // Falta (alvo − quem rende) por dia, de B.ini até "ate". Saída diária do quadro = taxa semanal do turno ÷ 7;
  // o novato sai 12% em 30 dias e mais 11% até 90, só a parte acima da taxa do turno (sem contar duas vezes).
  function serie(B, prem, turmas, ate) {
    var h = { A: B.h0.A, C: B.h0.C, D: B.h0.D }, out = [];
    var rend = prem.rend / 100;
    function reg(d) {
      var f = { dia: d };
      T.forEach(function (t) {
        var ef = h[t];
        if (rend < 1 && prem.rampa > 0) {
          turmas.forEach(function (c) { var id = d - opera(c, prem); if (id >= 0 && id < prem.rampa) { ef -= c.q[t] * (1 - rend); } });
        }
        f[t] = B.alvo[t] - ef;
      });
      f.tot = f.A + f.C + f.D;
      out.push(f);
    }
    for (var d = B.ini; d < ate; d++) {
      reg(d);
      var tx = {};
      T.forEach(function (t) { var s = h[t] * prem.tx[t] / 7; tx[t] = h[t] > 0 ? s / h[t] : 0; h[t] -= s; });
      turmas.forEach(function (c) {
        var idade = d - opera(c, prem);
        if (idade === 0) { T.forEach(function (t) { h[t] += c.q[t]; }); }
        if (idade >= 0 && idade < 30) { T.forEach(function (t) { h[t] -= c.q[t] * Math.max(prem.n30 / 100 / 30 - tx[t], 0); }); }
        else if (idade >= 30 && idade < 90) { T.forEach(function (t) { h[t] -= c.q[t] * Math.max(prem.n90 / 100 / 60 - tx[t], 0); }); }
      });
    }
    reg(ate);
    return out;
  }
  function roundPar(v) { var f = Math.floor(v), r = v - f; if (Math.abs(r - 0.5) < 1e-9) { return f % 2 === 0 ? f : f + 1; } return Math.round(v); }

  // plan() do sim.py: dimensiona cada turma planejada, em ordem, com as anteriores já contadas.
  function sugerir(B, prem) {
    var res = estado.turmas;
    for (var i = 0; i < res.length; i++) {
      if (res[i].fixa) { continue; }
      var prox = (i + 1 < res.length ? res[i + 1].ini : res[i].ini + 14) + prem.trein;
      var s = serie(B, prem, res.slice(0, i), Math.max(prox, B.ini));
      var f = s[s.length - 1];
      T.forEach(function (t) { res[i].q[t] = Math.min(prem.teto, Math.max(0, roundPar(f[t] / prem.aprov))); });
      res[i].editada = true;
    }
  }

  // ---------- gráfico (SVG, as mesmas classes do lib/graficos.php) ----------
  function grafico(s, prem) {
    var W = 900, H = 300, L = 44, R = 70, Tp = 14, Bt = 30;
    var x0 = s[0].dia, x1 = s[s.length - 1].dia;
    var lo = 0, hi = 0;
    s.forEach(function (f) { lo = Math.min(lo, f.A, f.C, f.D, f.tot); hi = Math.max(hi, f.A, f.C, f.D, f.tot); });
    var passo = hi - lo > 120 ? 40 : (hi - lo > 60 ? 20 : 10);
    lo = Math.floor(lo / passo) * passo; hi = Math.ceil(hi / passo) * passo; if (hi === lo) { hi = lo + passo; }
    var X = function (d) { return L + (d - x0) / Math.max(1, x1 - x0) * (W - L - R); };
    var Y = function (v) { return Tp + (hi - v) / (hi - lo) * (H - Tp - Bt); };
    var g = '';
    for (var v = lo; v <= hi; v += passo) {
      g += '<line class="grade' + (v === 0 ? ' zero' : '') + '" x1="' + L + '" x2="' + (W - R) + '" y1="' + Y(v) + '" y2="' + Y(v) + '"/>'
        + '<text class="eixo" x="' + (L - 6) + '" y="' + Y(v) + '" dy="4" text-anchor="end">' + v + '</text>';
    }
    var d0 = new Date(x0 * 86400000), m = d0.getUTCMonth(), a = d0.getUTCFullYear();
    for (;;) {
      m++; if (m > 11) { m = 0; a++; }
      var dm = Math.round(Date.UTC(a, m, 1) / 86400000);
      if (dm > x1) { break; }
      g += '<text class="eixo" x="' + X(dm) + '" y="' + (H - Bt + 16) + '" text-anchor="middle">' + TX.meses[m] + (m === 0 ? '/' + String(a).slice(2) : '') + '</text>';
    }
    estado.turmas.forEach(function (c) {
      var d = opera(c, prem);
      if (d > x0 && d <= x1 && c.q.A + c.q.C + c.q.D > 0) {
        g += '<line class="marco" x1="' + X(d) + '" x2="' + X(d) + '" y1="' + Tp + '" y2="' + (H - Bt) + '"/>';
      }
    });
    function linha(k, cls) {
      return '<polyline class="serie ' + cls + '" points="' + s.map(function (f) { return X(f.dia).toFixed(1) + ',' + Y(f[k]).toFixed(1); }).join(' ') + '"/>';
    }
    g += linha('A', 'cinza-2') + linha('C', 'cinza-2') + linha('D', 'cinza-2') + linha('tot', 'destaque grossa');
    // Rótulos diretos no fim das linhas, afastados.
    var fim = s[s.length - 1];
    var rot = [{ k: 'tot', t: TX.total + ' ' + num1(fim.tot), c: 'destaque' }, { k: 'A', t: 'A ' + num1(fim.A), c: 'cinza-2' }, { k: 'C', t: 'C ' + num1(fim.C), c: 'cinza-2' }, { k: 'D', t: 'D ' + num1(fim.D), c: 'cinza-2' }]
      .map(function (r) { r.y = Y(fim[r.k]); return r; }).sort(function (p, q) { return p.y - q.y; });
    var antes = -1e9;
    rot.forEach(function (r) { var y = Math.max(r.y, antes + 14); antes = y; g += '<text class="rot-serie ' + r.c + '" x="' + (X(fim.dia) + 6) + '" y="' + y + '" dy="4">' + esc(r.t) + '</text>'; });
    g += '<circle class="ponta destaque" cx="' + X(fim.dia) + '" cy="' + Y(fim.tot) + '" r="4"/>';
    // Dica por semana.
    var larg = (X(x0 + 7) - X(x0));
    for (var k = 0; k < s.length; k += 7) {
      var f = s[k];
      g += '<g class="marca" data-dica="' + esc(tt(TX.dica, { dia: fmt(f.dia, true), tot: num1(f.tot), a: num1(f.A), c: num1(f.C), d: num1(f.D) })) + '">'
        + '<rect class="hit" x="' + (X(f.dia) - larg / 2) + '" y="' + Tp + '" width="' + larg + '" height="' + (H - Tp - Bt) + '"/>'
        + '<line class="mira" x1="' + X(f.dia) + '" x2="' + X(f.dia) + '" y1="' + Tp + '" y2="' + (H - Bt) + '"/></g>';
    }
    $('req-svg').innerHTML = '<svg class="graf graf-linhas" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc($('req-graf-titulo').textContent) + '">' + g + '</svg>';
  }

  // ---------- gravar o plano (POST da própria tela) ----------
  function formPost(acao, campos) {
    var f = document.createElement('form');
    f.method = 'post'; f.action = D.acao;
    var add = function (n, v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); return i; };
    add('csrf', D.csrf); add('acao', acao);
    Object.keys(campos).forEach(function (k) { add(k, campos[k]); });
    return { form: f, add: add };
  }
  function botao(txt, cls) { var b = document.createElement('button'); b.type = 'submit'; b.className = 'botao' + (cls ? ' ' + cls : ''); b.textContent = txt; return b; }

  // Célula Ações: a data da turma (editável) com Salvar; Apagar; na turma com requisição, o link
  // para mudar os números no Acompanhamento. Turma em treinamento não muda aqui.
  function acoesTurma(x) {
    var td = document.createElement('td');
    td.className = 'req-acoes small';
    if (x.etapa === 'TREINANDO') {
      td.innerHTML = '<span class="muted">' + esc(TX.emTreino) + '</span>';
      return td;
    }
    var de = iso(x.ini);
    var s = formPost('turma_salvar', { turma: de });
    var data = document.createElement('input');
    data.type = 'date'; data.name = 'nova'; data.value = de; data.required = true;
    data.setAttribute('aria-label', tt(TX.dataTurma, { dia: fmt(x.ini, true) }));
    s.form.appendChild(data);
    if (!x.travada) {
      // Planejada: grava também os números da linha (os da simulação).
      s.add('com_q', '1');
      var hq = {};
      T.forEach(function (t) { hq[t] = s.add('q[' + t + ']', x.q[t]); });
      s.form.addEventListener('submit', function () { T.forEach(function (t) { hq[t].value = x.q[t]; }); });
    }
    s.form.appendChild(botao(TX.salvar, x.editada && !x.travada ? 'primario' : ''));
    td.appendChild(s.form);
    var a = formPost('turma_apagar', { turma: de });
    var b = botao(TX.apagar, 'perigo');
    b.setAttribute('data-confirmar', tt(TX.confApagar, { dia: fmt(x.ini, true) }));
    a.form.appendChild(b);
    td.appendChild(a.form);
    if (x.travada) {
      var l = document.createElement('a');
      l.href = '#req-' + de; l.textContent = TX.editarReq;
      td.appendChild(document.createElement('br'));
      td.appendChild(l);
    }
    return td;
  }

  // ---------- tela ----------
  function desenharTurmas(prem) {
    var tb = $('req-turmas');
    tb.textContent = '';
    estado.turmas.forEach(function (x, i) {
      var tr = document.createElement('tr');
      var tot = x.q.A + x.q.C + x.q.D;
      var lim = x.ini - prem.recr;
      var prazo = '';
      if (x.etapa === 'PLANEJADA' && tot > 0) {
        var dd = lim - HOJE;
        prazo = '<br><span class="small ' + (dd < 0 ? 'laranja' : 'muted') + '">' + esc(dd < 0 ? tt(TX.vencida, { n: -dd }) : (dd === 0 ? TX.hoje : tt(TX.vence, { n: dd }))) + '</span>';
      }
      tr.innerHTML = '<td><strong>' + fmt(x.ini, true) + '</strong></td><td><span class="tag' + (x.travada ? '' : ' amarelo') + '">' + esc(D.etapas[x.etapa] || x.etapa) + '</span>'
        + (x.editada && !x.travada && D.grava ? '<br><span class="small laranja">' + esc(TX.editada) + '</span>' : '') + '</td>'
        + '<td>' + (x.etapa === 'PLANEJADA' ? fmt(lim, true) + prazo : '—') + '</td><td>' + fmt(opera(x, prem), true) + '</td>';
      T.forEach(function (t) {
        var td = document.createElement('td');
        td.className = 'num';
        if (x.travada) {
          td.textContent = x.q[t];
        } else {
          var inp = document.createElement('input');
          inp.type = 'number'; inp.min = '0'; inp.max = '99'; inp.step = '1'; inp.value = x.q[t]; inp.className = 'req-q';
          inp.setAttribute('aria-label', fmt(x.ini, true) + ' · ' + t);
          if (x.q[t] > prem.teto) { inp.classList.add('acima'); inp.title = tt(TX.acimaTeto, { n: prem.teto }); }
          inp.addEventListener('change', function () { var v = parseInt(inp.value, 10); x.q[t] = v >= 0 ? v : 0; x.editada = true; $('req-msg').textContent = ''; atualizar(); });
          td.appendChild(inp);
        }
        tr.appendChild(td);
      });
      var tt2 = document.createElement('td'); tt2.className = 'num'; tt2.innerHTML = '<strong>' + tot + '</strong>'; tr.appendChild(tt2);
      if (D.grava) { tr.appendChild(acoesTurma(x)); }
      tb.appendChild(tr);
      // O formulário de requisição da turma acompanha os números da simulação.
      T.forEach(function (t) {
        var f = document.querySelector('[data-req-q="' + iso(x.ini) + '|' + t + '"]');
        if (f && x.editada && !x.travada) { f.value = x.q[t]; }
      });
    });
  }

  function atualizar() {
    var prem = premissas();
    var B = base(prem);
    desenharTurmas(prem);
    var ult = estado.turmas.length ? estado.turmas[estado.turmas.length - 1] : null;
    // Até uma semana depois da última turma operar (depois disso só há saídas, sem contratação).
    var fimD = Math.max(B.ini + 120, ult ? opera(ult, prem) + 7 : 0);
    var s = serie(B, prem, estado.turmas, fimD);
    var pior = s[0], fecha = null;
    s.forEach(function (f) { if (f.tot > pior.tot) { pior = f; } });
    // Fecha = primeiro dia depois do pior ponto em que a falta fica ≤ limite até o fim.
    for (var k = s.length - 1; k >= 0 && s[k].tot <= prem.fechada; k--) { fecha = s[k]; }
    var fim = s[s.length - 1];
    $('req-graf-titulo').textContent = fecha ? tt(TX.titFecha, { n: prem.fechada, dia: fmt(fecha.dia, true) }) : tt(TX.titNaoFecha, { dia: fmt(fim.dia, true), n: num1(fim.tot) });
    $('req-graf-nota').textContent = tt(TX.notaGraf, { rend: prem.rend, pior: num1(pior.tot), dia: fmt(pior.dia, true) });
    grafico(s, prem);
    // Falta no fim de cada mês.
    var tb = $('req-proj'); tb.textContent = '';
    s.forEach(function (f, i) {
      var amanha = new Date((f.dia + 1) * 86400000);
      if (amanha.getUTCDate() === 1 || i === s.length - 1) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td>' + fmt(f.dia, true) + '</td>' + T.map(function (t) { return '<td class="num ' + (f[t] > 0.05 ? 'laranja' : '') + '">' + num1(f[t]) + '</td>'; }).join('')
          + '<td class="num"><strong>' + num1(f.tot) + '</strong></td>';
        tb.appendChild(tr);
      }
    });
    // KPIs.
    var total = 0, nT = 0;
    estado.turmas.forEach(function (x) { var q = x.q.A + x.q.C + x.q.D; total += q; nT += q > 0 ? 1 : 0; });
    var abrir = estado.turmas.filter(function (x) { return x.etapa === 'PLANEJADA' && x.q.A + x.q.C + x.q.D > 0; });
    var venc = abrir.filter(function (x) { return x.ini - prem.recr < HOJE; }).length;
    var prox = abrir.filter(function (x) { return x.ini - prem.recr >= HOJE; })[0];
    function kpi(rot, val, det, cls) { return '<div class="kpi"><span class="muted">' + esc(rot) + '</span><strong class="' + (cls || '') + '">' + esc(val) + '</strong><span class="muted small">' + esc(det) + '</span></div>'; }
    $('req-kpis').innerHTML = kpi(TX.kContr, String(total), tt(TX.kContrDet, { n: nT }))
      + kpi(TX.kPior, num1(pior.tot), tt(TX.kPiorDet, { dia: fmt(pior.dia, true) }), pior.tot > prem.fechada ? 'laranja' : 'verde')
      + (fecha ? kpi(TX.kFecha, fmt(fecha.dia, true), tt(TX.kFechaDet, { n: prem.fechada }), 'verde') : kpi(TX.kFecha, TX.kNaoFecha, tt(TX.kNaoFechaDet, { dia: fmt(fim.dia, true) }), 'laranja'))
      + kpi(TX.kVenc, String(abrir.length), abrir.length ? tt(TX.kVencDet, { n: venc, dia: prox ? fmt(prox.ini - prem.recr, true) : '—' }) : TX.kVencNada, venc ? 'laranja' : '');
    // Requisições a copiar.
    var lista = $('req-lista'); lista.textContent = '';
    var linhas = [TX.cabCopia], soma = { A: 0, C: 0, D: 0 };
    abrir.forEach(function (x) {
      var cel = [fmt(x.ini, true), fmt(x.ini - prem.recr, true), fmt(opera(x, prem), true), x.q.A, x.q.C, x.q.D, x.q.A + x.q.C + x.q.D];
      T.forEach(function (t) { soma[t] += x.q[t]; });
      linhas.push(cel.join('\t'));
      var tr = document.createElement('tr');
      tr.innerHTML = cel.map(function (c, j) { return '<td' + (j > 2 ? ' class="num"' : '') + '>' + (j === 6 ? '<strong>' + c + '</strong>' : esc(c)) + '</td>'; }).join('');
      lista.appendChild(tr);
    });
    if (!abrir.length) {
      lista.innerHTML = '<tr><td colspan="7" class="muted">' + esc(TX.semReq) + '</td></tr>';
    } else {
      var tr2 = document.createElement('tr');
      tr2.innerHTML = '<td><strong>' + esc(TX.total) + '</strong></td><td></td><td></td>' + T.map(function (t) { return '<td class="num">' + soma[t] + '</td>'; }).join('') + '<td class="num"><strong>' + (soma.A + soma.C + soma.D) + '</strong></td>';
      lista.appendChild(tr2);
      linhas.push([TX.total, '', '', soma.A, soma.C, soma.D, soma.A + soma.C + soma.D].join('\t'));
    }
    $('req-texto').textContent = linhas.join('\n');
  }

  $('req-base').addEventListener('change', function () { estado.base = +this.value; atualizar(); });
  $('req-alvo').addEventListener('change', function () { estado.alvo = this.value; atualizar(); });
  $('req-partida').addEventListener('change', function () { estado.partida = this.value; atualizar(); });
  $('req-sugerir').addEventListener('click', function () { var prem = premissas(); sugerir(base(prem), prem); $('req-msg').textContent = TX.sugerido; atualizar(); });
  $('req-oficial').addEventListener('click', function () { estado.turmas = copiaTurmas(); $('req-msg').textContent = TX.oficial; atualizar(); });
  Array.prototype.forEach.call(document.querySelectorAll('.req-prem input'), function (inp) { inp.addEventListener('change', atualizar); });
  // Gravar de uma vez as turmas planejadas com os números da tabela (ex.: depois de "Sugerir tamanhos").
  if ($('req-gravar-todas')) {
    $('req-gravar-todas').addEventListener('click', function () {
      var plan = estado.turmas.filter(function (x) { return !x.travada; });
      if (!plan.length) { $('req-msg').textContent = TX.semPlanejadas; return; }
      $('req-todas-json').value = JSON.stringify(plan.map(function (x) { return { ini: iso(x.ini), q: x.q }; }));
      var f = $('req-form-todas');
      f.setAttribute('data-confirmar', tt(TX.confTodas, { n: plan.length }));
      if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
    });
  }
  $('req-copiar').addEventListener('click', function () {
    var txt = $('req-texto').textContent;
    var mostrar = function () {
      var pre = $('req-texto'); pre.hidden = false;
      var r = document.createRange(); r.selectNodeContents(pre);
      var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
      $('req-msg').textContent = TX.selecione;
    };
    try { navigator.clipboard.writeText(txt).then(function () { $('req-msg').textContent = TX.copiado; }, mostrar); } catch (e) { mostrar(); }
  });
  atualizar();
})();
