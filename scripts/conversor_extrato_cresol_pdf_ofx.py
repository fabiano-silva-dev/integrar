#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extrato PDF Cresol para OFX."""

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

LINHAS_IGNORAR = (
    'cresol central brasil',
    'extrato consolidado',
    'segundo titular',
    'conta integração',
    'conta integracao',
    'data movimento',
    'data/hora:',
    'julianabonald',
    'página',
    'pagina',
    'https://',
    'juros calculados',
    'juros sobre limite',
    'cet-custo',
    'o valor das parcelas',
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
    if texto.startswith('(=') or texto.startswith('(+') or texto.startswith('(-'):
        return True
    if re.match(r'^[a-z]+\s*\|\s*\d{2}/\d{2}/\d{4}', texto):
        return True
    return any(marca in texto for marca in LINHAS_IGNORAR)


def parsear_valor_brl(valor_str):
    return float(valor_str.replace('.', '').replace(',', '.'))


def valor_assinado(valor_str, tipo):
    valor = parsear_valor_brl(valor_str)
    return -valor if tipo.upper() == 'D' else valor


def extrair_dados_conta_cresol(linhas):
    agencia = ''
    numero_conta = ''
    titular = ''

    for linha in linhas[:40]:
        texto = linha.strip()

        match_ag = re.search(
            r'Ag[eê]ncia:\s*(\d{4}\s*-\s*\d+)',
            texto,
            re.IGNORECASE,
        )
        if match_ag:
            agencia = re.sub(r'\s+', '', match_ag.group(1))

        match_conta = re.search(
            r'Conta:\s*([\d.\-]+)\s*-\s*(.+)$',
            texto,
            re.IGNORECASE,
        )
        if match_conta:
            numero_conta = match_conta.group(1).strip()
            titular = match_conta.group(2).strip().rstrip('.')

    acct_id = numero_conta.replace('.', '') if numero_conta else '00000000'

    return {
        'titular': titular,
        'cooperativa': agencia,
        'branch_id': agencia,
        'numero_conta': numero_conta,
        'acct_id': acct_id,
    }


def extrair_periodo_cresol(linhas):
    for linha in linhas[:40]:
        match = re.search(
            r'(\d{2}/\d{2}/\d{4})\s+a\s+(\d{2}/\d{2}/\d{4})',
            linha,
            re.IGNORECASE,
        )
        if match:
            return match.group(1), match.group(2)
    return None, None


def separar_descricao_identificacao(meio):
    meio = meio.strip()
    if not meio:
        return '', ''

    match_id = re.search(r'([\d][\w.\-]{4,})$', meio)
    if match_id:
        identificacao = match_id.group(1)
        descricao = meio[:match_id.start()].strip()
        return descricao, identificacao

    return meio, ''


def montar_memo(descricao, identificacao=''):
    if identificacao:
        return f'{descricao} DOC: {identificacao}'.strip()
    return descricao.strip()


def parsear_lancamentos(linhas, periodo_inicio=None, periodo_fim=None):
    lancamentos = []
    saldo_corrente = None
    em_pendentes = False

    padrao_linha = re.compile(
        r'^(\d{2}/\d{2}/\d{4})\s+(.+?)\s+([\d.]+,\d{2})\s+([CD])\s*$',
        re.IGNORECASE,
    )

    for linha in linhas:
        texto = linha.strip()
        if not texto:
            continue

        if 'LANCAMENTOS FUTUROS/PENDENTES' in texto.upper():
            em_pendentes = True
            continue

        if em_pendentes or deve_ignorar_linha(texto):
            continue

        match = padrao_linha.match(texto)
        if not match:
            continue

        data = match.group(1)
        meio = match.group(2).strip()
        valor_str = match.group(3)
        tipo = match.group(4).upper()

        if periodo_inicio or periodo_fim:
            data_dt = datetime.strptime(data, '%d/%m/%Y')
            if periodo_inicio and data_dt < datetime.strptime(periodo_inicio, '%d/%m/%Y'):
                continue
            if periodo_fim and data_dt > datetime.strptime(periodo_fim, '%d/%m/%Y'):
                continue

        descricao, identificacao = separar_descricao_identificacao(meio)

        if re.search(r'SALDO\s+ANTERIOR', descricao, re.IGNORECASE):
            saldo_corrente = valor_assinado(valor_str, tipo)
            continue

        valor = valor_assinado(valor_str, tipo)
        lancamentos.append({
            'data': data,
            'valor': valor,
            'memo': montar_memo(descricao, identificacao),
        })

        if saldo_corrente is not None:
            saldo_corrente += valor

    return lancamentos, saldo_corrente


def extrair_saldo_resumo(linhas):
    for linha in linhas:
        match = re.search(
            r'\(=\)SALDO:\s*([\d.]+,\d{2})\s+([CD])',
            linha.strip(),
            re.IGNORECASE,
        )
        if match:
            return valor_assinado(match.group(1), match.group(2))
    return None


def converter_pdf_cresol_para_ofx(caminho_pdf, caminho_ofx):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta_cresol(linhas)
    periodo_inicio, periodo_fim = extrair_periodo_cresol(linhas)

    lancamentos, saldo_calculado = parsear_lancamentos(linhas, periodo_inicio, periodo_fim)
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

    saldo_final = extrair_saldo_resumo(linhas)
    if saldo_final is None:
        if saldo_calculado is not None:
            saldo_final = saldo_calculado
        else:
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
        print('Uso: python conversor_extrato_cresol_pdf_ofx.py <arquivo.pdf> <arquivo_saida.ofx>')
        sys.exit(1)

    caminho_pdf = sys.argv[1]
    caminho_ofx = sys.argv[2]

    if not os.path.exists(caminho_pdf):
        print(f"Erro: O arquivo '{caminho_pdf}' não existe.")
        sys.exit(1)

    try:
        resultado = converter_pdf_cresol_para_ofx(caminho_pdf, caminho_ofx)
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
