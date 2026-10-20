# 5C Planning

Repositório de trabalho do planejamento da 5C Company (capacity, contratação, absenteísmo e forecast de WFM).

| Pasta | Conteúdo |
|---|---|
| `contexto/` | Contexto consolidado do projeto (negócio, banco, regras, pendências) |
| `af_analise/` | Análise das Falhas Administrativas (AF): roteiro, SQL de diagnóstico (somente leitura), executor `AF_run_diagnostico.py` (grava CSVs em `resultados/`, fora do git), parecer e script de atualização do peso |

Regras: scripts SQL de diagnóstico são somente leitura; qualquer alteração no banco vai como script `.sql` separado, para o usuário rodar no Navicat. Nenhuma credencial neste repositório.
