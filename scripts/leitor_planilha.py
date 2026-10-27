#!/usr/bin/env python3
"""Leitura compartilhada de planilhas Excel 2003 XML (SpreadsheetML).

Alguns portais exportam SpreadsheetML com extensão .xls, embora o arquivo
não seja um XLS binário. pandas/xlrd/openpyxl não interpretam esse formato.
"""

from __future__ import annotations

import re
from datetime import datetime
from html.entities import html5
from pathlib import Path
from xml.etree import ElementTree as ET

NS = "urn:schemas-microsoft-com:office:spreadsheet"
SS = "{%s}" % NS
ENTIDADES_HTML = re.compile(rb"&([A-Za-z][A-Za-z0-9]+);")
ENTIDADES_XML = {"amp", "lt", "gt", "quot", "apos"}
MAX_COLUNAS = 16384
MAX_LINHAS = 100000


def eh_excel_xml_2003(caminho: str) -> bool:
    """Identifica SpreadsheetML pelos bytes, sem confiar na extensão."""
    with open(caminho, "rb") as arquivo:
        inicio = arquivo.read(8192)
    if inicio.startswith((b"\xff\xfe", b"\xfe\xff")):
        try:
            inicio = inicio.decode("utf-16", errors="ignore").encode("utf-8")
        except UnicodeError:
            return False
    return (b"urn:schemas-microsoft-com:office:spreadsheet" in inicio
            and (b"<Workbook" in inicio or b":Workbook" in inicio))


def _entidades_html_para_xml(conteudo: bytes) -> bytes:
    """Converte apenas entidades HTML não nativas em referências XML numéricas.

    Não aplicar html.unescape() ao documento inteiro: isso transformaria
    &amp; em &, corrompendo XMLs válidos.
    """
    def substituir(encontrada: re.Match[bytes]) -> bytes:
        nome = encontrada.group(1).decode("ascii")
        if nome in ENTIDADES_XML:
            return encontrada.group(0)
        caracteres = html5.get(nome + ";")
        if caracteres is None:
            return encontrada.group(0)  # Entidade desconhecida: erro XML explícito
        return b"".join(f"&#{ord(caractere)};".encode("ascii") for caractere in caracteres)

    if conteudo.startswith((b"\xff\xfe", b"\xfe\xff")):
        # Arquivos SpreadsheetML em UTF-16 também são gerados por alguns sistemas.
        texto = conteudo.decode("utf-16")
        def substituir_unicode(encontrada: re.Match[str]) -> str:
            nome = encontrada.group(1)
            if nome in ENTIDADES_XML:
                return encontrada.group(0)
            caracteres = html5.get(nome + ";")
            if caracteres is None:
                return encontrada.group(0)
            return "".join(f"&#{ord(c)};" for c in caracteres)
        return re.sub(r"&([A-Za-z][A-Za-z0-9]+);", substituir_unicode, texto).encode("utf-16")
    return ENTIDADES_HTML.sub(substituir, conteudo)


def _indice(atributos: dict[str, str], chave: str, padrao: int) -> int:
    valor = atributos.get(SS + chave)
    if valor is None:
        return padrao
    numero = int(valor)
    if numero < 1:
        raise ValueError(f"Índice inválido de {chave} no Excel XML.")
    return numero


def _celula_texto(celula: ET.Element) -> str:
    dados = celula.find(SS + "Data")
    if dados is None:
        return ""
    texto = "".join(dados.itertext()).strip()
    if dados.attrib.get(SS + "Type") == "DateTime":
        try:
            valor = datetime.fromisoformat(texto.rstrip("Z"))
            return valor.strftime("%d/%m/%Y %H:%M:%S" if valor.time().isoformat() != "00:00:00" else "%d/%m/%Y")
        except ValueError:
            pass
    return texto


def ler_excel_xml_2003(caminho: str) -> dict[str, list[list[str]]]:
    """Lê abas em linhas de texto preservando índices e mesclagens horizontais.

    Retorna o mesmo formato esperado pelo detector de tabelas:
    {nome_aba: [[celula1, celula2, ...], ...]}.
    """
    conteudo = Path(caminho).read_bytes()
    texto_inspecao = (
        conteudo.decode("utf-16")
        if conteudo.startswith((b"\xff\xfe", b"\xfe\xff"))
        else conteudo.decode("latin-1")
    )
    if re.search(r"<!\s*(?:DOCTYPE|ENTITY)\b", texto_inspecao, flags=re.I):
        raise ValueError("Planilha XML contém declarações não permitidas.")
    try:
        raiz = ET.fromstring(_entidades_html_para_xml(conteudo))
    except ET.ParseError as erro:
        raise ValueError(f"Planilha Excel 2003 XML inválida: {erro}") from erro
    if raiz.tag != SS + "Workbook":
        raise ValueError("Arquivo não é uma planilha Excel 2003 XML válida.")

    abas: dict[str, list[list[str]]] = {}
    for n_aba, aba in enumerate(raiz.findall(SS + "Worksheet"), start=1):
        nome = aba.attrib.get(SS + "Name", f"Planilha{n_aba}")
        if nome in abas:
            raise ValueError(f"Planilha XML contém abas com nomes duplicados: {nome}")
        linhas: list[list[str]] = []
        tabela = aba.find(SS + "Table")
        if tabela is not None:
            for elemento_linha in tabela.findall(SS + "Row"):
                indice_linha = _indice(elemento_linha.attrib, "Index", len(linhas) + 1)
                if indice_linha > MAX_LINHAS:
                    raise ValueError("A planilha XML excede o limite de linhas suportado.")
                if indice_linha <= len(linhas):
                    raise ValueError("Índice de linha repetido ou fora de ordem no Excel XML.")
                while len(linhas) < indice_linha - 1:
                    linhas.append([])

                valores: list[str] = []
                proxima_coluna = 1
                for celula in elemento_linha.findall(SS + "Cell"):
                    coluna = _indice(celula.attrib, "Index", proxima_coluna)
                    if coluna < proxima_coluna:
                        raise ValueError("Índice de coluna repetido ou fora de ordem no Excel XML.")
                    if coluna > MAX_COLUNAS:
                        raise ValueError("A planilha XML excede o limite de colunas suportado.")
                    while len(valores) < coluna - 1:
                        valores.append("")
                    valores.append(_celula_texto(celula))
                    mescla = int(celula.attrib.get(SS + "MergeAcross", "0"))
                    if mescla < 0 or len(valores) + mescla > MAX_COLUNAS:
                        raise ValueError("Mesclagem de células inválida no Excel XML.")
                    valores.extend([""] * mescla)
                    proxima_coluna = len(valores) + 1
                linhas.append(valores)
        abas[nome] = linhas
    if not abas:
        raise ValueError("Nenhuma aba foi encontrada na planilha XML.")
    return abas
