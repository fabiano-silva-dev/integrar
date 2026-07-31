#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Converte CSV padronizado do importador avançado para arquivo OFX.
"""

import csv
import re
import sys
from datetime import datetime, timedelta
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

from gerador_ofx import gerar_arquivo_ofx  # noqa: E402


LAYOUTS_OFX = {
    'grafeno': {'bank_id': '364', 'org': 'Grafeno', 'fi_id': '364'},
    'sicredi': {'bank_id': '748', 'org': 'Sicredi', 'fi_id': '748'},
    'caixa_federal': {'bank_id': '104', 'org': 'Caixa', 'fi_id': '104'},
    'caixa': {'bank_id': '104', 'org': 'Caixa', 'fi_id': '104'},
    'caixa_data_efetiva': {'bank_id': '104', 'org': 'Caixa', 'fi_id': '104'},
}


def parse_valor_brl(valor_str):
    if not valor_str:
        return 0.0
    valor = str(valor_str).strip()
    negativo = valor.startswith('-')
    valor = valor.lstrip('-').replace('.', '').replace(',', '.')
    try:
        numero = float(valor)
    except ValueError:
        return 0.0
    return -numero if negativo else numero


def normalizar_data(data_str):
    data = str(data_str).strip().split(' ')[0]
    for formato in ('%d/%m/%Y', '%d/%m/%y'):
        try:
            return datetime.strptime(data, formato).strftime('%d/%m/%Y')
        except ValueError:
            continue
    return data


def valor_com_sinal(row):
    valor = parse_valor_brl(row.get('Valor do Lançamento', '0'))
    debito = (row.get('Conta Débito') or '').strip()
    credito = (row.get('Conta Crédito') or '').strip()

    if credito and not debito:
        return -abs(valor)
    if debito and not credito:
        return abs(valor)
    return valor


def ler_transacoes_csv(caminho_csv):
    transacoes = []
    with open(caminho_csv, 'r', encoding='utf-8') as arquivo:
        reader = csv.DictReader(arquivo, delimiter=';')
        for row in reader:
            data = normalizar_data(row.get('Data do Lançamento', ''))
            valor = valor_com_sinal(row)
            historico = (row.get('Histórico') or '').strip()
            if not data or valor == 0 or not historico:
                continue
            transacoes.append({
                'data': data,
                'valor': valor,
                'memo': historico,
            })
    return transacoes


def converter_csv_para_ofx(caminho_csv, caminho_ofx, layout):
    config = LAYOUTS_OFX.get(layout, {'bank_id': '000', 'org': layout.title(), 'fi_id': '000'})
    transacoes = ler_transacoes_csv(caminho_csv)
    if not transacoes:
        raise ValueError('Nenhuma transação válida encontrada no CSV')

    transacoes_ordenadas = sorted(
        transacoes,
        key=lambda item: datetime.strptime(item['data'], '%d/%m/%Y'),
    )

    primeira = datetime.strptime(transacoes_ordenadas[0]['data'], '%d/%m/%Y')
    dtstart = (primeira - timedelta(days=1)).strftime('%d/%m/%Y')
    dtend = transacoes_ordenadas[-1]['data']
    saldo_final = sum(item['valor'] for item in transacoes_ordenadas)

    gerar_arquivo_ofx(
        transacoes=transacoes_ordenadas,
        output_path=caminho_ofx,
        bank_id=config['bank_id'],
        acct_id='00000000',
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=dtstart,
        dtend=dtend,
        org=config['org'],
        fi_id=config['fi_id'],
    )

    return {
        'layout': layout,
        'total_lancamentos': len(transacoes_ordenadas),
        'data_inicial': dtstart,
        'data_final': dtend,
    }


def main():
    if len(sys.argv) < 4:
        print('Uso: python conversor_csv_padrao_ofx.py <arquivo.csv> <arquivo.ofx> <layout>')
        sys.exit(1)

    caminho_csv = sys.argv[1]
    caminho_ofx = sys.argv[2]
    layout = sys.argv[3]

    try:
        resultado = converter_csv_para_ofx(caminho_csv, caminho_ofx, layout)
        print(f"Layout: {resultado['layout']}")
        print(f"Data inicial: {resultado['data_inicial']}")
        print(f"Data final: {resultado['data_final']}")
        print(f"Total de lançamentos: {resultado['total_lancamentos']}")
        print(f"Arquivo OFX gerado em: {caminho_ofx}")
    except Exception as erro:
        print(f'Erro na conversão: {erro}')
        sys.exit(1)


if __name__ == '__main__':
    main()
