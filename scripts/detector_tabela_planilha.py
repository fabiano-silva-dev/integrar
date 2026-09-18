#!/usr/bin/env python3
"""
Detecta abas e tabelas em planilhas Excel (XLS/XLSX).

Uso:
  python3 detector_tabela_planilha.py entrada.xlsx
  python3 detector_tabela_planilha.py entrada.xlsx saida.csv --aba Extrato --indice 0
"""

from __future__ import annotations

import argparse
import json
import math
import os
import re
import sys
import warnings
from datetime import date, datetime
from typing import Any

import pandas as pd

warnings.filterwarnings("ignore")

MAX_PREVIEW_ROWS = 22
MAX_PREVIEW_COLS = 12
AMOSTRA_LINHAS = 5
MIN_COLUNAS = 2
MIN_DADOS = 1
MAX_LABEL_LEN = 80

RE_NUMERO = re.compile(
    r"^(R\$\s*)?-?\d{1,3}(\.\d{3})*(,\d{2})?%?$"
    r"|^(R\$\s*)?-?\d+([.,]\d+)?%?$"
)
RE_DATA = re.compile(
    r"^\d{1,2}[/\-.]\d{1,2}[/\-.]\d{2,4}([ T]\d{1,2}:\d{2}(:\d{2})?)?$"
    r"|^\d{4}-\d{2}-\d{2}([ T]\d{1,2}:\d{2}(:\d{2})?)?$"
)


def achatar(valor: Any) -> str:
    if valor is None:
        return ""
    if isinstance(valor, float) and (math.isnan(valor) or math.isinf(valor)):
        return ""
    if isinstance(valor, datetime):
        if valor.hour or valor.minute or valor.second:
            return valor.strftime("%d/%m/%Y %H:%M:%S")
        return valor.strftime("%d/%m/%Y")
    if isinstance(valor, date):
        return valor.strftime("%d/%m/%Y")
    texto = str(valor).strip()
    if texto.lower() in ("nan", "nat", "none", "<na>"):
        return ""
    return " ".join(texto.split())


def parece_numero(texto: str) -> bool:
    t = achatar(texto).replace("R$", "").replace("\xa0", " ").replace("%", "").strip()
    if not t:
        return False
    return bool(RE_NUMERO.match(t.replace(" ", ""))) or bool(RE_NUMERO.match(t))


def parece_data(texto: str) -> bool:
    t = achatar(texto)
    if not t:
        return False
    return bool(RE_DATA.match(t))


def celulas_preenchidas(row: list[str]) -> list[tuple[int, str]]:
    return [(i, v) for i, v in enumerate(row) if v]


def linha_vazia(row: list[str]) -> bool:
    return not celulas_preenchidas(row)


def faixa_colunas(row: list[str]) -> tuple[int, int] | None:
    preenchidas = celulas_preenchidas(row)
    if not preenchidas:
        return None
    return preenchidas[0][0], preenchidas[-1][0]


def recortar(row: list[str], inicio: int, fim: int) -> list[str]:
    fatia = list(row[inicio:fim + 1])
    while len(fatia) < (fim - inicio + 1):
        fatia.append("")
    return fatia


def parece_prosa(row: list[str]) -> bool:
    preenchidas = [v for _, v in celulas_preenchidas(row)]
    if not preenchidas:
        return False
    longas = sum(1 for v in preenchidas if len(v) > MAX_LABEL_LEN)
    return longas >= max(1, len(preenchidas) / 2)


def parece_cabecalho(row: list[str]) -> bool:
    preenchidas = [v for _, v in celulas_preenchidas(row)]
    if len(preenchidas) < MIN_COLUNAS:
        return False
    if parece_prosa(row):
        return False

    numericas = sum(1 for v in preenchidas if parece_numero(v) or parece_data(v))
    if numericas > len(preenchidas) * 0.45:
        return False

    media = sum(len(v) for v in preenchidas) / len(preenchidas)
    if media > 55:
        return False

    return True


def parece_linha_dados(row: list[str], cabecalho: list[str]) -> bool:
    if linha_vazia(row) or parece_prosa(row):
        return False

    preenchidas = celulas_preenchidas(row)
    if not preenchidas:
        return False

    cols_cab = max(len([c for c in cabecalho if c]), 1)
    if len(preenchidas) < max(MIN_COLUNAS, math.ceil(cols_cab * 0.35)):
        return False

    if parece_cabecalho(row):
        return False

    return True


def titulo_anterior(linhas: list[list[str]], indice_cabecalho: int) -> str:
    if indice_cabecalho <= 0:
        return ""
    anterior = linhas[indice_cabecalho - 1]
    preenchidas = [v for _, v in celulas_preenchidas(anterior)]
    if len(preenchidas) == 1 and len(preenchidas[0]) <= MAX_LABEL_LEN:
        return preenchidas[0]
    return ""


def nome_tabela(titulo: str, cabecalho: list[str], indice_local: int) -> str:
    if titulo:
        return titulo
    amostra = [c for c in cabecalho if c][:3]
    if amostra:
        return f"Tabela {indice_local + 1} — {', '.join(amostra)}"
    return f"Tabela {indice_local + 1}"


def detectar_tabelas_aba(linhas: list[list[str]]) -> list[dict[str, Any]]:
    tabelas: list[dict[str, Any]] = []
    i = 0
    total = len(linhas)

    while i < total:
        row = linhas[i]
        if linha_vazia(row) or not parece_cabecalho(row):
            i += 1
            continue

        faixa = faixa_colunas(row)
        if faixa is None:
            i += 1
            continue
        col_ini, col_fim = faixa
        cabecalho = recortar(row, col_ini, col_fim)

        j = i + 1
        while j < total and linha_vazia(linhas[j]):
            j += 1
        if j >= total or not parece_linha_dados(recortar(linhas[j], col_ini, col_fim), cabecalho):
            i += 1
            continue

        fim = j
        while fim + 1 < total:
            proxima = recortar(linhas[fim + 1], col_ini, col_fim)
            if linha_vazia(proxima):
                break
            if parece_cabecalho(linhas[fim + 1]) and not parece_linha_dados(proxima, cabecalho):
                break
            if not parece_linha_dados(proxima, cabecalho) and len(celulas_preenchidas(proxima)) < MIN_COLUNAS:
                break
            fim += 1

        dados = [recortar(linhas[r], col_ini, col_fim) for r in range(i + 1, fim + 1) if not linha_vazia(linhas[r])]
        if len(dados) < MIN_DADOS:
            i += 1
            continue

        titulo = titulo_anterior(linhas, i)
        tabelas.append({
            "titulo": titulo,
            "linha_cabecalho": i + 1,
            "linha_inicio": i + 2,
            "linha_fim": fim + 1,
            "coluna_inicio": col_ini + 1,
            "coluna_fim": col_fim + 1,
            "cabecalho": cabecalho,
            "dados": dados,
        })
        i = fim + 1

    return tabelas


def ler_planilha(caminho: str) -> dict[str, list[list[str]]]:
    extensao = os.path.splitext(caminho)[1].lower()
    engine = "openpyxl" if extensao == ".xlsx" else None
    xl = pd.ExcelFile(caminho, engine=engine)
    abas: dict[str, list[list[str]]] = {}
    for nome in xl.sheet_names:
        df = pd.read_excel(xl, sheet_name=nome, header=None)
        linhas: list[list[str]] = []
        for _, serie in df.iterrows():
            linhas.append([achatar(v) for v in serie.tolist()])
        abas[nome] = linhas
    return abas


def previa_aba(linhas: list[list[str]], colunas: int | None = None) -> dict[str, Any]:
    colunas = colunas or MAX_PREVIEW_COLS
    max_col = 0
    for row in linhas[:MAX_PREVIEW_ROWS]:
        preenchidas = celulas_preenchidas(row)
        if preenchidas:
            max_col = max(max_col, preenchidas[-1][0] + 1)
    max_col = min(max(max_col, 1), colunas)
    previa_linhas = []
    for idx, row in enumerate(linhas[:MAX_PREVIEW_ROWS], 1):
        previa_linhas.append({
            "numero": idx,
            "celulas": recortar(row, 0, max_col - 1),
            "vazia": linha_vazia(row),
        })
    return {
        "colunas_exibidas": max_col,
        "total_linhas": len(linhas),
        "linhas": previa_linhas,
    }


def montar_analise(caminho: str) -> dict[str, Any]:
    abas_brutas = ler_planilha(caminho)
    abas = []
    tabelas = []
    indice_global = 0

    for indice_aba, (nome, linhas) in enumerate(abas_brutas.items()):
        encontradas = detectar_tabelas_aba(linhas)
        indices_tabelas = []
        for indice_local, tabela in enumerate(encontradas):
            cabecalho = tabela["cabecalho"]
            dados = tabela["dados"]
            item = {
                "indice": indice_global,
                "indice_na_aba": indice_local,
                "aba": nome,
                "aba_indice": indice_aba,
                "nome": nome_tabela(tabela["titulo"], cabecalho, indice_local),
                "linha_cabecalho": tabela["linha_cabecalho"],
                "linha_inicio": tabela["linha_inicio"],
                "linha_fim": tabela["linha_fim"],
                "coluna_inicio": tabela["coluna_inicio"],
                "coluna_fim": tabela["coluna_fim"],
                "colunas": len(cabecalho),
                "linhas_dados": len(dados),
                "cabecalho": cabecalho,
                "amostra": dados[:AMOSTRA_LINHAS],
            }
            tabelas.append(item)
            indices_tabelas.append(indice_global)
            indice_global += 1

        abas.append({
            "nome": nome,
            "indice": indice_aba,
            "tem_tabela": bool(indices_tabelas),
            "total_linhas": len(linhas),
            "total_colunas": max((len(r) for r in linhas), default=0),
            "tabelas": indices_tabelas,
            "previa": previa_aba(linhas),
        })

    abas_com_tabela = [a["nome"] for a in abas if a["tem_tabela"]]
    return {
        "sucesso": bool(tabelas),
        "mensagem": (
            f"{len(tabelas)} tabela(s) em {len(abas_com_tabela)} aba(s)"
            if tabelas else
            "Nenhuma tabela foi identificada nesta planilha."
        ),
        "abas": abas,
        "abas_com_tabela": abas_com_tabela,
        "tabelas": tabelas,
        "tabela_escolhida": 0 if len(tabelas) == 1 else None,
        "aba_escolhida": abas_com_tabela[0] if len(abas_com_tabela) == 1 else None,
        "escolha_automatica": len(abas_com_tabela) == 1 and len(tabelas) == 1,
    }


def cabecalho_unico(cabecalho: list[str]) -> list[str]:
    usados: dict[str, int] = {}
    unicos = []
    for i, nome in enumerate(cabecalho):
        base = nome.strip() or f"Coluna {i + 1}"
        qtd = usados.get(base, 0)
        usados[base] = qtd + 1
        unicos.append(base if qtd == 0 else f"{base}_{qtd + 1}")
    return unicos


def extrair_tabela(caminho: str, saida: str, aba: str | None, indice: int, delimitador: str) -> dict[str, Any]:
    analise = montar_analise(caminho)
    if not analise["sucesso"]:
        return analise

    tabelas = analise["tabelas"]
    escolhida = None
    if aba:
        candidatas = [t for t in tabelas if t["aba"] == aba]
        if not candidatas:
            return {
                "sucesso": False,
                "mensagem": f'A aba "{aba}" não tem tabela detectada.',
            }
        for tabela in candidatas:
            if tabela["indice_na_aba"] == indice or tabela["indice"] == indice:
                escolhida = tabela
                break
        if escolhida is None:
            escolhida = candidatas[0]
    else:
        if indice < 0 or indice >= len(tabelas):
            return {"sucesso": False, "mensagem": "Índice de tabela inválido."}
        escolhida = tabelas[indice]

    abas = ler_planilha(caminho)
    linhas = abas[escolhida["aba"]]
    col_ini = escolhida["coluna_inicio"] - 1
    col_fim = escolhida["coluna_fim"] - 1
    cabecalho = cabecalho_unico(escolhida["cabecalho"])
    registros = []
    for r in range(escolhida["linha_inicio"] - 1, escolhida["linha_fim"]):
        if r >= len(linhas) or linha_vazia(linhas[r]):
            continue
        registros.append(recortar(linhas[r], col_ini, col_fim))

    df = pd.DataFrame(registros, columns=cabecalho)

    try:
        sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
        from conversor_laravel import ConversorLaravel

        conversor = ConversorLaravel()
        tipos = conversor._detectar_tipos(df)
        df, formatos_data = conversor._converter_datas(df, tipos)
        df = conversor._normalizar_floats_para_csv(df)
    except Exception:
        tipos = {}
        formatos_data = {}

    df.to_csv(saida, index=False, sep=delimitador, encoding="utf-8")

    analise.update({
        "sucesso": True,
        "arquivo_saida": saida,
        "tabela_escolhida": escolhida["indice"],
        "aba_escolhida": escolhida["aba"],
        "cabecalho": cabecalho,
        "linhas_dados": len(df),
        "tipos_detectados": tipos,
        "resumo": {
            "aba": escolhida["aba"],
            "linha_cabecalho": escolhida["linha_cabecalho"],
            "linha_inicio": escolhida["linha_inicio"],
            "linha_fim": escolhida["linha_fim"],
            "linhas": len(df),
            "colunas": len(cabecalho),
            "formatos_data": formatos_data,
        },
        "mensagem": f'Tabela "{escolhida["nome"]}" extraída da aba {escolhida["aba"]}.',
    })
    return analise


def main() -> None:
    parser = argparse.ArgumentParser(description="Detecta tabelas em planilhas Excel")
    parser.add_argument("entrada")
    parser.add_argument("saida", nargs="?")
    parser.add_argument("--aba", default=None)
    parser.add_argument("--indice", type=int, default=0)
    parser.add_argument("--delimitador", default=",")
    args = parser.parse_args()

    if not os.path.exists(args.entrada):
        print(json.dumps({
            "sucesso": False,
            "mensagem": f"Arquivo não encontrado: {args.entrada}",
        }, ensure_ascii=False))
        sys.exit(1)

    try:
        if args.saida:
            resultado = extrair_tabela(args.entrada, args.saida, args.aba, args.indice, args.delimitador)
        else:
            resultado = montar_analise(args.entrada)
    except Exception as exc:
        print(json.dumps({
            "sucesso": False,
            "mensagem": f"Erro ao analisar planilha: {exc}",
        }, ensure_ascii=False))
        sys.exit(1)

    print(json.dumps(resultado, ensure_ascii=False))


if __name__ == "__main__":
    main()
