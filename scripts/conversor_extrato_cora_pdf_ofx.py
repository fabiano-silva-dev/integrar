#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extrato PDF Cora (Cora SCFI) para OFX."""

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
from extrato_util import eh_descricao_saldo, gravar_preview_json  # noqa: E402

LINHAS_IGNORAR = (
    'extrato do período',
    'extrato do periodo',
    'saldo inicial disponível',
    'saldo inicial disponivel',
    'total de entradas',
    'total de saídas',
    'total de saidas',
    'saldo final disponível',
    'saldo final disponivel',
    'transações',
    'transacoes',
    'cora scfi',
    'ouvidoria',
    'extrato gerado',
)

PADRAO_LANCAMENTO = re.compile(
    r'^(.+?)\s+([+-])\s*R\$\s*([\d.]+,\d{2})\s*$'
)
PADRAO_SALDO_DIA = re.compile(
    r'^(\d{2}/\d{2}/\d{4})\s+Saldo do dia\s+R\$\s*([\d.]+,\d{2})',
    re.IGNORECASE,
)
PADRAO_PERIODO = re.compile(
    r'Extrato do per[ií]odo\s+(\d{2}/\d{2}/\d{4})\s+a\s+(\d{2}/\d{2}/\d{4})',
    re.IGNORECASE,
)
PADRAO_CONTA = re.compile(
    r'Ag[eê]ncia:\s*(\d+)\s*-\s*Conta:\s*([\d.\-]+)',
    re.IGNORECASE,
)
PADRAO_SALDO_FINAL = re.compile(
    r'Saldo final dispon[ií]vel\s+R\$\s*([\d.]+,\d{2})',
    re.IGNORECASE,
)
PADRAO_CNPJ_TITULAR = re.compile(r'^CNPJ\s+[\d./\-]+$', re.IGNORECASE)


def extrair_texto_pdf(caminho_pdf):
    linhas = []
    with pdfplumber.open(caminho_pdf) as pdf:
        for page in pdf.pages:
            texto = page.extract_text()
            if texto:
                linhas.extend(texto.split('\n'))
    return linhas


def parsear_valor_brl(valor_str):
    return float(valor_str.replace('.', '').replace(',', '.'))


def deve_ignorar_linha(texto):
    lower = texto.lower().strip()
    if not lower:
        return True
    if PADRAO_CNPJ_TITULAR.match(texto.strip()):
        return True
    if re.search(r'p[áa]g\s+\d+\s+de\s+\d+', lower):
        return True
    return any(marca in lower for marca in LINHAS_IGNORAR)


def extrair_dados_conta(linhas):
    titular = ''
    agencia = ''
    numero_conta = ''

    for linha in linhas[:20]:
        texto = linha.strip()
        if not texto:
            continue

        match = PADRAO_CONTA.search(texto)
        if match:
            agencia = match.group(1).strip()
            numero_conta = match.group(2).strip()
            continue

        if (
            not titular
            and not deve_ignorar_linha(texto)
            and not PADRAO_LANCAMENTO.match(texto)
            and not PADRAO_SALDO_DIA.match(texto)
        ):
            titular = texto

    acct_id = re.sub(r'\D', '', numero_conta) if numero_conta else '00000000'

    return {
        'titular': titular,
        'cooperativa': agencia,
        'branch_id': agencia,
        'numero_conta': numero_conta,
        'acct_id': acct_id or '00000000',
    }


def extrair_periodo(linhas):
    for linha in linhas[:20]:
        match = PADRAO_PERIODO.search(linha)
        if match:
            return match.group(1), match.group(2)
    return None, None


def extrair_saldo_final(linhas):
    for linha in linhas[:30]:
        match = PADRAO_SALDO_FINAL.search(linha.strip())
        if match:
            return parsear_valor_brl(match.group(1))
    return None


def montar_memo(descricao):
    memo = re.sub(r'\s+', ' ', (descricao or '').strip())
    return memo.replace('…', '...')


def parsear_lancamentos(linhas, titular='', numero_conta=''):
    lancamentos = []
    data_corrente = None
    titular_norm = re.sub(r'\s+', ' ', (titular or '').strip().lower())
    conta_norm = (numero_conta or '').strip()

    for linha in linhas:
        texto = linha.strip()
        if not texto:
            continue

        match_dia = PADRAO_SALDO_DIA.match(texto)
        if match_dia:
            data_corrente = match_dia.group(1)
            continue

        if titular_norm and re.sub(r'\s+', ' ', texto.lower()) == titular_norm:
            continue
        if conta_norm and texto == conta_norm:
            continue
        if PADRAO_CONTA.search(texto):
            continue

        if deve_ignorar_linha(texto):
            continue

        match = PADRAO_LANCAMENTO.match(texto)
        if not match:
            continue

        if not data_corrente:
            continue

        descricao = montar_memo(match.group(1))
        if not descricao or eh_descricao_saldo(descricao):
            continue

        sinal = match.group(2)
        valor = parsear_valor_brl(match.group(3))
        if sinal == '-':
            valor = -abs(valor)
        else:
            valor = abs(valor)

        lancamentos.append({
            'data': data_corrente,
            'valor': valor,
            'memo': descricao,
        })

    return lancamentos


def converter_pdf_cora_para_ofx(caminho_pdf, caminho_ofx, caminho_preview=None):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta(linhas)
    periodo_inicio, periodo_fim = extrair_periodo(linhas)
    lancamentos = parsear_lancamentos(
        linhas,
        titular=dados_conta.get('titular', ''),
        numero_conta=dados_conta.get('numero_conta', ''),
    )

    if not lancamentos:
        raise ValueError('Nenhum lançamento válido encontrado no PDF')

    lancamentos_ordenados = sorted(
        reversed(lancamentos),
        key=lambda item: datetime.strptime(item['data'], '%d/%m/%Y'),
    )

    transacoes = [{
        'data': item['data'],
        'valor': item['valor'],
        'memo': item['memo'],
    } for item in lancamentos_ordenados]

    saldo_final = extrair_saldo_final(linhas)
    if saldo_final is None:
        saldo_final = sum(item['valor'] for item in transacoes)

    dtstart = None
    if periodo_inicio:
        inicio = datetime.strptime(periodo_inicio, '%d/%m/%Y')
        dtstart = (inicio - timedelta(days=1)).strftime('%d/%m/%Y')

    data_inicial = dtstart or transacoes[0]['data']
    data_final = periodo_fim or transacoes[-1]['data']

    gerar_arquivo_ofx(
        transacoes=transacoes,
        output_path=caminho_ofx,
        bank_id='403',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Cora',
        fi_id='403',
    )

    preview = gravar_preview_json(transacoes, caminho_preview)

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
        print(
            'Uso: python conversor_extrato_cora_pdf_ofx.py '
            '<arquivo.pdf> <arquivo_saida.ofx> [preview.json]'
        )
        sys.exit(1)

    caminho_pdf = sys.argv[1]
    caminho_ofx = sys.argv[2]
    caminho_preview = sys.argv[3] if len(sys.argv) > 3 else None

    if not os.path.exists(caminho_pdf):
        print(f"Erro: O arquivo '{caminho_pdf}' não existe.")
        sys.exit(1)

    try:
        resultado = converter_pdf_cora_para_ofx(caminho_pdf, caminho_ofx, caminho_preview)
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
        print(f"Arquivo OFX gerado em: {caminho_ofx}")
    except Exception as erro:
        print(f'Erro na conversão: {erro}')
        sys.exit(1)


if __name__ == '__main__':
    main()
