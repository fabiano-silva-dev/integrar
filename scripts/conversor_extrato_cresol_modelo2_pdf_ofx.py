#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extrato PDF Cresol (extrato de conta corrente) para OFX."""

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
    'extrato de conta corrente',
    'consulta posição consolidada',
    'consulta posicao consolidada',
    'lançamentos',
    'lancamentos',
    'totalizadores',
    'saldo bloqueado',
    'saldo bloqueio',
    'débitos pendentes',
    'debitos pendentes',
    'limite de crédito',
    'limite de credito',
    'cheque especial',
    'custo efetivo total',
    'juros de adiantamento',
    'juros acumulados',
    'página',
    'pagina',
)


def extrair_texto_pdf(caminho_pdf):
    linhas = []
    with pdfplumber.open(caminho_pdf) as pdf:
        for page in pdf.pages:
            texto = page.extract_text()
            if texto:
                linhas.extend(texto.split('\n'))
    return linhas


def deve_ignorar_linha(linha):
    texto = linha.strip().lower()
    if not texto:
        return True
    if re.match(r'^r\$\s*[\d.]+,\d{2}\s+r\$\s*[\d.]+,\d{2}', texto):
        return True
    if texto.startswith('saldo do dia'):
        return True
    if texto.startswith('saldo anterior'):
        return True
    return any(marca in texto for marca in LINHAS_IGNORAR)


def parsear_valor_brl(valor_str):
    return float(valor_str.replace('.', '').replace(',', '.'))


def valor_assinado(valor_str, sinal):
    valor = parsear_valor_brl(valor_str)
    return -valor if sinal == '-' else valor


def extrair_dados_conta(linhas):
    agencia = ''
    numero_conta = ''
    titular = ''

    for indice, linha in enumerate(linhas[:25]):
        texto = linha.strip()

        match = re.search(
            r'Ag[eê]ncia:\s*(\d+)\s+Conta:\s*([\d.\-]+)',
            texto,
            re.IGNORECASE,
        )
        if match:
            agencia = match.group(1).strip()
            numero_conta = match.group(2).strip()
            if indice > 0:
                candidato = linhas[indice - 1].strip()
                if candidato and not re.search(r'ag[eê]ncia:', candidato, re.IGNORECASE):
                    titular = candidato
            break

    acct_id = re.sub(r'\D', '', numero_conta) if numero_conta else '00000000'

    return {
        'titular': titular,
        'cooperativa': agencia,
        'branch_id': agencia,
        'numero_conta': numero_conta,
        'acct_id': acct_id or '00000000',
    }


def extrair_periodo(linhas):
    for linha in linhas[:30]:
        match = re.search(
            r'Per[ií]odo de\s+(\d{2}/\d{2}/\d{4})\s+a\s+(\d{2}/\d{2}/\d{4})',
            linha,
            re.IGNORECASE,
        )
        if match:
            return match.group(1), match.group(2)
    return None, None


def parsear_lancamentos(linhas, periodo_inicio=None, periodo_fim=None):
    lancamentos = []
    data_corrente = None

    padrao_data = re.compile(r'^(\d{2}/\d{2}/\d{4})$')
    padrao_lancamento = re.compile(
        r'^(.+?)\s+([+-])\s*R\$\s*([\d.]+,\d{2})\s*$',
        re.IGNORECASE,
    )

    for linha in linhas:
        texto = linha.strip()
        if not texto:
            continue

        if deve_ignorar_linha(texto):
            continue

        match_data = padrao_data.match(texto)
        if match_data:
            data_corrente = match_data.group(1)
            continue

        match = padrao_lancamento.match(texto)
        if not match or not data_corrente:
            continue

        descricao = match.group(1).strip()
        if eh_descricao_saldo(descricao):
            continue

        data = data_corrente
        if periodo_inicio or periodo_fim:
            data_dt = datetime.strptime(data, '%d/%m/%Y')
            if periodo_inicio and data_dt < datetime.strptime(periodo_inicio, '%d/%m/%Y'):
                continue
            if periodo_fim and data_dt > datetime.strptime(periodo_fim, '%d/%m/%Y'):
                continue

        valor = valor_assinado(match.group(3), match.group(2))
        lancamentos.append({
            'data': data,
            'valor': valor,
            'memo': descricao,
        })

    return lancamentos


def extrair_saldo_final(linhas):
    for linha in linhas:
        match = re.search(
            r'Saldo da Conta Corrente\s+([+-])\s*R\$\s*([\d.]+,\d{2})',
            linha.strip(),
            re.IGNORECASE,
        )
        if match:
            return valor_assinado(match.group(2), match.group(1))
    return None


def converter_pdf_cresol_modelo2_para_ofx(caminho_pdf, caminho_ofx, caminho_preview=None):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta(linhas)
    periodo_inicio, periodo_fim = extrair_periodo(linhas)
    lancamentos = parsear_lancamentos(linhas, periodo_inicio, periodo_fim)

    if not lancamentos:
        raise ValueError('Nenhum lançamento válido encontrado no PDF')

    lancamentos_ordenados = sorted(
        lancamentos,
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
        bank_id='133',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Cresol',
        fi_id='133',
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
        print('Uso: python conversor_extrato_cresol_modelo2_pdf_ofx.py <arquivo.pdf> <arquivo_saida.ofx> [preview.json]')
        sys.exit(1)

    caminho_pdf = sys.argv[1]
    caminho_ofx = sys.argv[2]
    caminho_preview = sys.argv[3] if len(sys.argv) > 3 else None

    if not os.path.exists(caminho_pdf):
        print(f"Erro: O arquivo '{caminho_pdf}' não existe.")
        sys.exit(1)

    try:
        resultado = converter_pdf_cresol_modelo2_para_ofx(caminho_pdf, caminho_ofx, caminho_preview)
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
