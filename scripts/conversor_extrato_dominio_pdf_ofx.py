#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extrato PDF da Domínio Conta Digital para OFX."""

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
    'precisa de ajuda',
    'domínio serviços financeiros',
    'dominio servicos financeiros',
    'sac.digital@',
    'data lançamento valor saldo',
    'data lancamento valor saldo',
)

PADRAO_LANCAMENTO = re.compile(
    r'^(\d{2}/\d{2}/\d{4})\s+(.+?)\s+([+-])R\$\s*([\d.]+,\d{2})(?:\s+-)?\s*$'
)
PADRAO_SALDO_DIARIO = re.compile(
    r'^(\d{2}/\d{2}/\d{4})\s+SALDO\s+DI[AÁ]RIO\b.*R\$\s*([\d.]+,\d{2})\s*$',
    re.IGNORECASE,
)
PADRAO_PERIODO = re.compile(
    r'Per[ií]odo:\s*(\d{2}/\d{2}/\d{4})\s+a\s+(\d{2}/\d{2}/\d{4})',
    re.IGNORECASE,
)
PADRAO_CONTA = re.compile(
    r'Ag[eê]ncia\s+(\d+)\s+Conta\s+([\d.\-]+)',
    re.IGNORECASE,
)


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
    if re.match(r'^0800[\d\-]+', lower):
        return True
    if lower in ('extratos',):
        return True
    return any(marca in lower for marca in LINHAS_IGNORAR)


def extrair_dados_conta(linhas):
    titular = ''
    agencia = ''
    numero_conta = ''

    for linha in linhas[:15]:
        texto = linha.strip()
        if not texto or deve_ignorar_linha(texto):
            continue

        match = PADRAO_CONTA.search(texto)
        if match:
            agencia = match.group(1).strip()
            numero_conta = match.group(2).strip()
            continue

        if (
            not titular
            and not PADRAO_PERIODO.search(texto)
            and not PADRAO_LANCAMENTO.match(texto)
            and not PADRAO_SALDO_DIARIO.match(texto)
            and not texto.lower().startswith('período')
            and not texto.lower().startswith('periodo')
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
    for linha in linhas:
        match = PADRAO_SALDO_DIARIO.match(linha.strip())
        if match:
            return parsear_valor_brl(match.group(2))
    return None


def parsear_lancamentos(linhas):
    lancamentos = []

    for linha in linhas:
        texto = linha.strip()
        if not texto or deve_ignorar_linha(texto):
            continue

        if PADRAO_SALDO_DIARIO.match(texto):
            continue

        match = PADRAO_LANCAMENTO.match(texto)
        if not match:
            continue

        descricao = re.sub(r'\s+', ' ', match.group(2)).strip()
        if not descricao or eh_descricao_saldo(descricao):
            continue

        sinal = match.group(3)
        valor = parsear_valor_brl(match.group(4))
        if sinal == '-':
            valor = -valor

        lancamentos.append({
            'data': match.group(1),
            'valor': valor,
            'memo': descricao,
        })

    return lancamentos


def converter_pdf_dominio_para_ofx(caminho_pdf, caminho_ofx, caminho_preview=None):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta(linhas)
    periodo_inicio, periodo_fim = extrair_periodo(linhas)
    lancamentos = parsear_lancamentos(linhas)

    if not lancamentos:
        raise ValueError('Nenhum lançamento válido encontrado no PDF')

    # O PDF vem do mais recente para o mais antigo; inverte para manter a ordem do dia.
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
        bank_id='000',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Dominio Conta Digital',
        fi_id='000',
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
            'Uso: python conversor_extrato_dominio_pdf_ofx.py '
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
        resultado = converter_pdf_dominio_para_ofx(caminho_pdf, caminho_ofx, caminho_preview)
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
