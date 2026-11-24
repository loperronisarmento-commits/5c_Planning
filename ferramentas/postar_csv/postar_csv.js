/* Postar CSV em lote - 5C WebPortal > Shifts and Schedule > Schedule Tools
 * Lê o pacote da última versão publicada da semana (Grade do 5C Escalas > "Pacote para postar
 * em lote (.json)") e, para cada página marcada: seleciona o Model, preenche o Date Schedule,
 * cola o CSV no Code CSV, clica em Save Schedule CSV e espera o "Success".
 * Desde a 1.38.0 (E1) o pacote traz o "delete_range" (do dia do "CSV a partir de" ao fim da semana):
 * com ele, cada página é apagada nesse range logo antes de ser postada, e as páginas que só existem
 * no site ("so_apagar") são apagadas no fim. O CSV do dia do corte já traz os blocos de antes do
 * corte iguais ao site, então nada antes do corte se perde nem duplica.
 */
(function () {
  if (window.__pcPanel) { window.__pcPanel.style.display = 'flex'; return; }

  const CFG = {
    waitResultMs: 180000,   // tempo máximo esperando Success/Error
    retryWaitMs: 8000,      // espera antes de tentar de novo ("Your request is being processed")
    maxRetries: 5,
    afterSuccessMs: 2500,   // depois do Success o site recarrega a tabela; um Error aqui é só aviso
    pauseBetweenMs: 1500,
  };
  const LS_KEY = 'pcBatchFeitas'; // {"<semana> <versão>": [ids já postados por este favorito]}

  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const norm = s => (s || '').replace(/\s+/g, ' ').trim().toLowerCase();
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  const els = () => ({
    model: document.getElementById('select-model-schedule'),
    date: document.getElementById('schedule-set-date'),
    dateEnd: document.getElementById('schedule-set-date-end'),
    btnDelete: document.getElementById('deleteRange'),
    csv: document.getElementById('schedule-csv-text-area'),
    btnSave: [...document.querySelectorAll('button, input[type=button], a')].find(b =>
      /submitScheduleCSV/.test(b.getAttribute('onclick') || '') || norm(b.textContent || b.value) === 'save schedule csv'),
  });

  function setValue(el, value) {
    el.value = value;
    ['input', 'change'].forEach(t => el.dispatchEvent(new Event(t, { bubbles: true })));
    if (window.jQuery) window.jQuery(el).trigger('change');
  }

  // ---------- SweetAlert (swal v2: .swal-overlay--show-modal) ----------
  function popup() {
    const o = document.querySelector('.swal-overlay.swal-overlay--show-modal');
    return o ? o.querySelector('.swal-modal') : null;
  }
  const popupTitle = p => norm(p.querySelector('.swal-title')?.textContent);
  const popupText = p => (p.querySelector('.swal-text')?.textContent || '').trim();
  const popupButton = (p, text) => [...p.querySelectorAll('.swal-button')].find(b => norm(b.textContent) === norm(text));
  async function waitFor(fn, timeout, step = 250) {
    const end = Date.now() + timeout;
    while (Date.now() < end) { const v = fn(); if (v) return v; await sleep(step); }
    return null;
  }
  async function closePopup() {
    const p = popup(); if (!p) return;
    (p.querySelector('.swal-button--confirm') || p.querySelector('.swal-button'))?.click();
    await waitFor(() => !popup(), 5000);
  }
  const resultado = () => {
    const p = popup(); if (!p) return null;
    const t = popupTitle(p);
    if (t === 'success') return { ok: true, msg: popupText(p) };
    if (t === 'error') return { ok: false, msg: popupText(p) };
    return { ok: false, msg: `${popupTitle(p)}: ${popupText(p)}`, outro: true }; // ex.: "Date Unselected"
  };

  // ---------- T1 de segunda = T6 de domingo real ----------
  // O T1 de segunda fecha o turno D que começou no T6 de domingo. Lê no site, na hora de postar, o
  // T6 de domingo como está (pode ter mudado à mão depois da geração) e põe essas pessoas no T1 do
  // CSV, o regular primeiro. Devolve null se a leitura falhar.
  const ddmmaaaa = (iso, n) => {
    const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n);
    return [d.getDate(), d.getMonth() + 1].map(x => String(x).padStart(2, '0')).join('/') + '/' + d.getFullYear();
  };
  async function t6Domingo(id) {
    if (!window.jQuery) return null;
    const txt = await new Promise(res => window.jQuery.ajax({
      type: 'POST', url: '/Scheduler/ScheduleDay2CSV',
      data: { ModelId: id, StartDate: ddmmaaaa(pacote.semana, -7), EndDate: ddmmaaaa(pacote.semana, -1), ExcludeAbsences: false },
      success: r => res(typeof r === 'string' ? r : null), error: () => res(null),
    }));
    if (txt === null) return null;
    const cel = ((txt.split(/\|\||\r?\n/)[5] || '').split(';')[6] || '');
    const codes = cel.split(',').map(c => c.replace(/\$/g, '').trim()).filter(Boolean);
    return [...codes.filter(c => !c.endsWith('#')), ...codes.filter(c => c.endsWith('#'))].join(', ');
  }
  const comT1 = (csv, cel) => { const l = csv.split('\n'); const c = l[0].split(';'); c[0] = cel; l[0] = c.join(';'); return l.join('\n'); };

  // ---------- Delete Range de uma página (o mesmo passo do favorito "Delete Range em lote") ----------
  async function deleteOne(id, ini, end, log) {
    for (let attempt = 1; attempt <= CFG.maxRetries; attempt++) {
      await closePopup();
      const e = els();
      if (!e.model || !e.date || !e.dateEnd || !e.btnDelete) throw new Error('Não achei os campos do Delete Range. Abra a tela Schedule Tools.');
      setValue(e.model, String(id));
      if (e.model.value !== String(id)) throw new Error('Model não existe na lista do site');
      setValue(e.date, ini);
      setValue(e.dateEnd, end);
      await sleep(200);
      e.btnDelete.click();
      // 5C Schedule (id 3): "Delete All Reasons"; os outros: "Are you sure?" -> OK
      const ask = await waitFor(() => { const p = popup(); return p && (popupButton(p, 'Delete All Reasons') || popupButton(p, 'OK')) ? p : null; }, 10000);
      if (!ask) throw new Error('A confirmação do Delete Range não abriu');
      if (!popupButton(ask, 'Delete All Reasons') && !popupTitle(ask).startsWith('are you sure')) throw new Error(`${popupTitle(ask)}: ${popupText(ask)}`);
      (popupButton(ask, 'Delete All Reasons') || popupButton(ask, 'OK')).click();
      await waitFor(() => !popup(), 5000);
      const res = await waitFor(resultado, CFG.waitResultMs, 400);
      if (!res) throw new Error('Delete Range sem resposta dentro do tempo limite');
      await closePopup();
      if (res.ok) { setValue(els().dateEnd, ''); return res.msg; }
      log(`  Delete com erro (tentativa ${attempt}/${CFG.maxRetries}): ${res.msg}`);
      if (attempt < CFG.maxRetries) await sleep(CFG.retryWaitMs);
    }
    throw new Error('Delete Range falhou depois de ' + CFG.maxRetries + ' tentativas');
  }

  // ---------- uma página ----------
  async function postOne(pg, data, log) {
    let csv = pg.csv;
    // O T1 de segunda só entra quando a postagem começa na segunda (sem corte no meio da semana).
    const t6 = data === ddmmaaaa(pacote.semana, 0) ? await t6Domingo(pg.id) : undefined;
    const antes = (csv.split('\n')[0] || '').split(';')[0] || '';
    if (t6 === undefined) { /* corte no meio da semana: o CSV do pacote vale como está */ }
    else if (t6 === null) log('  (não consegui ler o T6 de domingo no site: vai o T1 da Grade)');
    else if (!t6.trim() && antes.trim()) log(`  (o T6 de domingo veio vazio do site: fica o T1 do pacote: ${antes})`);
    else {
      csv = comT1(csv, t6);
      if (antes.replace(/\s/g, '') !== t6.replace(/\s/g, '')) log(`  T1 de segunda = T6 de domingo do site: ${t6 || '(vazio)'} (na Grade: ${antes || '(vazio)'})`);
    }
    for (let attempt = 1; attempt <= CFG.maxRetries; attempt++) {
      await closePopup();
      const e = els();
      if (!e.model || !e.date || !e.csv || !e.btnSave)
        throw new Error('Não achei os campos. Abra a tela Schedule Tools.');

      setValue(e.model, String(pg.id));
      if (e.model.value !== String(pg.id)) throw new Error('Model não existe na lista do site');
      setValue(e.date, data);
      setValue(e.csv, csv);
      await sleep(200);
      e.btnSave.click();

      const res = await waitFor(resultado, CFG.waitResultMs, 400);
      if (!res) throw new Error('Sem resposta (nem Success nem Error) dentro do tempo limite');
      await closePopup();
      if (res.outro) throw new Error(res.msg);
      if (res.ok) {
        const aviso = await waitFor(resultado, CFG.afterSuccessMs, 250);
        if (aviso) { log(`  (aviso ao recarregar a tabela: ${aviso.msg})`); await closePopup(); }
        return res.msg;
      }
      // Só repete quando o site pede para esperar; outro erro pode ter gravado parte da escala.
      if (!/being processed|just take a moment/i.test(res.msg)) throw new Error(res.msg);
      log(`  Ocupado (tentativa ${attempt}/${CFG.maxRetries}): ${res.msg}`);
      if (attempt < CFG.maxRetries) { log(`  Esperando ${CFG.retryWaitMs / 1000}s para tentar de novo...`); await sleep(CFG.retryWaitMs); }
    }
    throw new Error('Falhou depois de ' + CFG.maxRetries + ' tentativas');
  }

  // ---------- painel ----------
  const e0 = els();
  if (!e0.model || !e0.csv) { alert('Abra a tela Shifts and Schedule > Schedule Tools antes de clicar no favorito.'); return; }
  const noSite = new Map([...e0.model.options].filter(o => o.value !== '').map(o => [o.value, o.text.trim()]));
  let pacote = null;

  const panel = document.createElement('div');
  window.__pcPanel = panel;
  panel.style.cssText = 'position:fixed;top:16px;right:16px;width:400px;max-height:92vh;display:flex;flex-direction:column;z-index:2147483000;' +
    'background:#fff;border:2px solid #8b0a50;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.25);' +
    'font:13px/1.4 system-ui,Segoe UI,sans-serif;color:#222;padding:12px;overflow:auto';
  panel.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <b style="color:#8b0a50;font-size:15px">Postar CSV em lote</b>
      <button data-x style="border:0;background:none;font-size:18px;cursor:pointer">×</button>
    </div>
    <label>Pacote da Grade (.json)<br><input type="file" data-file accept=".json,application/json"></label>
    <details style="margin-top:4px"><summary style="cursor:pointer;color:#666">ou colar o conteúdo do .json</summary>
      <textarea data-paste rows="4" style="width:100%;margin-top:4px"></textarea>
      <button data-load style="margin-top:4px;cursor:pointer">Carregar</button>
    </details>
    <div data-info style="margin-top:8px;color:#666"></div>
    <div data-body style="display:none">
      <label style="display:block;margin-top:8px">Date Schedule<br><input data-date placeholder="dd/mm/aaaa" style="width:100%"></label>
      <div style="margin-top:8px;display:flex;gap:6px;align-items:center">
        <input data-filter placeholder="Filtrar páginas..." style="flex:1">
        <a href="#" data-all>todas</a> · <a href="#" data-none>nenhuma</a> · <a href="#" data-todo>a fazer</a>
      </div>
      <div data-count style="margin-top:4px;color:#666"></div>
      <div data-list style="margin-top:4px;border:1px solid #ddd;border-radius:4px;padding:4px 6px;height:220px;overflow:auto"></div>
      <button data-go style="margin-top:10px;width:100%;padding:8px;background:#8b0a50;color:#fff;border:0;border-radius:4px;font-weight:bold;cursor:pointer">Postar CSV</button>
      <button data-stop style="display:none;margin-top:6px;width:100%;padding:6px;cursor:pointer">Parar depois da página atual</button>
    </div>
    <pre data-log style="margin-top:10px;background:#f5f5f5;padding:8px;white-space:pre-wrap;min-height:40px;max-height:240px;overflow:auto;font-size:12px"></pre>`;
  document.body.appendChild(panel);
  const $ = s => panel.querySelector(s);
  const boxes = () => [...panel.querySelectorAll('[data-list] input:not(:disabled)')];
  const logEl = $('[data-log]');
  const log = m => { logEl.textContent += m + '\n'; logEl.scrollTop = logEl.scrollHeight; console.log('[PostarCSV]', m); };
  const updCount = () => { $('[data-count]').textContent = `${boxes().filter(c => c.checked).length} de ${pacote.paginas.length} páginas marcadas`; };
  const chave = () => `${pacote.semana} ${pacote.versao}`;
  const lerFeitas = () => { try { return JSON.parse(localStorage.getItem(LS_KEY) || '{}')[chave()] || []; } catch (_) { return []; } };
  const marcarFeita = id => {
    try {
      const todas = JSON.parse(localStorage.getItem(LS_KEY) || '{}');
      todas[chave()] = [...new Set([...(todas[chave()] || []), String(id)])];
      localStorage.setItem(LS_KEY, JSON.stringify(todas));
    } catch (_) {}
  };
  const ROT = { afazer: ['a fazer', '#b45309'], igual: ['sem mudança', '#15803d'], postado: ['já postada', '#15803d'] };

  function carregar(texto) {
    let p;
    try { p = JSON.parse(texto); } catch (_) { return alert('O arquivo não é um JSON válido.'); }
    if (!p || !Array.isArray(p.paginas) || !p.semana) return alert('Este JSON não é o pacote da Grade ("Pacote para postar em lote").');
    if (p.treino) return alert('Este pacote é do Modo Treino e não pode ser postado no site real.');
    pacote = p;
    const feitas = lerFeitas();
    const dr = p.delete_range && Array.isArray(p.delete_range.paginas) ? p.delete_range : null;
    $('[data-info]').innerHTML = `Semana <b>${esc(p.semana)}</b> · versão <b>${esc(p.versao)}</b>` +
      (p.treino ? ' · <b style="color:#dc3545">MODO TREINO</b>' : '') + ` · ${p.paginas.length} páginas` +
      (dr ? `<br><label style="color:#8b0a50"><input type="checkbox" data-del checked> Delete Range de <b>${esc(dr.de)}</b> a <b>${esc(dr.ate)}</b> antes de postar cada página` +
        (dr.paginas.some(x => x.so_apagar) ? ` (e só apagar ${dr.paginas.filter(x => x.so_apagar).length} página(s) fora do plano)` : '') + '</label>' +
        (p.corte && p.corte.blocos_antes_do_corte && p.corte.blocos_antes_do_corte.length ? `<br><small>Em ${esc(p.corte.dia)}, os blocos ${esc(p.corte.blocos_antes_do_corte.join(', '))} voltam iguais ${p.corte.fonte_antes_do_corte === 'site' ? 'ao site' : 'à versão postada'}.</small>` : '') : '');
    $('[data-date]').value = p.date_schedule || '';
    $('[data-list]').innerHTML = p.paginas.map(pg => {
      const id = String(pg.id), existe = noSite.has(id), feita = feitas.includes(id);
      const [rot, cor] = feita ? ['postada por este favorito', '#15803d'] : (ROT[pg.estado] || [pg.estado, '#666']);
      const marcar = existe && !feita && pg.estado === 'afazer';
      return `<label style="display:block;${existe ? '' : 'color:#aaa'}"><input type="checkbox" value="${esc(id)}" data-estado="${esc(pg.estado)}" ${marcar ? 'checked' : ''} ${existe ? '' : 'disabled'}> ` +
        `${esc(pg.nome)} <small style="color:${existe ? cor : '#dc3545'}">${existe ? esc(rot) : 'não está no site'}</small></label>`;
    }).join('');
    $('[data-body]').style.display = 'block';
    updCount();
  }
  $('[data-file]').onchange = ev => { const f = ev.target.files[0]; if (f) f.text().then(carregar); };
  $('[data-load]').onclick = () => carregar($('[data-paste]').value);
  $('[data-list]').addEventListener('change', updCount);
  $('[data-filter]').oninput = ev => {
    const q = norm(ev.target.value);
    [...panel.querySelectorAll('[data-list] label')].forEach(l => { l.style.display = norm(l.textContent).includes(q) ? 'block' : 'none'; });
  };
  // "todas"/"nenhuma"/"a fazer" valem só para as páginas visíveis no filtro
  const marcar = fn => ev => { ev.preventDefault(); boxes().forEach(c => { if (c.parentElement.style.display !== 'none') c.checked = fn(c); }); updCount(); };
  $('[data-all]').onclick = marcar(() => true);
  $('[data-none]').onclick = marcar(() => false);
  $('[data-todo]').onclick = marcar(c => c.dataset.estado === 'afazer' && !lerFeitas().includes(c.value));

  $('[data-x]').onclick = () => { panel.style.display = 'none'; };
  let stop = false;
  $('[data-stop]').onclick = () => { stop = true; log('Vai parar depois da página atual.'); };

  $('[data-go]').onclick = async () => {
    const data = $('[data-date]').value.trim();
    const ids = boxes().filter(c => c.checked).map(c => c.value);
    const paginas = pacote.paginas.filter(pg => ids.includes(String(pg.id)));
    if (!/^\d{2}\/\d{2}\/\d{4}$/.test(data)) return alert('Date Schedule no formato dd/mm/aaaa.');
    if (!paginas.length) return alert('Marque pelo menos uma página.');
    const lista = paginas.length > 25 ? paginas.slice(0, 25).map(p => p.nome).join('\n') + `\n... e mais ${paginas.length - 25}` : paginas.map(p => p.nome).join('\n');
    const dr = pacote.delete_range && $('[data-del]') && $('[data-del]').checked ? pacote.delete_range : null;
    if (!confirm(`Postar a ${pacote.versao} da semana ${pacote.semana} (Date Schedule ${data}) em ${paginas.length} página(s)?\n` +
      (dr ? `Cada página é apagada de ${dr.de} a ${dr.ate} (Delete Range) logo antes de ser postada.` : `O Delete Range dessas páginas já foi feito?`) + `\n\n${lista}`)) return;

    stop = false;
    $('[data-go]').disabled = true; $('[data-stop]').style.display = 'block';
    logEl.textContent = '';
    const ok = [], fail = [];
    for (const [i, pg] of paginas.entries()) {
      if (stop) { log('Parado.'); break; }
      log(`▶ (${i + 1}/${paginas.length}) ${pg.nome}`);
      try {
        if (dr && dr.paginas.some(x => String(x.id) === String(pg.id))) log(`  Delete Range: ${await deleteOne(pg.id, dr.de, dr.ate, log)}`);
        const msg = await postOne(pg, data, log);
        log(`  ✔ ${msg}`); ok.push(pg.nome); marcarFeita(pg.id);
        const c = panel.querySelector(`[data-list] input[value="${CSS.escape(String(pg.id))}"]`);
        if (c) { c.checked = false; c.parentElement.querySelector('small').textContent = 'postada por este favorito'; }
      } catch (err) {
        log(`  ✖ ${err.message}`); fail.push(pg.nome);
        await closePopup();
      }
      await sleep(CFG.pauseBetweenMs);
    }
    // Páginas que só existem no site (fora deste plano): só apagar, no fim.
    if (dr && !stop) {
      for (const x of dr.paginas.filter(x => x.so_apagar && noSite.has(String(x.id)))) {
        log(`▶ só apagar: ${x.nome}`);
        try { log(`  ✔ ${await deleteOne(x.id, dr.de, dr.ate, log)}`); } catch (err) { log(`  ✖ ${err.message}`); fail.push(x.nome + ' (delete)'); await closePopup(); }
        await sleep(CFG.pauseBetweenMs);
      }
    }
    updCount();
    log(`\nFim: ${ok.length} ok, ${fail.length} com falha.` + (fail.length ? `\nFalharam: ${fail.join(', ')}` : '') +
      (ok.length ? '\nMarque "Postei" na Grade do 5C Escalas.' : ''));
    $('[data-go]').disabled = false; $('[data-stop]').style.display = 'none';
  };
})();
