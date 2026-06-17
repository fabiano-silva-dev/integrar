#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Converte extrato PDF Banrisul (conta corrente) para arquivo OFX.
Reutiliza o parser do conversor_extrato_banrisul_pdf_csv.py.
"""

import json
import os
import sys
from datetime import datetime, timedelta

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

from conversor_extrato_banrisul_pdf_csv import (  # noqa: E402
    extrair_dados_conta_banrisul,
    extrair_saldo_final_banrisul,
    extrair_texto_pdf,
    montar_memo_banrisul,
    parsear_lancamentos,
)
from gerador_ofx import gerar_arquivo_ofx  # noqa: E402


def montar_preview_lancamentos(lancamentos):
    preview = []
    for item in lancamentos:
        preview.append({
            'data': item['data'],
            'historico': montar_memo_banrisul(item),
            'historico_original': item['descricao'],
            'documento': item.get('documento', ''),
            'valor': round(item['valor'], 2),
            'enriquecido': False,
        })
    return preview


def converter_pdf_banrisul_para_ofx(caminho_pdf, caminho_ofx, caminho_preview=None):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta_banrisul(linhas)
    saldo_final = extrair_saldo_final_banrisul(linhas)

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
            'memo': montar_memo_banrisul(lancamento),
        })

    if saldo_final is None:
        saldo_final = sum(item['valor'] for item in transacoes)

    primeira_data = transacoes[0]['data']
    ultima_data = transacoes[-1]['data']
    inicio = datetime.strptime(primeira_data, '%d/%m/%Y') - timedelta(days=1)
    data_inicial = inicio.strftime('%d/%m/%Y')
    data_final = ultima_data

    gerar_arquivo_ofx(
        transacoes=transacoes,
        output_path=caminho_ofx,
        bank_id='041',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Banrisul',
        fi_id='041',
    )

    preview = montar_preview_lancamentos(lancamentos_ordenados)
    if caminho_preview:
        with open(caminho_preview, 'w', encoding='utf-8') as arquivo:
            json.dump(preview, arquivo, ensure_ascii=False)

    return {
        'total_lancamentos': len(transacoes),
        'saldo_final': saldo_final,
        'cooperativa': dados_conta['cooperativa'],
        'numero_conta': dados_conta['numero_conta'],
        'titular': dados_conta['titular'],
        'acct_id': dados_conta['acct_id'],
        'data_inicial': data_inicial,
        'data_final': data_final,
        'lancamentos': preview,
    }


def main():
    if len(sys.argv) < 3:
        print('Uso: python conversor_extrato_banrisul_pdf_ofx.py <arquivo.pdf> <arquivo_saida.ofx> [preview.json]')
        sys.exit(1)

    caminho_pdf = sys.argv[1]
    caminho_ofx = sys.argv[2]
    caminho_preview = sys.argv[3] if len(sys.argv) > 3 else None

    if not os.path.exists(caminho_pdf):
        print(f"Erro: O arquivo '{caminho_pdf}' não existe.")
        sys.exit(1)

    if not caminho_pdf.lower().endswith('.pdf'):
        print('Erro: O arquivo de entrada deve ser um PDF (.pdf)')
        sys.exit(1)

    try:
        resultado = converter_pdf_banrisul_para_ofx(caminho_pdf, caminho_ofx, caminho_preview)
        if resultado.get('cooperativa'):
            print(f"Agência extraída: {resultado['cooperativa']}")
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
        if caminho_preview:
            print(f"Prévia JSON: {caminho_preview}")
    except Exception as erro:
        print(f'Erro na conversão: {erro}')
        sys.exit(1)


if __name__ == '__main__':
    main()
