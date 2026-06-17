#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extrato PDF Santander (Internet Banking Empresarial) para OFX."""

import os
import re
import sys
from datetime import datetime

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

try:
    import pdfplumber
except ImportError:
    print('Erro: pdfplumber não encontrado.')
    sys.exit(1)

from gerador_ofx import gerar_arquivo_ofx  # noqa: E402
from extrato_util import eh_descricao_saldo  # noqa: E402

MESES = {
    'janeiro': 1, 'fevereiro': 2, 'março': 3, 'marco': 3, 'abril': 4,
    'maio': 5, 'junho': 6, 'julho': 7, 'agosto': 8,
    'setembro': 9, 'outubro': 10, 'novembro': 11, 'dezembro': 12,
}


def extrair_texto_pdf(caminho_pdf):
    linhas = []
    with pdfplumber.open(caminho_pdf) as pdf:
        for page in pdf.pages:
            text = page.extract_text()
            if text:
                linhas.extend(text.split('\n'))
    return linhas


def extrair_dados_conta_santander(linhas):
    titular = ''
    agencia = ''
    conta = ''

    for linha in linhas[:15]:
        match = re.search(
            r'^(.+?)\s+Ag[eê]ncia:\s*(\d+)\s+Conta:\s*(\d+)',
            linha.strip(),
            re.IGNORECASE,
        )
        if match:
            titular = match.group(1).strip().rstrip('.')
            agencia = match.group(2).strip()
            conta = match.group(3).strip()
            break

    return {
        'titular': titular,
        'cooperativa': agencia,
        'branch_id': agencia,
        'numero_conta': conta,
        'acct_id': conta or '00000000',
    }


def parsear_data_cabecalho(linha):
    match = re.search(
        r'\d{1,2}\s+de\s+(\w+)\s+de\s+(\d{4})',
        linha,
        re.IGNORECASE,
    )
    if not match:
        return None
    mes_nome = match.group(1).lower()
    ano = int(match.group(2))
    dia_match = re.search(r'(\d{1,2})\s+de\s+' + re.escape(match.group(1)), linha, re.IGNORECASE)
    if not dia_match:
        return None
    mes = MESES.get(mes_nome)
    if not mes:
        return None
    return f"{int(dia_match.group(1)):02d}/{mes:02d}/{ano}"


def parsear_valor_brl(texto):
    valor = texto.replace('.', '').replace(',', '.')
    try:
        return float(valor)
    except ValueError:
        return None


def parsear_lancamentos(linhas):
    lancamentos = []
    data_atual = None

    padrao_transacao = re.compile(
        r'^(.+?)\s+(CREDITO|DEBITO)\s+R\$\s*([\d.]+,\d{2})\s*$',
        re.IGNORECASE,
    )

    for linha in linhas:
        linha = linha.strip()
        if not linha or linha.startswith('Internet Banking'):
            continue

        data_cab = parsear_data_cabecalho(linha)
        if data_cab:
            data_atual = data_cab
            continue

        match = padrao_transacao.match(linha)
        if match and data_atual:
            descricao = match.group(1).strip()
            if eh_descricao_saldo(descricao):
                continue
            tipo = match.group(2).upper()
            valor = parsear_valor_brl(match.group(3))
            if valor is None or valor == 0:
                continue
            if tipo == 'DEBITO':
                valor = -abs(valor)
            else:
                valor = abs(valor)
            lancamentos.append({
                'data': data_atual,
                'valor': valor,
                'memo': descricao,
            })

    return lancamentos


def converter_pdf_santander_para_ofx(caminho_pdf, caminho_ofx):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta_santander(linhas)
    lancamentos = parsear_lancamentos(linhas)
    if not lancamentos:
        raise ValueError('Nenhum lançamento válido encontrado no PDF')

    lancamentos.sort(key=lambda x: datetime.strptime(x['data'], '%d/%m/%Y'))
    saldo_final = sum(item['valor'] for item in lancamentos)
    data_inicial = lancamentos[0]['data']
    data_final = lancamentos[-1]['data']

    gerar_arquivo_ofx(
        transacoes=lancamentos,
        output_path=caminho_ofx,
        bank_id='033',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Santander',
        fi_id='033',
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
        print('Uso: python conversor_extrato_santander_pdf_ofx.py <arquivo.pdf> <arquivo.ofx>')
        sys.exit(1)

    caminho_pdf = sys.argv[1]
    caminho_ofx = sys.argv[2]

    try:
        resultado = converter_pdf_santander_para_ofx(caminho_pdf, caminho_ofx)
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
        print(f"Arquivo OFX gerado em: {caminho_ofx}")
    except Exception as erro:
        print(f'Erro na conversão: {erro}')
        sys.exit(1)


if __name__ == '__main__':
    main()
