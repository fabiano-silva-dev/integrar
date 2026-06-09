#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Converte extrato PDF do Sicredi para arquivo OFX.
Reutiliza o parser do conversor_extrato_sicredi_pdf_csv.py.
"""

import os
import sys
from datetime import datetime, timedelta

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

from conversor_extrato_sicredi_pdf_csv import (  # noqa: E402
    extrair_dados_conta_sicredi,
    extrair_periodo_sicredi,
    extrair_saldo_anterior_sicredi,
    extrair_texto_pdf,
    parsear_lancamentos,
)
from gerador_ofx import gerar_arquivo_ofx  # noqa: E402


def montar_memo_sicredi(lancamento):
    historico = lancamento['descricao'].strip()
    documento = lancamento.get('documento', '')
    if documento and documento not in ('PIX_CRED', 'PIX_CRE', 'PIX_DEB'):
        historico += f' DOC: {documento}'
    return historico


def converter_pdf_sicredi_para_ofx(caminho_pdf, caminho_ofx):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta_sicredi(linhas)
    periodo_inicio, periodo_fim = extrair_periodo_sicredi(linhas)
    saldo_anterior = extrair_saldo_anterior_sicredi(linhas)

    lancamentos = parsear_lancamentos(linhas)
    if not lancamentos:
        raise ValueError('Nenhum lançamento válido encontrado no PDF')

    lancamentos_ordenados = sorted(
        lancamentos,
        key=lambda item: datetime.strptime(item['data'], '%d/%m/%Y'),
    )

    transacoes = []
    for lancamento in lancamentos_ordenados:
        transacoes.append({
            'data': lancamento['data'],
            'valor': lancamento['valor'],
            'memo': montar_memo_sicredi(lancamento),
        })

    ultimo_saldo = lancamentos_ordenados[-1].get('saldo')
    if ultimo_saldo is not None:
        saldo_final = ultimo_saldo
    elif saldo_anterior is not None:
        saldo_final = saldo_anterior + sum(item['valor'] for item in transacoes)
    else:
        saldo_final = sum(item['valor'] for item in transacoes)

    dtstart = None
    if periodo_inicio:
        inicio_periodo = datetime.strptime(periodo_inicio, '%d/%m/%Y')
        dtstart = (inicio_periodo - timedelta(days=1)).strftime('%d/%m/%Y')

    if periodo_fim:
        dtend = periodo_fim
    else:
        dtend = lancamentos_ordenados[-1]['data']

    data_inicial = dtstart or transacoes[0]['data']
    data_final = dtend

    gerar_arquivo_ofx(
        transacoes=transacoes,
        output_path=caminho_ofx,
        bank_id='748',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Sicredi',
        fi_id='748',
    )

    return {
        'total_lancamentos': len(transacoes),
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
        print('Uso: python conversor_extrato_sicredi_pdf_ofx.py <arquivo.pdf> <arquivo_saida.ofx>')
        sys.exit(1)

    caminho_pdf = sys.argv[1]
    caminho_ofx = sys.argv[2]

    if not os.path.exists(caminho_pdf):
        print(f"Erro: O arquivo '{caminho_pdf}' não existe.")
        sys.exit(1)

    if not caminho_pdf.lower().endswith('.pdf'):
        print('Erro: O arquivo de entrada deve ser um PDF (.pdf)')
        sys.exit(1)

    try:
        resultado = converter_pdf_sicredi_para_ofx(caminho_pdf, caminho_ofx)
        if resultado.get('cooperativa'):
            print(f"Cooperativa extraída: {resultado['cooperativa']}")
        if resultado.get('numero_conta'):
            print(f"Conta extraída: {resultado['numero_conta']}")
        if resultado.get('titular'):
            print(f"Titular: {resultado['titular']}")
        print(f"ACCTID OFX: {resultado['acct_id']}")
        if resultado.get('data_inicial'):
            print(f"Data inicial: {resultado['data_inicial']}")
        if resultado.get('data_final'):
            print(f"Data final: {resultado['data_final']}")
        print(f"Total de lançamentos: {resultado['total_lancamentos']}")
        print(f"Saldo final: R$ {resultado['saldo_final']:,.2f}")
        print(f"Arquivo OFX gerado em: {caminho_ofx}")
    except Exception as erro:
        print(f'Erro na conversão: {erro}')
        sys.exit(1)


if __name__ == '__main__':
    main()
