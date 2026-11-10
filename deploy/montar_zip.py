# =====================================================================
# Monta escalas/sistema/5c_escalas.zip, que o deploy/deploy.ps1 envia ao servidor.
# Leva o módulo como está no último commit (HEAD): alteração sem commit na pasta de
# trabalho não vai para o servidor, e nada de logs/ nem arquivos locais.
# Pasta 5c_escalas/ na raiz e barras "/" (o deploy_remoto.ps1 confere essa pasta).
#   python deploy/montar_zip.py
# =====================================================================
import pathlib
import subprocess
import zipfile

raiz = pathlib.Path(__file__).resolve().parent.parent
base = raiz / 'escalas' / 'sistema'
destino = base / '5c_escalas.zip'

subprocess.run(['git', 'archive', '--format=zip', '-o', str(destino), 'HEAD:escalas/sistema', '5c_escalas'],
               cwd=raiz, check=True)

with zipfile.ZipFile(destino) as z:
    total = sum(not nome.endswith('/') for nome in z.namelist())
print(f'{destino}: {total} arquivos')

pendentes = subprocess.run(['git', 'status', '--porcelain', '--', 'escalas/sistema/5c_escalas'], cwd=raiz,
                           capture_output=True, text=True, check=True).stdout.splitlines()
if pendentes:
    print(f'Atenção: {len(pendentes)} arquivo(s) do módulo sem commit ficaram de fora do zip.')
