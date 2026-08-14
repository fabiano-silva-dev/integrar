#!/usr/bin/env python3
"""
Extrator genérico de tabelas em PDF digital (sem OCR).

Estratégias, em ordem de preferência quando o score empata:
1. lattice — linhas/retângulos (tabelas de tela/HTML, ex.: Banrisul Pix)
2. cluster — alinhamento X/Y sem grade (relatórios ERP, ex.: Argo)
3. stream — pdfplumber text strategy (fallback)

Uso:
  python3 extrator_tabela_pdf.py entrada.pdf saida.csv [--indice 0] [--ignorar-totais 1]
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import re
import sys
import warnings
from collections import defaultdict
from typing import Any

warnings.filterwarnings("ignore")

try:
    import pdfplumber
except ImportError:
    print(json.dumps({
        "sucesso": False,
        "mensagem": "pdfplumber não encontrado. Instale com: pip install pdfplumber",
    }, ensure_ascii=False))
    sys.exit(1)


RE_TOTAL = re.compile(
    r"^\s*(total(\s+geral)?|página|pagina|subtotal|soma)\b",
    re.IGNORECASE,
)
RE_NUMERO = re.compile(
    r"^(R\$\s*)?-?\d{1,3}(\.\d{3})*(,\d{2})?%?$|^(R\$\s*)?-?\d+([.,]\d+)?%?$"
)
RE_DATA = re.compile(r"\d{1,2}/\d{1,2}/\d{2,4}")
MIN_CHARS_TEXTO = 20


def achatar(valor: Any) -> str:
    if valor is None:
        return ""
    return " ".join(str(valor).split())


def linha_vazia(row: list) -> bool:
    return not any(achatar(c) for c in row)


def eh_linha_total(row: list) -> bool:
    celulas = [achatar(c) for c in row if achatar(c)]
    if not celulas:
        return False
    texto = " ".join(celulas)
    if RE_TOTAL.match(texto):
        return True
    if len(celulas) <= 2 and re.search(r"\btotal\b", texto, re.IGNORECASE):
        return True
    return False


def parece_numero(texto: str) -> bool:
    t = achatar(texto).replace("R$", "").replace("%", "").strip()
    if not t:
        return False
    return bool(RE_NUMERO.match(t))


def parece_data(texto: str) -> bool:
    return bool(RE_DATA.search(achatar(texto)))


def normalizar_cabecalho(row: list) -> tuple[str, ...]:
    return tuple(achatar(c).lower() for c in row)


def limpar_tabela(rows: list[list], ignorar_totais: bool) -> list[list]:
    limpas = []
    for row in rows:
        if linha_vazia(row):
            continue
        if ignorar_totais and eh_linha_total(row):
            continue
        limpas.append([achatar(c) for c in row])
    return limpas


def score_tabela(cabecalho: list, dados: list[list]) -> float:
    if not dados or not cabecalho:
        return 0.0
    ncols = max(len(cabecalho), 1)
    if ncols < 2 or len(dados) < 2:
        return 0.0

    preenchimento = 0
    numericas = 0
    datas = 0
    total_celulas = 0
    for row in dados:
        for i in range(ncols):
            total_celulas += 1
            valor = achatar(row[i]) if i < len(row) else ""
            if valor:
                preenchimento += 1
                if parece_numero(valor):
                    numericas += 1
                if parece_data(valor):
                    datas += 1

    if total_celulas == 0:
        return 0.0

    ratio_preenchimento = preenchimento / total_celulas
    ratio_estrutura = min(1.0, (numericas + datas) / max(len(dados), 1) / 2)
    ratio_linhas = min(1.0, len(dados) / 15)
    ratio_cols = min(1.0, ncols / 6)

    score = (
        0.25 * ratio_linhas
        + 0.20 * ratio_cols
        + 0.25 * ratio_preenchimento
        + 0.30 * min(1.0, ratio_estrutura * 2)
    )
    if numericas == 0 and datas == 0:
        score *= 0.4
    return round(min(1.0, score), 4)


def extrair_lattice(page) -> list[list[list]]:
    tabelas = page.extract_tables() or []
    if tabelas:
        return tabelas
    if page.rects or page.lines:
        try:
            tabelas = page.extract_tables({
                "vertical_strategy": "lines",
                "horizontal_strategy": "lines",
                "snap_tolerance": 5,
                "intersection_tolerance": 5,
            }) or []
        except Exception:
            tabelas = []
    return tabelas


def extrair_stream(page) -> list[list[list]]:
    try:
        tabelas = page.extract_tables({
            "vertical_strategy": "text",
            "horizontal_strategy": "text",
            "snap_tolerance": 5,
            "join_tolerance": 5,
            "min_words_vertical": 3,
            "min_words_horizontal": 1,
        }) or []
    except Exception:
        tabelas = []
    return tabelas


def _agrupar_linhas(words: list[dict], y_tol: float = 3.5) -> list[list[dict]]:
    if not words:
        return []
    ordenadas = sorted(words, key=lambda w: (w["top"], w["x0"]))
    linhas = []
    atual = [ordenadas[0]]
    y_ref = ordenadas[0]["top"]
    for w in ordenadas[1:]:
        if abs(w["top"] - y_ref) <= y_tol:
            atual.append(w)
        else:
            linhas.append(sorted(atual, key=lambda x: x["x0"]))
            atual = [w]
            y_ref = w["top"]
    linhas.append(sorted(atual, key=lambda x: x["x0"]))
    return linhas


def _palavras_em_celulas(line_words: list[dict], gap_min: float) -> list[list[dict]]:
    if not line_words:
        return []
    celulas = []
    atual = [line_words[0]]
    for w in line_words[1:]:
        gap = w["x0"] - atual[-1]["x1"]
        if gap >= gap_min:
            celulas.append(atual)
            atual = [w]
        else:
            atual.append(w)
    celulas.append(atual)
    return celulas


def _texto_celula(words: list[dict]) -> str:
    return achatar(" ".join(w["text"] for w in words))


def _ancoras_x(celulas: list[list[dict]]) -> list[float]:
    return [c[0]["x0"] for c in celulas]


def _ancoras_compativeis(a: list[float], b: list[float], tol: float = 32.0) -> bool:
    if len(a) != len(b) or len(a) < 2:
        return False
    return all(abs(x - y) <= tol for x, y in zip(a, b))


def _linha_para_row(celulas: list[list[dict]]) -> list[str]:
    return [_texto_celula(c) for c in celulas]


def extrair_cluster(page, gap_min: float = 28.0) -> list[list[list]]:
    words = page.extract_words(x_tolerance=3, y_tolerance=3) or []
    if len(words) < 6:
        return []

    linhas = _agrupar_linhas(words)
    parsed = []
    for line_words in linhas:
        celulas = _palavras_em_celulas(line_words, gap_min)
        y = line_words[0]["top"]
        parsed.append({
            "y": y,
            "n": len(celulas),
            "ancoras": _ancoras_x(celulas) if celulas else [],
            "row": _linha_para_row(celulas),
            "celulas": celulas,
        })

    melhor = None
    i = 0
    while i < len(parsed):
        if parsed[i]["n"] < 2:
            i += 1
            continue
        ancora = parsed[i]["ancoras"]
        ncols = parsed[i]["n"]
        j = i
        while j + 1 < len(parsed):
            nxt = parsed[j + 1]
            if nxt["n"] == 0:
                if nxt["y"] - parsed[j]["y"] <= 22:
                    j += 1
                    continue
                break
            if nxt["n"] == ncols and _ancoras_compativeis(ancora, nxt["ancoras"]):
                j += 1
                continue
            break
        tamanho = sum(1 for k in range(i, j + 1) if parsed[k]["n"] == ncols)
        if melhor is None or tamanho > melhor["tamanho"]:
            melhor = {"inicio": i, "fim": j, "ncols": ncols, "tamanho": tamanho, "ancora": ancora}
        i = max(i + 1, j)

    if not melhor or melhor["tamanho"] < 3:
        return []

    inicio = melhor["inicio"]
    if inicio > 0:
        anterior = parsed[inicio - 1]
        if anterior["n"] == melhor["ncols"] and _ancoras_compativeis(melhor["ancora"], anterior["ancoras"], 40):
            inicio -= 1
        elif anterior["n"] >= 2 and abs(anterior["y"] - parsed[melhor["inicio"]]["y"]) <= 22:
            inicio -= 1
            if anterior["n"] != melhor["ncols"]:
                parsed[inicio]["row"] = _encaixar_na_ancora(anterior, melhor["ancora"])
                parsed[inicio]["n"] = melhor["ncols"]

    rows = []
    for k in range(inicio, melhor["fim"] + 1):
        item = parsed[k]
        if item["n"] == 0:
            continue
        if item["n"] == melhor["ncols"]:
            rows.append(item["row"])
        elif k == inicio:
            rows.append(_encaixar_na_ancora(item, melhor["ancora"]))
    if len(rows) < 3:
        return []
    return [rows]


def _encaixar_na_ancora(item: dict, ancora: list[float]) -> list[str]:
    row = [""] * len(ancora)
    for celula in item.get("celulas") or []:
        x = celula[0]["x0"]
        idx = min(range(len(ancora)), key=lambda i: abs(ancora[i] - x))
        texto = _texto_celula(celula)
        row[idx] = (row[idx] + " " + texto).strip() if row[idx] else texto
    if not any(row) and item.get("row"):
        for i, val in enumerate(item["row"][: len(row)]):
            row[i] = val
    return row


def candidato_de_raw(
    raw_rows: list[list],
    paginas: list[int],
    estrategia: str,
    ignorar_totais: bool,
) -> dict | None:
    rows = limpar_tabela(raw_rows, ignorar_totais=False)
    if len(rows) < 2:
        return None

    cabecalho = rows[0]
    dados = rows[1:]
    ignoradas = []
    if ignorar_totais:
        filtrados = []
        for row in dados:
            if eh_linha_total(row):
                ignoradas.append(" ".join(c for c in row if c)[:80])
            else:
                filtrados.append(row)
        dados = filtrados

    if len(dados) < 2:
        return None

    ncols = len(cabecalho)
    dados = [r + [""] * (ncols - len(r)) if len(r) < ncols else r[:ncols] for r in dados]
    cabecalho = [c if c else f"Coluna {i + 1}" for i, c in enumerate(cabecalho)]

    score = score_tabela(cabecalho, dados)
    if score < 0.18:
        return None

    return {
        "paginas": paginas,
        "estrategia": estrategia,
        "score": score,
        "cabecalho": cabecalho,
        "dados": dados,
        "linhas_dados": len(dados),
        "linhas_ignoradas": ignoradas,
        "assinatura": normalizar_cabecalho(cabecalho),
    }


def unir_por_assinatura(candidatos: list[dict]) -> list[dict]:
    grupos: dict[tuple, dict] = {}
    ordem = []
    for cand in candidatos:
        chave = (cand["estrategia"], cand["assinatura"])
        if chave not in grupos:
            grupos[chave] = {
                **cand,
                "dados": list(cand["dados"]),
                "paginas": list(cand["paginas"]),
                "linhas_ignoradas": list(cand["linhas_ignoradas"]),
            }
            ordem.append(chave)
            continue
        atual = grupos[chave]
        paginas_novas = set(cand["paginas"]) - set(atual["paginas"])
        if not paginas_novas:
            if cand["linhas_dados"] > len(atual["dados"]):
                atual["dados"] = list(cand["dados"])
                atual["linhas_dados"] = len(atual["dados"])
                atual["linhas_ignoradas"] = list(cand["linhas_ignoradas"])
                atual["score"] = max(atual["score"], cand["score"])
            continue
        atual["dados"].extend(cand["dados"])
        atual["paginas"] = sorted(set(atual["paginas"] + cand["paginas"]))
        atual["linhas_dados"] = len(atual["dados"])
        atual["linhas_ignoradas"].extend(cand["linhas_ignoradas"])
        atual["score"] = max(atual["score"], score_tabela(atual["cabecalho"], atual["dados"]))
    return [grupos[k] for k in ordem]


def deduplicar(candidatos: list[dict]) -> list[dict]:
    """Mantém a melhor estratégia quando o cabeçalho é o mesmo."""
    por_assinatura: dict[tuple, dict] = {}
    for cand in candidatos:
        chave = cand["assinatura"]
        atual = por_assinatura.get(chave)
        if atual is None or cand["score"] > atual["score"] or (
            cand["score"] == atual["score"] and cand["linhas_dados"] > atual["linhas_dados"]
        ):
            por_assinatura[chave] = cand
    saida = list(por_assinatura.values())
    saida.sort(key=lambda c: (c["score"], c["linhas_dados"]), reverse=True)
    return saida


def montar_publicos(candidatos: list[dict]) -> list[dict]:
    publicos = []
    for i, cand in enumerate(candidatos):
        publicos.append({
            "indice": i,
            "paginas": cand["paginas"],
            "estrategia": cand["estrategia"],
            "score": cand["score"],
            "cabecalho": cand["cabecalho"],
            "linhas_dados": cand["linhas_dados"],
            "linhas_ignoradas": cand["linhas_ignoradas"][:8],
            "amostra": cand["dados"][:8],
        })
    return publicos


def escrever_csv(caminho: str, cabecalho: list[str], dados: list[list[str]]) -> None:
    with open(caminho, "w", encoding="utf-8", newline="") as fh:
        writer = csv.writer(fh, delimiter=",")
        writer.writerow(cabecalho)
        writer.writerows(dados)


def extrair(caminho_pdf: str, caminho_csv: str, indice: int | None, ignorar_totais: bool) -> dict:
    if not os.path.exists(caminho_pdf):
        return {"sucesso": False, "mensagem": f"Arquivo não encontrado: {caminho_pdf}"}

    with pdfplumber.open(caminho_pdf) as pdf:
        total_chars = sum(len(p.chars or []) for p in pdf.pages)
        if total_chars < MIN_CHARS_TEXTO:
            return {
                "sucesso": False,
                "mensagem": (
                    "Este PDF não tem texto selecionável. "
                    "Na primeira versão só PDFs digitais são suportados."
                ),
            }

        brutos: dict[str, list[dict]] = defaultdict(list)
        for i, page in enumerate(pdf.pages, start=1):
            for raw in extrair_lattice(page):
                cand = candidato_de_raw(raw, [i], "lattice", ignorar_totais)
                if cand:
                    brutos["lattice"].append(cand)
            for gap in (22.0, 28.0, 40.0, 55.0):
                for raw in extrair_cluster(page, gap_min=gap):
                    cand = candidato_de_raw(raw, [i], "cluster", ignorar_totais)
                    if cand:
                        brutos["cluster"].append(cand)
            for raw in extrair_stream(page):
                cand = candidato_de_raw(raw, [i], "stream", ignorar_totais)
                if cand:
                    brutos["stream"].append(cand)

    unidos = []
    for estrategia in ("lattice", "cluster", "stream"):
        unidos.extend(unir_por_assinatura(brutos[estrategia]))

    candidatos = deduplicar(unidos)
    estruturados = [c for c in candidatos if c["estrategia"] in ("lattice", "cluster") and c["score"] >= 0.5]
    if estruturados:
        candidatos = estruturados
    if not candidatos:
        return {
            "sucesso": False,
            "mensagem": "Nenhuma tabela foi identificada neste PDF.",
        }

    candidatos.sort(
        key=lambda c: (
            c["score"] + (0.04 if c["estrategia"] == "lattice" else 0.0),
            c["linhas_dados"],
        ),
        reverse=True,
    )

    escolhido_idx = 0
    if indice is not None:
        if indice < 0 or indice >= len(candidatos):
            return {
                "sucesso": False,
                "mensagem": f"Índice de tabela inválido: {indice}",
            }
        escolhido_idx = indice

    escolhido = candidatos[escolhido_idx]
    escrever_csv(caminho_csv, escolhido["cabecalho"], escolhido["dados"])

    return {
        "sucesso": True,
        "mensagem": "Tabela extraída com sucesso",
        "arquivo_saida": caminho_csv,
        "estrategia": escolhido["estrategia"],
        "tabela_escolhida": escolhido_idx,
        "cabecalho": escolhido["cabecalho"],
        "linhas_dados": escolhido["linhas_dados"],
        "tabelas": montar_publicos(candidatos),
        "resumo": {
            "linhas": escolhido["linhas_dados"],
            "colunas": len(escolhido["cabecalho"]),
            "paginas": escolhido["paginas"],
            "estrategia": escolhido["estrategia"],
        },
    }


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Extrai tabelas de PDF digital para CSV")
    parser.add_argument("entrada")
    parser.add_argument("saida")
    parser.add_argument("--indice", type=int, default=None)
    parser.add_argument("--ignorar-totais", type=int, default=1, choices=[0, 1])
    return parser.parse_args(argv)


def main() -> int:
    args = parse_args(sys.argv[1:])
    resultado = extrair(
        args.entrada,
        args.saida,
        args.indice,
        bool(args.ignorar_totais),
    )
    print(json.dumps(resultado, ensure_ascii=False))
    return 0 if resultado.get("sucesso") else 1


if __name__ == "__main__":
    sys.exit(main())
