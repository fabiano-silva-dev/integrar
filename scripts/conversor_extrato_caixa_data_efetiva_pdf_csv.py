#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Converte extrato PDF da Caixa no layout paisagem com colunas Data / Data Efetiva.

Sinais do layout:
- "Saldo anterior ao período solicitado"
- cabeçalho "Data Efetiva" / "Documento" / "Histórico"
- valores no formato R$ / - R$
"""

import csv
import os
import re
import sys
from datetime import datetime

from PyPDF2 import PdfReader

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from extrato_util import eh_descricao_saldo

PAD_DATA = re.compile(r'^(\d{2}/\d{2}/\d{4})$')
PAD_SALDO_DIA = re.compile(r'^(\d{2}/\d{2}/\d{4})\s+SALDO\s+DIA\b', re.I)
PAD_DETALHE = re.compile(r'^(\d{2}/\d{2})\s+(\d{2}:\d{2})(\d{0,12})\s*(.*)$')
PAD_VALORES = re.compile(
    r'(-?\s*R\$\s*[\d.]+,\d{2})\s+(R\$\s*[\d.]+,\d{2})\s*([CD])?\s*$',
    re.I,
)
PAD_VALOR = re.compile(r'(-?\s*R\$\s*[\d.]+,\d{2})', re.I)
PAD_DOC_COLADO = re.compile(r'^(\d{5,12})(.*)$')

CABECALHO_IGNORAR = (
    'data efetiva',
    'saldo anterior',
    'extrat',
    'cnpj:',
    'agência:',
    'agencia:',
    'sac caixa',
    'ouvidoria',
    'alô caixa',
    'alo caixa',
    'pessoas com deficiência',
    '0800',
)


def normalizar_linha(texto):
    return re.sub(r'[ \t]+', ' ', (texto or '').replace('\xa0', ' ')).strip()


def parse_valor_brl(texto):
    texto = normalizar_linha(texto)
    if not texto:
        return None
    negativo = '-' in texto.split('R$')[0] or texto.startswith('-')
    match = re.search(r'([\d.]+,\d{2})', texto)
    if not match:
        return None
    valor = float(match.group(1).replace('.', '').replace(',', '.'))
    return -valor if negativo else valor


def extrair_valor_e_prefixo(texto):
    texto = normalizar_linha(texto)
    match = PAD_VALORES.search(texto)
    if match:
        return parse_valor_brl(match.group(1)), texto[: match.start()].strip()
    match_um = PAD_VALOR.search(texto)
    if match_um:
        return parse_valor_brl(match_um.group(1)), texto[: match_um.start()].strip()
    return None, texto


def deve_ignorar(linha):
    baixa = linha.lower()
    if baixa == 'data' or baixa.startswith('documento'):
        return True
    return any(baixa.startswith(p) or p in baixa for p in CABECALHO_IGNORAR)


def coletar_linhas(reader):
    linhas = []
    for page in reader.pages:
        texto = page.extract_text() or ''
        for bruta in texto.split('\n'):
            linha = normalizar_linha(bruta)
            if linha:
                linhas.append(linha)
    return linhas


def extrair_lancamentos(linhas):
    lancamentos = []
    data_atual = None
    i = 0

    while i < len(linhas):
        linha = linhas[i]

        match_saldo = PAD_SALDO_DIA.match(linha)
        if match_saldo:
            data_atual = match_saldo.group(1)
            i += 1
            continue

        if deve_ignorar(linha):
            i += 1
            continue

        match_data = PAD_DATA.match(linha)
        if match_data:
            data_atual = match_data.group(1)
            i += 1
            continue

        match_det = PAD_DETALHE.match(linha)
        if not match_det or not data_atual:
            i += 1
            continue

        _ddmm, _hora, doc, resto = match_det.groups()
        valor, historico = extrair_valor_e_prefixo(resto)

        if not doc and historico:
            match_doc = PAD_DOC_COLADO.match(historico)
            if match_doc:
                doc, historico = match_doc.group(1), match_doc.group(2).strip()

        extras = []
        j = i + 1
        while valor is None and j < len(linhas):
            proxima = linhas[j]
            if (
                PAD_DATA.match(proxima)
                or PAD_SALDO_DIA.match(proxima)
                or PAD_DETALHE.match(proxima)
            ):
                break
            valor_prox, prefixo = extrair_valor_e_prefixo(proxima)
            if valor_prox is not None:
                valor = valor_prox
                if prefixo:
                    extras.append(prefixo)
                j += 1
                break
            extras.append(proxima)
            j += 1

        if extras:
            historico = f"{historico} {' '.join(extras)}".strip()

        if valor is not None and abs(valor) > 0 and not eh_descricao_saldo(historico):
            lancamentos.append(
                {
                    'data': data_atual,
                    'numero_doc': doc or '',
                    'historico': historico.strip(),
                    'valor': valor,
                    'natureza': 'C' if valor > 0 else 'D',
                }
            )

        i = j if j > i + 1 else i + 1

    return lancamentos


def parse_data(data_str):
    try:
        return datetime.strptime(data_str, '%d/%m/%Y')
    except ValueError:
        return datetime(1900, 1, 1)


def formatar_valor_brl(valor):
    return f'{abs(valor):,.2f}'.replace('.', 'X').replace(',', '.').replace('X', ',')


def eh_layout_data_efetiva(pdf_path_ou_reader):
    """
    Detecta o extrato paisagem com colunas Data / Data Efetiva.
    Usa orientação da página e marcas de texto (não depende só do tamanho).
    """
    reader = (
        pdf_path_ou_reader
        if hasattr(pdf_path_ou_reader, 'pages')
        else PdfReader(pdf_path_ou_reader)
    )
    if not reader.pages:
        return False

    pagina = reader.pages[0]
    box = pagina.mediabox
    largura = float(box.width)
    altura = float(box.height)
    paisagem = largura > altura

    texto = (pagina.extract_text() or '').lower().replace('\xa0', ' ')
    marcas = (
        'data efetiva' in texto
        or 'saldo anterior ao período solicitado' in texto
        or 'saldo anterior ao periodo solicitado' in texto
    )
    return paisagem or marcas


def converter(pdf_path, csv_path, conta_banco='1.1.1.01'):
    reader = PdfReader(pdf_path)
    lancamentos = extrair_lancamentos(coletar_linhas(reader))
    lancamentos.sort(key=lambda item: (parse_data(item['data']), item['numero_doc']))

    with open(csv_path, 'w', newline='', encoding='utf-8') as csvfile:
        writer = csv.writer(csvfile, delimiter=';')
        writer.writerow(
            [
                'Data do Lançamento',
                'Usuário',
                'Conta Débito',
                'Conta Crédito',
                'Valor do Lançamento',
                'Histórico',
                'Código da Filial/Matriz',
                'Nome da Empresa',
                'Número da Nota',
            ]
        )

        for item in lancamentos:
            if item['natureza'] == 'C':
                conta_debito = conta_banco
                conta_credito = ''
                prefixo = 'RCTO REF'
            else:
                conta_debito = ''
                conta_credito = conta_banco
                prefixo = 'PGTO REF'

            writer.writerow(
                [
                    item['data'],
                    'Sistema',
                    conta_debito,
                    conta_credito,
                    formatar_valor_brl(item['valor']),
                    f"{prefixo} {item['historico']}".strip(),
                    '',
                    '',
                    item['numero_doc'],
                ]
            )

    return len(lancamentos)


def main():
    if len(sys.argv) < 3:
        print(
            'Uso: python conversor_extrato_caixa_data_efetiva_pdf_csv.py '
            '<arquivo.pdf> <arquivo_saida.csv> [conta_banco]'
        )
        sys.exit(1)

    pdf_path = sys.argv[1]
    csv_path = sys.argv[2]
    conta_banco = sys.argv[3] if len(sys.argv) > 3 else '1.1.1.01'

    if not os.path.exists(pdf_path):
        print(f"Erro: O arquivo '{pdf_path}' não existe.")
        sys.exit(1)

    total = converter(pdf_path, csv_path, conta_banco)
    print(f'CSV padronizado gerado em: {csv_path}')
    print(f'Total de lançamentos processados: {total}')


if __name__ == '__main__':
    main()
