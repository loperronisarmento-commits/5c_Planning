# Limpeza de 01/11/2026 (a partir do commit 5fb92ed)

**Removido (obsoleto ou gerado):**
- `escalas/sistema/5c_escalas.zip`: artefato de build. Agora é montado por `python deploy/montar_zip.py`, com o mesmo conteúdo (107 arquivos).
- `escalas/rascunho_telas/`: rascunhos das telas, que já viraram páginas do módulo. O canvas continua no artifact.
- `escalas/comunicado/`: comunicado do 5x2, já divulgado.
- `escalas/LOG_revisao_28out.md`: log de uma revisão concluída.
- Planilhas já transformadas em scripts:
  - `Proposta_turnos_formulario.xlsx`, que virou o ES_25;
  - `Modelos_escala_lideranca.xlsx`, base da escala da liderança, que hoje está no banco;
  - `Acao_fim_dos_times_31out.xlsx`.
- `escalas/sql/gerador/`: geradores do ES_05, ES_10 e ES_17. Os scripts gerados ficam.
- Scripts de desfazer de etapas que já estão em produção:
  - `ES_98_desfazer_v4.sql`;
  - `5C_Planning_99_desfazer_10_a_12.sql`;
  - `5C_Planning_00_limpeza_wfm_db_mis_opcional.sql` (limpeza opcional e única).

**Mantido de propósito:**
- Todos os outros scripts SQL. Eles formam a cadeia de instalação: mesmo os só de dados gravam parâmetros e regras que o motor lê.
- `ES_99_desfazer.sql`, que é a desinstalação do schema.

**Código PHP:**
- não há função sem uso;
- não há código comentado parado.

Foram conferidas as 284 funções do módulo.

**Adicionado:**
- `deploy/montar_zip.py`;
- `contexto_otimizado.md`;
- `aprendizados.md`.
