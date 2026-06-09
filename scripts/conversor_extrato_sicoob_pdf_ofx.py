#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Converte extrato PDF do Sicoob para arquivo OFX.
Reutiliza o parser do conversor_extrato_sicoob_pdf_csv.py.
"""

import os
import re
import sys
from datetime import datetime, timedelta

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

from conversor_extrato_sicoob_pdf_csv import (  # noqa: E402
    detectar_formato_sicoob,
    extrair_dados_conta_sicoob,
    extrair_periodo_sicoob,
    extrair_texto_pdf,
    organizar_lancamentos_por_data,
    processar_lancamentos_com_data_valor,
)
from gerador_ofx import gerar_arquivo_ofx  # noqa: E402


def _eh_linha_ruido_ofx(parte):
    parte = parte.strip()
    if parte in ('C', 'D'):
        return True
    if re.match(r'^\d{1,3}(?:\.\d{3})*,\d{2}[DC]?$', parte):
        return True
    return False


def montar_memo_ofx_sicoob(lancamento_completo, historico=''):
    """Monta o MEMO no padrão do OFX Sicoob a partir das linhas do lançamento."""
    partes = [p.strip() for p in lancamento_completo.split('|') if p.strip()]
    if not partes:
        return historico or 'Transação'

    primeira = partes[0]
    primeira = re.sub(r'^\d{2}/\d{2}(/\d{4})?\s*', '', primeira)
    primeira = re.sub(r'\s+\d{1,3}(?:\.\d{3})*,\d{2}[DC]?\s*$', '', primeira).strip()

    memo_partes = []
    if primeira:
        memo_partes.append(primeira)

    for parte in partes[1:]:
        if _eh_linha_ruido_ofx(parte):
            continue
        memo_partes.append(parte)

    memo = ' '.join(memo_partes).strip()
    memo = re.sub(r'\bDOC:\s*', 'DOC.: ', memo)
    return memo or historico or 'Transação'


def converter_pdf_sicoob_para_ofx(caminho_pdf, caminho_ofx):
    texto, linhas = extrair_texto_pdf(caminho_pdf)
    if not texto:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta_sicoob(linhas)
    periodo_inicio, periodo_fim, ano_referencia = extrair_periodo_sicoob(linhas)

    formato = detectar_formato_sicoob(linhas)
    lancamentos_por_data = organizar_lancamentos_por_data(texto)
    lancamentos_processados, saldo_anterior, ultimo_saldo_dia = processar_lancamentos_com_data_valor(
        lancamentos_por_data,
        formato,
        ano_referencia,
    )

    if not lancamentos_processados:
        raise ValueError('Nenhum lançamento válido encontrado no PDF')

    lancamentos_ordenados = sorted(
        lancamentos_processados,
        key=lambda item: datetime.strptime(item['data'], '%d/%m/%Y'),
    )

    transacoes = []
    for lancamento in lancamentos_ordenados:
        lancamento_completo = lancamento.get('lancamento_completo', '').strip()
        memo = montar_memo_ofx_sicoob(lancamento_completo)

        transacoes.append({
            'data': lancamento['data'],
            'valor': lancamento['valor'],
            'memo': memo,
        })

    if ultimo_saldo_dia is not None:
        saldo_final = ultimo_saldo_dia
    elif saldo_anterior is not None:
        saldo_final = saldo_anterior + sum(item['valor'] for item in transacoes)
    else:
        saldo_final = sum(item['valor'] for item in transacoes)

    dtstart = None
    if periodo_inicio:
        inicio_periodo = datetime.strptime(periodo_inicio, '%d/%m/%Y')
        dtstart = (inicio_periodo - timedelta(days=1)).strftime('%d/%m/%Y')

    metadata = gerar_arquivo_ofx(
        transacoes=transacoes,
        output_path=caminho_ofx,
        bank_id='756',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=dtstart,
        dtend=lancamentos_ordenados[-1]['data'],
    )

    data_inicial = dtstart or transacoes[0]['data']
    data_final = lancamentos_ordenados[-1]['data']

    return {
        'formato': formato,
        'total_lancamentos': len(transacoes),
        'saldo_anterior': saldo_anterior,
        'saldo_final': saldo_final,
        'cooperativa': dados_conta['cooperativa'],
        'numero_conta': dados_conta['numero_conta'],
        'titular': dados_conta['titular'],
        'acct_id': dados_conta['acct_id'],
        'data_inicial': data_inicial,
        'data_final': data_final,
        **metadata,
    }


def main():
    if len(sys.argv) < 3:
        print('Uso: python conversor_extrato_sicoob_pdf_ofx.py <arquivo.pdf> <arquivo_saida.ofx>')
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
        resultado = converter_pdf_sicoob_para_ofx(caminho_pdf, caminho_ofx)
        print(f"Formato detectado: {resultado['formato']} colunas")
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
