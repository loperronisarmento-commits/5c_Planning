// Gera instalar_postar_csv.html a partir de postar_csv.js.
// Uso: node build_bookmarklet.js
const fs = require('fs');
const path = require('path');

const dir = __dirname;
const js = fs.readFileSync(path.join(dir, 'postar_csv.js'), 'utf8')
  .replace(/\/\*[\s\S]*?\*\//, '')   // tira o comentário do topo
  .replace(/^\s*\/\/.*$/gm, '');     // tira linhas só de comentário
const href = 'javascript:' + encodeURIComponent(js);

const html = `<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Instalar Postar CSV em lote</title>
<style>body{font:15px/1.5 system-ui,Segoe UI,sans-serif;max-width:640px;margin:40px auto;padding:0 16px;color:#222}
a.bm{display:inline-block;padding:10px 18px;background:#8b0a50;color:#fff;border-radius:6px;text-decoration:none;font-weight:bold}
li{margin:6px 0}</style></head><body>
<h1>Postar CSV em lote</h1>
<p>Arraste o botão abaixo para a barra de favoritos do Chrome (Ctrl+Shift+B mostra a barra):</p>
<p><a class="bm" href="${href.replace(/"/g, '&quot;')}">Postar CSV em lote</a></p>
<ol><li>No 5C Escalas, abra a <b>Grade</b> da versão publicada da semana e clique em <b>Pacote para postar em lote (.json)</b>. O arquivo é sempre o da última versão publicada.</li>
<li>Faça o <b>Delete Range</b> nas páginas que já têm escala na semana (favorito <b>Delete Range em lote</b>).</li>
<li>No WebPortal, abra <b>Shifts and Schedule &gt; Schedule Tools</b> e clique no favorito <b>Postar CSV em lote</b>: abre um painel no canto da tela.</li>
<li>Escolha o arquivo .json. Já vêm marcadas as páginas "a fazer"; as "sem mudança" desde a versão já postada ficam desmarcadas. Confira o Date Schedule e clique em <b>Postar CSV</b>.</li>
<li>Para cada página ele seleciona o Model, preenche o Date Schedule, cola o CSV, clica em <b>Save Schedule CSV</b> e espera o Success. Se o site disser que está ocupado, espera 8 s e tenta de novo (até 5 vezes); outro erro para naquela página.</li>
<li>No fim, marque "Postei" na Grade.</li></ol>
<p>O painel lembra, por semana e versão, as páginas que ele já postou: se parar no meio, é só carregar o mesmo arquivo e continuar.</p></body></html>`;

fs.writeFileSync(path.join(dir, 'instalar_postar_csv.html'), html);
console.log('instalar_postar_csv.html gerado (' + href.length + ' caracteres no favorito)');
