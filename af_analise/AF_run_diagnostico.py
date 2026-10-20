#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
5C | Falhas Administrativas (AF) — executor SOMENTE LEITURA do AF_01.

Lê AF_01_diagnostico_somente_leitura.sql, executa cada consulta no servidor .11
e grava um CSV por consulta em af_analise/resultados/ (pasta fora do git).

Garantias de leitura:
  - a sessão MySQL abre com SET SESSION TRANSACTION READ ONLY;
  - só são aceitos comandos SELECT / WITH / DESCRIBE / DESC / SHOW / EXPLAIN
    e SET de variáveis de sessão (@x, NAMES); qualquer outro é recusado
    antes de chegar ao servidor;
  - a senha vem de DB_5C_PASSWORD e nunca é impressa.

Uso:
  python3 AF_run_diagnostico.py --testar            # só testa a conexão
  python3 AF_run_diagnostico.py                     # roda Q0..Q9
  python3 AF_run_diagnostico.py --so Q0 Q1          # roda só algumas
  python3 AF_run_diagnostico.py --sql outro.sql     # outro arquivo

Variáveis: DB_5C_HOST, DB_5C_PORT, DB_5C_USER, DB_5C_PASSWORD.
Se o TCP direto falhar e HTTPS_PROXY existir, tenta túnel CONNECT pelo proxy.
"""
import argparse
import csv
import os
import re
import socket
import sys
import time
from datetime import date, datetime
from decimal import Decimal
from pathlib import Path
from urllib.parse import urlparse

import pymysql

AQUI = Path(__file__).resolve().parent
SQL_PADRAO = AQUI / "AF_01_diagnostico_somente_leitura.sql"
SAIDA = AQUI / "resultados"

PERMITIDOS = ("SELECT", "WITH", "DESCRIBE", "DESC", "SHOW", "EXPLAIN")
RE_SET_OK = re.compile(r"^SET\s+(NAMES\s|@\w+\s*=)", re.I)


def ler_statements(caminho: Path):
    """Divide o arquivo em statements, guardando o rótulo Qn do bloco."""
    texto = caminho.read_text(encoding="utf-8")
    stmts, rotulo, buf = [], "Q0", []
    seq = {}
    for linha in texto.splitlines():
        m = re.match(r"^\s*--\s*(Q\d+[a-z]?)\.", linha)
        if m:
            rotulo = m.group(1)
        sem_coment = re.sub(r"--.*$", "", linha)
        if not sem_coment.strip():
            continue
        buf.append(sem_coment)
        if sem_coment.rstrip().endswith(";"):
            sql = "\n".join(buf).strip().rstrip(";").strip()
            buf = []
            if not sql:
                continue
            seq[rotulo] = seq.get(rotulo, 0) + 1
            stmts.append((rotulo, seq[rotulo], sql))
    if buf and "".join(buf).strip():
        sql = "\n".join(buf).strip()
        seq[rotulo] = seq.get(rotulo, 0) + 1
        stmts.append((rotulo, seq[rotulo], sql))
    return stmts


def eh_leitura(sql: str) -> bool:
    primeira = sql.lstrip().split(None, 1)[0].upper()
    if primeira in PERMITIDOS:
        return True
    if primeira == "SET" and RE_SET_OK.match(sql.lstrip()):
        return True
    return False


def socket_via_proxy(host: str, porta: int, timeout: float = 30.0):
    """Abre um túnel CONNECT pelo HTTPS_PROXY e devolve o socket já ligado."""
    proxy = os.environ.get("HTTPS_PROXY") or os.environ.get("https_proxy")
    if not proxy:
        raise OSError("HTTPS_PROXY não definido")
    p = urlparse(proxy)
    s = socket.create_connection((p.hostname, p.port), timeout=timeout)
    s.sendall(f"CONNECT {host}:{porta} HTTP/1.1\r\nHost: {host}:{porta}\r\n\r\n".encode())
    resp = b""
    while b"\r\n\r\n" not in resp:
        parte = s.recv(4096)
        if not parte:
            break
        resp += parte
    if b" 200 " not in resp.split(b"\r\n", 1)[0]:
        s.close()
        raise OSError(f"proxy recusou o túnel: {resp.split(b'\r\n', 1)[0]!r}")
    s.settimeout(None)
    return s


def conectar(timeout_conexao: int = 20):
    host = os.environ["DB_5C_HOST"]
    porta = int(os.environ.get("DB_5C_PORT", "3306"))
    user = os.environ["DB_5C_USER"]
    senha = os.environ["DB_5C_PASSWORD"]
    comum = dict(
        host=host, port=porta, user=user, password=senha,
        charset="utf8mb4", autocommit=True,
        connect_timeout=timeout_conexao, read_timeout=3600, write_timeout=120,
        init_command="SET SESSION TRANSACTION READ ONLY",
        cursorclass=pymysql.cursors.Cursor,
    )
    erros = []
    t0 = time.time()
    try:
        conn = pymysql.connect(**comum)
        print(f"[conexão] TCP direto OK em {time.time()-t0:.1f}s")
        return conn, "direto"
    except Exception as e:  # noqa: BLE001
        erros.append(f"direto: {type(e).__name__}: {e}")
    t0 = time.time()
    try:
        sock = socket_via_proxy(host, porta, timeout=timeout_conexao)
        conn = pymysql.connections.Connection(defer_connect=True, **comum)
        conn.connect(sock=sock)
        print(f"[conexão] via túnel do proxy OK em {time.time()-t0:.1f}s")
        return conn, "proxy"
    except Exception as e:  # noqa: BLE001
        erros.append(f"proxy: {type(e).__name__}: {e}")
    raise SystemExit("[conexão] FALHOU\n  " + "\n  ".join(erros))


def valor_csv(v):
    if v is None:
        return ""
    if isinstance(v, (bytes, bytearray)):
        return v.decode("utf-8", "replace")
    if isinstance(v, (datetime, date, Decimal)):
        return str(v)
    return v


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--sql", type=Path, default=SQL_PADRAO)
    ap.add_argument("--so", nargs="*", default=None, help="rótulos a rodar, ex.: Q0 Q1")
    ap.add_argument("--testar", action="store_true", help="só testa a conexão")
    ap.add_argument("--saida", type=Path, default=SAIDA)
    args = ap.parse_args()

    conn, via = conectar()
    with conn.cursor() as cur:
        cur.execute("SELECT @@session.transaction_read_only, VERSION(), CURRENT_USER(), NOW()")
        ro, ver, quem, agora = cur.fetchone()
        print(f"[sessão] read_only={ro} mysql={ver} user={quem} now={agora} via={via}")
        if int(ro) != 1:
            raise SystemExit("sessão não está READ ONLY; abortando")
    if args.testar:
        conn.close()
        return

    args.saida.mkdir(parents=True, exist_ok=True)
    stmts = ler_statements(args.sql)
    log = open(args.saida / "_log_execucao.txt", "a", encoding="utf-8")
    log.write(f"\n=== {datetime.now():%Y-%m-%d %H:%M:%S} arquivo={args.sql.name} via={via}\n")

    for rotulo, k, sql in stmts:
        if args.so and rotulo not in args.so and not sql.upper().startswith("SET"):
            continue
        if not eh_leitura(sql):
            msg = f"[{rotulo}.{k}] RECUSADO (não é leitura): {sql[:80]!r}"
            print(msg); log.write(msg + "\n")
            continue
        nome = f"{rotulo}" if k == 1 else f"{rotulo}_{k}"
        t0 = time.time()
        try:
            with conn.cursor() as cur:
                cur.execute(sql)
                if cur.description is None:
                    dt = time.time() - t0
                    msg = f"[{nome}] ok (sem resultado) {dt:.1f}s"
                    print(msg); log.write(msg + "\n")
                    continue
                cols = [d[0] for d in cur.description]
                linhas = cur.fetchall()
            dt = time.time() - t0
            destino = args.saida / f"{nome}.csv"
            with open(destino, "w", newline="", encoding="utf-8") as f:
                w = csv.writer(f)
                w.writerow(cols)
                for r in linhas:
                    w.writerow([valor_csv(v) for v in r])
            msg = f"[{nome}] {len(linhas)} linhas, {dt:.1f}s -> {destino.name}"
            print(msg); log.write(msg + "\n")
        except Exception as e:  # noqa: BLE001
            dt = time.time() - t0
            msg = f"[{nome}] ERRO após {dt:.1f}s: {type(e).__name__}: {e}"
            print(msg); log.write(msg + "\n")
            try:
                conn.ping(reconnect=True)
            except Exception:  # noqa: BLE001
                conn, via = conectar()
    log.close()
    conn.close()


if __name__ == "__main__":
    main()
