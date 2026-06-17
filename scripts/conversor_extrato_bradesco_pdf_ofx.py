#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extrato PDF Bradesco para OFX."""

import os
import re
import sys
from datetime import datetime, timedelta

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

try:
    import pdfplumber
except ImportError:
    print('Erro: pdfplumber não encontrado.')
    sys.exit(1)

from gerador_ofx import gerar_arquivo_ofx  # noqa: E402
from extrato_util import eh_descricao_saldo  # noqa: E402


def extrair_texto_pdf(caminho_pdf):
    linhas = []
    with pdfplumber.open(caminho_pdf) as pdf:
        for page in pdf.pages:
            text = page.extract_text()
            if text:
                linhas.extend(text.split('\n'))
    return linhas


def parse_valor(texto):
    return float(texto.replace('.', '').replace(',', '.'))


def extrair_dados_conta_bradesco(linhas):
    agencia = ''
    conta = ''
    titular = ''

    for linha in linhas[:10]:
        match_extrato = re.search(
            r'Ag:\s*(\d+)\s*\|\s*CC:\s*([\d\-]+)',
            linha,
            re.IGNORECASE,
        )
        if match_extrato:
            agencia = match_extrato.group(1).lstrip('0') or match_extrato.group(1)
            conta = match_extrato.group(2).strip()

        match_linha = re.match(r'^(\d+)\s*\|\s*([\d\-]+)', linha.strip())
        if match_linha and not agencia:
            agencia = match_linha.group(1).lstrip('0') or match_linha.group(1)
            conta = match_linha.group(2).strip()

    return {
        'titular': titular,
        'cooperativa': agencia,
        'branch_id': agencia,
        'numero_conta': conta,
        'acct_id': conta.replace('.', '') if conta else '00000000',
    }


def extrair_periodo_bradesco(linhas):
    for linha in linhas[:10]:
        match = re.search(
            r'Entre\s+(\d{2}/\d{2}/\d{4})\s+e\s+(\d{2}/\d{2}/\d{4})',
            linha,
            re.IGNORECASE,
        )
        if match:
            return match.group(1), match.group(2)
    return None, None


def extrair_saldo_anterior(linhas):
    for linha in linhas[:20]:
        match = re.search(
            r'(\d{2}/\d{2}/\d{4})\s+SALDO ANTERIOR\s+(-?[\d.]+,\d{2})',
            linha,
            re.IGNORECASE,
        )
        if match:
            return parse_valor(match.group(2))
    return None


def parsear_lancamentos(linhas):
    lancamentos = []
    data_atual = None
    descricao_pendente = ''

    padrao_data_credito = re.compile(
        r'^(\d{2}/\d{2}/\d{4})\s+(\d+)\s+([\d.]+,\d{2})\s+(-?[\d.]+,\d{2})$'
    )
    padrao_doc_valor = re.compile(
        r'^(\d{4,7})\s+(-?[\d.]+,\d{2})\s+(-?[\d.]+,\d{2})$'
    )
    padrao_desc_valor = re.compile(
        r'^(.+?)\s+(\d+)\s+([\d.]+,\d{2})\s+(-?[\d.]+,\d{2})$'
    )

    for linha in linhas:
        linha = linha.strip()
        if not linha or linha.startswith('Data Lançamento') or linha.startswith('Agência | Conta'):
            continue
        if linha.startswith('Extrato de:'):
            continue
        if re.match(r'^\d{11,14}$', linha.replace('.', '').replace('-', '').replace('/', '')):
            continue
        if linha.upper().startswith('DES:'):
            continue
        if 'SALDO ANTERIOR' in linha.upper():
            match_data = re.match(r'^(\d{2}/\d{2}/\d{4})', linha)
            if match_data:
                data_atual = match_data.group(1)
            continue

        match = padrao_data_credito.match(linha)
        if match:
            data_atual = match.group(1)
            doc = match.group(2)
            valor = parse_valor(match.group(3))
            memo = f'{descricao_pendente} DOC: {doc}'.strip() if descricao_pendente else f'DOC: {doc}'
            if not eh_descricao_saldo(memo):
                lancamentos.append({'data': data_atual, 'valor': valor, 'memo': memo})
            descricao_pendente = ''
            continue

        match = padrao_desc_valor.match(linha)
        if match:
            desc = match.group(1).strip()
            doc = match.group(2)
            valor = parse_valor(match.group(3))
            memo = desc
            if descricao_pendente:
                memo = f'{descricao_pendente} {desc}'.strip()
            memo = f'{memo} DOC: {doc}'
            if not eh_descricao_saldo(memo):
                lancamentos.append({'data': data_atual or '01/01/2026', 'valor': valor, 'memo': memo})
            descricao_pendente = ''
            continue

        match = padrao_doc_valor.match(linha)
        if match and data_atual:
            doc = match.group(1)
            valor = parse_valor(match.group(2))
            memo = descricao_pendente or 'Lançamento'
            memo = f'{memo} DOC: {doc}'.strip()
            if not eh_descricao_saldo(memo):
                lancamentos.append({'data': data_atual, 'valor': valor, 'memo': memo})
            descricao_pendente = ''
            continue

        if re.match(r'^\d{2}/\d{2}/\d{4}\s+', linha):
            match_data = re.match(r'^(\d{2}/\d{2}/\d{4})', linha)
            if match_data:
                data_atual = match_data.group(1)
            continue

        if re.match(r'^[\d\s|]+$', linha):
            continue

        descricao_pendente = f'{descricao_pendente} {linha}'.strip() if descricao_pendente else linha

    return lancamentos


def converter_pdf_bradesco_para_ofx(caminho_pdf, caminho_ofx):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta_bradesco(linhas)
    periodo_inicio, periodo_fim = extrair_periodo_bradesco(linhas)
    saldo_anterior = extrair_saldo_anterior(linhas)
    lancamentos = parsear_lancamentos(linhas)
    if not lancamentos:
        raise ValueError('Nenhum lançamento válido encontrado no PDF')

    lancamentos.sort(key=lambda x: datetime.strptime(x['data'], '%d/%m/%Y'))

    if saldo_anterior is not None:
        saldo_final = saldo_anterior + sum(item['valor'] for item in lancamentos)
    else:
        saldo_final = sum(item['valor'] for item in lancamentos)

    if periodo_inicio:
        inicio = datetime.strptime(periodo_inicio, '%d/%m/%Y')
        data_inicial = (inicio - timedelta(days=1)).strftime('%d/%m/%Y')
    else:
        data_inicial = lancamentos[0]['data']

    data_final = periodo_fim or lancamentos[-1]['data']

    gerar_arquivo_ofx(
        transacoes=lancamentos,
        output_path=caminho_ofx,
        bank_id='237',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Bradesco',
        fi_id='237',
    )

    return {
        'total_lancamentos': len(lancamentos),
        'saldo_final': saldo_final,
        'cooperativa': dados_conta['cooperativa'],
        'numero_conta': dados_conta['numero_conta'],
        'titular': dados_conta['titular'],
        'acct_id': dados_conta['acct_id'],
        'data_inicial': data_inicial,
        'data_final': data_final,
    }


def main():
    if len(sys.argv) < 3:
        print('Uso: python conversor_extrato_bradesco_pdf_ofx.py <arquivo.pdf> <arquivo.ofx>')
        sys.exit(1)

    try:
        resultado = converter_pdf_bradesco_para_ofx(sys.argv[1], sys.argv[2])
        if resultado.get('cooperativa'):
            print(f"Agência extraída: {resultado['cooperativa']}")
        if resultado.get('numero_conta'):
            print(f"Conta extraída: {resultado['numero_conta']}")
        print(f"ACCTID OFX: {resultado['acct_id']}")
        print(f"Data inicial: {resultado['data_inicial']}")
        print(f"Data final: {resultado['data_final']}")
        print(f"Total de lançamentos: {resultado['total_lancamentos']}")
        print(f"Arquivo OFX gerado em: {sys.argv[2]}")
    except Exception as erro:
        print(f'Erro na conversão: {erro}')
        sys.exit(1)


if __name__ == '__main__':
    main()
