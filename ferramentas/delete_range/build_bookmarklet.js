// Gera instalar_delete_range.html a partir de delete_range.js.
// Uso: node build_bookmarklet.js
const fs = require('fs');
const path = require('path');

const dir = __dirname;
const js = fs.readFileSync(path.join(dir, 'delete_range.js'), 'utf8')
  .replace(/\/\*[\s\S]*?\*\//, '')   // tira o comentário do topo
  .replace(/^\s*\/\/.*$/gm, '');     // tira linhas só de comentário
const href = 'javascript:' + encodeURIComponent(js);

const html = `<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Instalar Delete Range em lote</title>
<style>body{font:15px/1.5 system-ui,Segoe UI,sans-serif;max-width:640px;margin:40px auto;padding:0 16px;color:#222}
a.bm{display:inline-block;padding:10px 18px;background:#8b0a50;color:#fff;border-radius:6px;text-decoration:none;font-weight:bold}
li{margin:6px 0}</style></head><body>
<h1>Delete Range em lote</h1>
<p>Arraste o botão abaixo para a barra de favoritos do Chrome (Ctrl+Shift+B mostra a barra):</p>
<p><a class="bm" href="${href.replace(/"/g, '&quot;')}">Delete Range em lote</a></p>
<ol><li>No WebPortal, abra <b>Shifts and Schedule &gt; Schedule Tools</b>.</li>
<li>Clique no favorito <b>Delete Range em lote</b>: abre um painel no canto da tela.</li>
<li>Preencha <b>De</b> e <b>Até</b> (dd/mm/aaaa), marque as páginas (dá para filtrar) e clique em <b>Apagar range</b>.</li>
<li>Confirme. Para cada página ele seleciona o Model, preenche as datas, clica em Delete Range, confirma (<b>Delete All Reasons</b> no 5C Schedule, <b>OK</b> nos outros) e espera o Success. Se vier Error, espera 8 s e tenta de novo (até 5 vezes).</li></ol>
<p>O painel lembra as últimas datas e páginas marcadas.</p></body></html>`;

fs.writeFileSync(path.join(dir, 'instalar_delete_range.html'), html);
console.log('instalar_delete_range.html gerado (' + href.length + ' caracteres no favorito)');
