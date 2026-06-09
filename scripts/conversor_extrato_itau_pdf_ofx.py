#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extrato PDF Itaú para OFX."""

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

IGNORAR_PADROES = (
    'SALDO TOTAL DISPONÍVEL',
    'SALDO EM CONTA CORRENTE',
    'SALDO TOTAL',
    'RENDIMENTOS',
    'AUT MAIS',
    'REND PAGO',
)


def extrair_texto_pdf(caminho_pdf):
    linhas = []
    with pdfplumber.open(caminho_pdf) as pdf:
        for page in pdf.pages:
            text = page.extract_text()
            if text:
                linhas.extend(text.split('\n'))
    return linhas


def extrair_dados_conta_itau(linhas):
    texto = '\n'.join(linhas[:10])
    titular = ''
    agencia = ''
    conta = ''

    match = re.search(
        r'^(.+?)\s+CNPJ\s+[\d./\-]+\s+Ag[eê]ncia\s+(\d+)\s+Conta\s+([\d\-]+)',
        texto,
        re.IGNORECASE | re.MULTILINE,
    )
    if match:
        titular = match.group(1).strip()
        agencia = match.group(2).strip()
        conta = match.group(3).strip()

    return {
        'titular': titular,
        'cooperativa': agencia,
        'branch_id': agencia,
        'numero_conta': conta,
        'acct_id': conta.replace('.', '') if conta else '00000000',
    }


def extrair_periodo_itau(linhas):
    texto = '\n'.join(linhas[:15])
    match = re.search(
        r'per[ií]odo:\s*(\d{2}/\d{2}/\d{4})\s+at[eé]\s+(\d{2}/\d{2}/\d{4})',
        texto,
        re.IGNORECASE,
    )
    if not match:
        return None, None
    return match.group(1), match.group(2)


def deve_ignorar(descricao):
    upper = descricao.upper()
    return any(padrao in upper for padrao in IGNORAR_PADROES)


def parsear_lancamentos(linhas):
    lancamentos = []
    ultimo_memo = None

    padrao_data_valor = re.compile(
        r'^(\d{2}/\d{2}/\d{4})\s+(.+?)\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})\s*$'
    )
    padrao_so_valor = re.compile(
        r'^(\d{2}/\d{2}/\d{4})\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})\s*$'
    )

    for linha in linhas:
        linha = linha.strip()
        if not linha or linha.startswith('Data Lançamentos') or linha.startswith('Saldo total'):
            continue

        match = padrao_data_valor.match(linha)
        if match:
            data = match.group(1)
            descricao = match.group(2).strip()
            valor_str = match.group(3)
            if deve_ignorar(descricao):
                ultimo_memo = None
                continue
            valor = float(valor_str.replace('.', '').replace(',', '.'))
            if valor == 0:
                continue
            memo = descricao
            if ultimo_memo and not descricao.upper().startswith(ultimo_memo.upper()[:10]):
                memo = f'{ultimo_memo} {descricao}'.strip()
            lancamentos.append({'data': data, 'valor': valor, 'memo': memo})
            ultimo_memo = None
            continue

        match_so = padrao_so_valor.match(linha)
        if match_so:
            data = match_so.group(1)
            valor = float(match_so.group(2).replace('.', '').replace(',', '.'))
            if valor != 0 and ultimo_memo:
                lancamentos.append({
                    'data': data,
                    'valor': valor,
                    'memo': ultimo_memo,
                })
            ultimo_memo = None
            continue

        if re.match(r'^\d{2}/\d{2}/\d{4}\s+', linha):
            ultimo_memo = None
            continue

        if linha and not re.match(r'^[\d./\-]+$', linha):
            if ultimo_memo:
                ultimo_memo = f'{ultimo_memo} {linha}'.strip()
            else:
                ultimo_memo = linha

    return lancamentos


def converter_pdf_itau_para_ofx(caminho_pdf, caminho_ofx):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta_itau(linhas)
    periodo_inicio, periodo_fim = extrair_periodo_itau(linhas)
    lancamentos = parsear_lancamentos(linhas)
    if not lancamentos:
        raise ValueError('Nenhum lançamento válido encontrado no PDF')

    lancamentos.sort(key=lambda x: datetime.strptime(x['data'], '%d/%m/%Y'))
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
        bank_id='341',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Itau',
        fi_id='341',
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
        print('Uso: python conversor_extrato_itau_pdf_ofx.py <arquivo.pdf> <arquivo.ofx>')
        sys.exit(1)

    try:
        resultado = converter_pdf_itau_para_ofx(sys.argv[1], sys.argv[2])
        if resultado.get('titular'):
            print(f"Titular: {resultado['titular']}")
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
