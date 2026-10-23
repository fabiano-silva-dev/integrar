#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extratos PDF do C6 Bank para OFX pela data contábil."""

import os
import re
import sys
from datetime import datetime, timedelta

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

import pdfplumber

from extrato_util import gravar_preview_json
from gerador_ofx import gerar_arquivo_ofx

PADRAO_PERIODO = re.compile(
    r'\(\s*(\d{2}/\d{2}/\d{4})\s*-\s*(\d{2}/\d{2}/\d{4})\s*\)'
)
PADRAO_MES_ANO = re.compile(
    r'^(?:janeiro|fevereiro|março|marco|abril|maio|junho|julho|agosto|'
    r'setembro|outubro|novembro|dezembro)\s+(\d{4})\b', re.IGNORECASE
)
PADRAO_CONTA = re.compile(r'Ag[eê]ncia:\s*([\d.-]+)\s*[•|\-]\s*Conta:\s*([\d.-]+)', re.IGNORECASE)
PADRAO_SALDO = re.compile(r'^Saldo do dia\s+\d{2}/\d{2}/\d{2,4}\s+R\$\s*([\d.]+,\d{2})', re.IGNORECASE)
PADRAO_LANCAMENTO = re.compile(
    r'^(\d{2}/\d{2})\s+(\d{2}/\d{2})\s+'
    r'(Entrada PIX|Sa[ií]da PIX|Pagamento|Entrada TED|Sa[ií]da TED|'
    r'Transfer[eê]ncia|Recebimento|D[eé]bito autom[aá]tico|Tarifa|Dep[oó]sito|Saque|Estorno)\s+'
    r'(.+?)\s+([+-]?\s*R\$\s*[\d.]+,\d{2})\s*$',
    re.IGNORECASE,
)


def valor_brl(texto):
    return float(texto.replace('R$', '').replace(' ', '').replace('.', '').replace(',', '.'))


def obter_ano_contabil(dia_mes_contabil, dia_mes_lancamento, ano_lancamento):
    data_lancamento = datetime.strptime(f'{dia_mes_lancamento}/{ano_lancamento}', '%d/%m/%Y')
    datas_candidatas = []
    for ano in (ano_lancamento - 1, ano_lancamento, ano_lancamento + 1):
        try:
            data = datetime.strptime(f'{dia_mes_contabil}/{ano}', '%d/%m/%Y')
            datas_candidatas.append(data)
        except ValueError:
            continue
    if not datas_candidatas:
        raise ValueError(f'Data contábil inválida: {dia_mes_contabil}')
    return min(datas_candidatas, key=lambda data: abs((data - data_lancamento).days)).strftime('%d/%m/%Y')


def ler_extrato(caminho_pdf):
    linhas = []
    with pdfplumber.open(caminho_pdf) as documento:
        for pagina in documento.pages:
            texto = pagina.extract_text()
            if texto:
                linhas.extend(texto.splitlines())
    if not linhas:
        raise ValueError('O PDF não contém texto extraível')

    periodo = None
    agencia = ''
    conta = ''
    titular = ''
    saldo = None
    ano_corrente = None
    lancamentos = []

    for linha in linhas:
        texto = linha.strip()
        if periodo is None:
            match = PADRAO_PERIODO.search(texto)
            if match:
                periodo = (match.group(1), match.group(2))
                ano_corrente = int(periodo[0][-4:])
        match_mes = PADRAO_MES_ANO.match(texto)
        if match_mes:
            ano_corrente = int(match_mes.group(1))
        match_conta = PADRAO_CONTA.search(texto)
        if match_conta:
            agencia, conta = match_conta.groups()
        if not titular and ' • ' in texto and 'Agência:' not in texto and 'Extrato' not in texto:
            possivel_titular = texto.split(' • ')[0].strip()
            if not possivel_titular.lower().startswith(('saldo', 'período', 'entradas')):
                titular = possivel_titular
        match_saldo = PADRAO_SALDO.match(texto)
        if match_saldo:
            saldo = valor_brl(match_saldo.group(1))
            continue
        match = PADRAO_LANCAMENTO.match(texto)
        if not match:
            continue
        if ano_corrente is None:
            raise ValueError('Não foi possível determinar o ano dos lançamentos do C6')
        data_lancamento, data_contabil, tipo, descricao, valor_texto = match.groups()
        data = obter_ano_contabil(data_contabil, data_lancamento, ano_corrente)
        valor = valor_brl(valor_texto)
        lancamentos.append({
            'data': data,
            'valor': valor,
            'memo': f'{tipo} - {descricao.strip()}',
        })

    if not lancamentos:
        raise ValueError('Nenhum lançamento reconhecido no extrato PDF do C6')
    if not conta:
        raise ValueError('Número da conta não encontrado no extrato PDF do C6')
    if periodo is None:
        raise ValueError('Período não encontrado no extrato PDF do C6')
    return lancamentos, periodo, agencia, conta, titular, saldo


def converter_pdf_c6_para_ofx(caminho_pdf, caminho_ofx, caminho_preview=None):
    lancamentos, periodo, agencia, conta, titular, saldo = ler_extrato(caminho_pdf)
    transacoes = sorted(lancamentos, key=lambda item: datetime.strptime(item['data'], '%d/%m/%Y'))
    data_inicial = (datetime.strptime(periodo[0], '%d/%m/%Y') - timedelta(days=1)).strftime('%d/%m/%Y')
    data_final = periodo[1]
    gerar_arquivo_ofx(
        transacoes=transacoes,
        output_path=caminho_ofx,
        bank_id='336',
        branch_id=agencia,
        acct_id=re.sub(r'\D', '', conta),
        acct_type='CHECKING',
        balance=saldo,
        dtstart=data_inicial,
        dtend=data_final,
        org='C6 Bank',
        fi_id='336',
    )
    preview = gravar_preview_json(transacoes, caminho_preview)
    return {
        'total_lancamentos': len(transacoes),
        'data_inicial': data_inicial,
        'data_final': data_final,
        'cooperativa': agencia,
        'numero_conta': conta,
        'titular': titular,
        'acct_id': re.sub(r'\D', '', conta),
        'saldo_final': saldo,
        'lancamentos': preview,
    }


def main():
    if len(sys.argv) < 3:
        print('Uso: python3 conversor_extrato_c6_pdf_ofx.py <arquivo.pdf> <arquivo.ofx> [preview.json]')
        sys.exit(1)
    try:
        dados = converter_pdf_c6_para_ofx(sys.argv[1], sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else None)
        print(f"Agência extraída: {dados['cooperativa']}")
        print(f"Conta extraída: {dados['numero_conta']}")
        if dados['titular']:
            print(f"Titular: {dados['titular']}")
        print(f"ACCTID OFX: {dados['acct_id']}")
        print(f"Data inicial: {dados['data_inicial']}")
        print(f"Data final: {dados['data_final']}")
        print(f"Total de lançamentos: {dados['total_lancamentos']}")
        print(f"Arquivo OFX gerado em: {sys.argv[2]}")
    except Exception as erro:
        print(f'Erro na conversão: {erro}', file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
