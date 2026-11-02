/* Delete Range em lote - 5C WebPortal > Shifts and Schedule > Schedule Tools
 * Abre um painel na página, pede o range e as páginas (Models) e, para cada uma:
 * seleciona o Model, preenche Date Schedule e Date End, clica em Delete Range,
 * confirma (Delete All Reasons no 5C Schedule, OK nos outros) e espera o "Success".
 * Se vier "Error", espera e tenta de novo.
 */
(function () {
  if (window.__drPanel) { window.__drPanel.style.display = 'block'; return; }

  const CFG = {
    waitResultMs: 180000,   // tempo máximo esperando Success/Error
    retryWaitMs: 8000,      // espera antes de tentar de novo após um Error
    maxRetries: 5,
    pauseBetweenMs: 1500,   // pausa entre uma página e a próxima
  };
  const LS_KEY = 'drBatchLast';

  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const norm = s => (s || '').replace(/\s+/g, ' ').trim().toLowerCase();
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  const els = () => ({
    model: document.getElementById('select-model-schedule'),
    dateIni: document.getElementById('schedule-set-date'),
    dateEnd: document.getElementById('schedule-set-date-end'),
    btnDelete: document.getElementById('deleteRange'),
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
    (popupButton(p, 'OK') || p.querySelector('.swal-button--confirm') || p.querySelector('.swal-button')).click();
    await waitFor(() => !popup(), 5000);
  }

  // ---------- uma página ----------
  async function deleteOne(model, ini, end, log) {
    for (let attempt = 1; attempt <= CFG.maxRetries; attempt++) {
      await closePopup();
      const e = els();
      if (!e.model || !e.dateIni || !e.dateEnd || !e.btnDelete)
        throw new Error('Não achei os campos. Abra a tela Schedule Tools.');

      setValue(e.model, model.id);
      if (e.model.value !== model.id) throw new Error('Model não existe mais na lista');
      setValue(e.dateIni, ini);
      setValue(e.dateEnd, end);
      await sleep(200);
      e.btnDelete.click();

      // 5C Schedule (id 3): "Delete All Reasons"; os outros: "Are you sure?" -> OK
      const ask = await waitFor(() => {
        const p = popup(); if (!p) return null;
        return (popupButton(p, 'Delete All Reasons') || popupButton(p, 'OK')) ? p : null;
      }, 10000);
      if (!ask) throw new Error('A janela de confirmação não abriu');
      if (!popupButton(ask, 'Delete All Reasons') && !popupTitle(ask).startsWith('are you sure'))
        throw new Error(`${popupTitle(ask)}: ${popupText(ask)}`); // ex.: "Invalid Range"
      (popupButton(ask, 'Delete All Reasons') || popupButton(ask, 'OK')).click();

      await waitFor(() => !popup(), 5000);
      const res = await waitFor(() => {
        const p = popup(); if (!p) return null;
        const t = popupTitle(p);
        if (t === 'success') return { ok: true, msg: popupText(p) };
        if (t === 'error') return { ok: false, msg: popupText(p) };
        return null;
      }, CFG.waitResultMs, 400);

      if (!res) throw new Error('Sem resposta (nem Success nem Error) dentro do tempo limite');
      await closePopup();
      if (res.ok) return res.msg;

      log(`  Erro (tentativa ${attempt}/${CFG.maxRetries}): ${res.msg}`);
      if (attempt < CFG.maxRetries) { log(`  Esperando ${CFG.retryWaitMs / 1000}s para tentar de novo...`); await sleep(CFG.retryWaitMs); }
    }
    throw new Error('Falhou depois de ' + CFG.maxRetries + ' tentativas');
  }

  // ---------- painel ----------
  const e0 = els();
  if (!e0.model) { alert('Abra a tela Shifts and Schedule > Schedule Tools antes de clicar no favorito.'); return; }
  const opts = [...e0.model.options].filter(o => o.value !== '').map(o => ({ id: o.value, name: o.text.trim() }));
  let last = {};
  try { last = JSON.parse(localStorage.getItem(LS_KEY) || '{}'); } catch (_) {}
  const lastIds = last.ids || [];

  const panel = document.createElement('div');
  window.__drPanel = panel;
  panel.style.cssText = 'position:fixed;top:16px;right:16px;width:380px;max-height:92vh;display:flex;flex-direction:column;z-index:2147483000;' +
    'background:#fff;border:2px solid #8b0a50;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.25);' +
    'font:13px/1.4 system-ui,Segoe UI,sans-serif;color:#222;padding:12px';
  panel.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <b style="color:#8b0a50;font-size:15px">Delete Range em lote</b>
      <button data-x style="border:0;background:none;font-size:18px;cursor:pointer">×</button>
    </div>
    <div style="display:flex;gap:8px">
      <label style="flex:1">De (Date Schedule)<br><input data-ini placeholder="dd/mm/aaaa" style="width:100%"></label>
      <label style="flex:1">Até (Date End)<br><input data-end placeholder="dd/mm/aaaa" style="width:100%"></label>
    </div>
    <div style="margin-top:8px;display:flex;gap:6px;align-items:center">
      <input data-filter placeholder="Filtrar páginas..." style="flex:1">
      <a href="#" data-all>todas</a> · <a href="#" data-none>nenhuma</a>
    </div>
    <div data-count style="margin-top:4px;color:#666"></div>
    <div data-list style="margin-top:4px;border:1px solid #ddd;border-radius:4px;padding:4px 6px;height:200px;overflow:auto">
      ${opts.map(o => `<label style="display:block"><input type="checkbox" value="${esc(o.id)}" ${lastIds.includes(o.id) ? 'checked' : ''}> ${esc(o.name)}</label>`).join('')}
    </div>
    <button data-go style="margin-top:10px;padding:8px;background:#dc3545;color:#fff;border:0;border-radius:4px;font-weight:bold;cursor:pointer">Apagar range</button>
    <button data-stop style="display:none;margin-top:6px;padding:6px;cursor:pointer">Parar depois da página atual</button>
    <pre data-log style="margin-top:10px;background:#f5f5f5;padding:8px;white-space:pre-wrap;min-height:40px;max-height:240px;overflow:auto;font-size:12px"></pre>`;
  document.body.appendChild(panel);
  const $ = s => panel.querySelector(s);
  const boxes = () => [...panel.querySelectorAll('[data-list] input')];
  const logEl = $('[data-log]');
  const log = m => { logEl.textContent += m + '\n'; logEl.scrollTop = logEl.scrollHeight; console.log('[DeleteRange]', m); };
  const updCount = () => { $('[data-count]').textContent = `${boxes().filter(c => c.checked).length} de ${opts.length} páginas marcadas`; };

  $('[data-ini]').value = last.ini || '';
  $('[data-end]').value = last.end || '';
  updCount();
  $('[data-list]').addEventListener('change', updCount);
  $('[data-filter]').oninput = ev => {
    const q = norm(ev.target.value);
    boxes().forEach(c => { c.parentElement.style.display = norm(c.parentElement.textContent).includes(q) ? 'block' : 'none'; });
  };
  // "todas"/"nenhuma" valem só para as páginas visíveis no filtro
  const markVisible = v => ev => { ev.preventDefault(); boxes().forEach(c => { if (c.parentElement.style.display !== 'none') c.checked = v; }); updCount(); };
  $('[data-all]').onclick = markVisible(true);
  $('[data-none]').onclick = markVisible(false);

  $('[data-x]').onclick = () => { panel.style.display = 'none'; };
  let stop = false;
  $('[data-stop]').onclick = () => { stop = true; log('Vai parar depois da página atual.'); };

  const dateOk = s => /^\d{2}\/\d{2}\/\d{4}$/.test(s);
  const toDate = s => { const [d, m, y] = s.split('/').map(Number); return new Date(y, m - 1, d); };

  $('[data-go]').onclick = async () => {
    const ini = $('[data-ini]').value.trim(), end = $('[data-end]').value.trim();
    const ids = boxes().filter(c => c.checked).map(c => c.value);
    const models = opts.filter(o => ids.includes(o.id));
    if (!dateOk(ini) || !dateOk(end)) return alert('Datas no formato dd/mm/aaaa.');
    if (toDate(end) < toDate(ini)) return alert('A data final é antes da inicial.');
    if (!models.length) return alert('Marque pelo menos uma página.');
    const lista = models.length > 25 ? models.slice(0, 25).map(m => m.name).join('\n') + `\n... e mais ${models.length - 25}` : models.map(m => m.name).join('\n');
    if (!confirm(`Apagar TODAS as escalas não reportadas de ${ini} até ${end} em ${models.length} página(s)?\n\n${lista}`)) return;

    try { localStorage.setItem(LS_KEY, JSON.stringify({ ini, end, ids })); } catch (_) {}
    stop = false;
    $('[data-go]').disabled = true; $('[data-stop]').style.display = 'block';
    logEl.textContent = '';
    const ok = [], fail = [];
    for (const [i, m] of models.entries()) {
      if (stop) { log('Parado.'); break; }
      log(`▶ (${i + 1}/${models.length}) ${m.name}`);
      try {
        const msg = await deleteOne(m, ini, end, log);
        log(`  ✔ ${msg}`); ok.push(m.name);
      } catch (err) {
        log(`  ✖ ${err.message}`); fail.push(m.name);
        await closePopup();
      }
      await sleep(CFG.pauseBetweenMs);
    }
    log(`\nFim: ${ok.length} ok, ${fail.length} com falha.` + (fail.length ? `\nFalharam: ${fail.join(', ')}` : ''));
    $('[data-go]').disabled = false; $('[data-stop]').style.display = 'none';
  };
})();
