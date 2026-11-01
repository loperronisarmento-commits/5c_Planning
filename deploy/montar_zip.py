# =====================================================================
# Monta escalas/sistema/5c_escalas.zip, que o deploy/deploy.ps1 envia ao servidor.
# Leva só os arquivos do módulo que estão no git (nada de logs/ nem arquivos locais),
# com a pasta 5c_escalas/ na raiz e barras "/" (o deploy_remoto.ps1 confere essa pasta).
#   python deploy/montar_zip.py
# =====================================================================
import pathlib
import subprocess
import zipfile

raiz = pathlib.Path(__file__).resolve().parent.parent
base = raiz / 'escalas' / 'sistema'
arquivos = subprocess.run(['git', 'ls-files', 'escalas/sistema/5c_escalas'], cwd=raiz,
                          capture_output=True, text=True, check=True).stdout.split()

destino = base / '5c_escalas.zip'
with zipfile.ZipFile(destino, 'w', zipfile.ZIP_DEFLATED) as z:
    for rel in arquivos:
        caminho = raiz / rel
        z.write(caminho, caminho.relative_to(base).as_posix())
print(f'{destino}: {len(arquivos)} arquivos')
