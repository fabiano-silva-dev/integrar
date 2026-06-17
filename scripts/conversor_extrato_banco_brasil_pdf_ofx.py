#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extrato PDF Banco do Brasil (autoatendimento) para OFX."""

import os
import re
import sys
from datetime import datetime, timedelta
from calendar import monthrange

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

try:
    import pdfplumber
except ImportError:
    print('Erro: pdfplumber não encontrado.')
    sys.exit(1)

from gerador_ofx import gerar_arquivo_ofx  # noqa: E402
from extrato_util import eh_descricao_saldo  # noqa: E402

LINHAS_IGNORAR = (
    'banco do brasil',
    'visualizar pix',
    'consultas - extrato',
    'cliente - conta atual',
    'lançamentos',
    'dt. dt.',
    'ag. origem lote',
    'período do',
    'extrato',
    'rende facil',
    'transação efetuada',
    'https://',
    'autoatendimento.bb.com.br',
    '---',
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
    if texto.startswith('g') and texto[1:].isdigit():
        return True
    return any(marca in texto for marca in LINHAS_IGNORAR)


def parsear_valor_brl(valor_str):
    return float(valor_str.replace('.', '').replace(',', '.'))


def valor_assinado(valor_str, tipo):
    valor = parsear_valor_brl(valor_str)
    return -valor if tipo.upper() == 'D' else valor


def extrair_dados_conta_bb(linhas):
    agencia = ''
    numero_conta = ''
    titular = ''

    for linha in linhas[:25]:
        texto = linha.strip()
        match_ag = re.search(r'Ag[eê]ncia\s+([\d\-]+)', texto, re.IGNORECASE)
        if match_ag:
            agencia = match_ag.group(1).strip()

        match_conta = re.search(
            r'Conta corrente\s+([\d\-]+)\s+(.+)$',
            texto,
            re.IGNORECASE,
        )
        if match_conta:
            numero_conta = match_conta.group(1).strip()
            titular = match_conta.group(2).strip()

    acct_id = numero_conta if numero_conta else '00000000'

    return {
        'titular': titular,
        'cooperativa': agencia,
        'branch_id': agencia,
        'numero_conta': numero_conta,
        'acct_id': acct_id,
    }


def extrair_periodo_bb(linhas):
    mes = None
    ano = None

    for indice, linha in enumerate(linhas[:25]):
        texto = linha.strip()
        if 'período do' in texto.lower() or 'periodo do' in texto.lower():
            for proxima in linhas[indice + 1:indice + 4]:
                match = re.match(r'^(\d{2})\s*/\s*(\d{4})$', proxima.strip())
                if match:
                    mes = int(match.group(1))
                    ano = int(match.group(2))
                    break
            if mes and ano:
                break

        match = re.match(r'^(\d{2})\s*/\s*(\d{4})$', texto)
        if match:
            mes = int(match.group(1))
            ano = int(match.group(2))
            break

    if not mes or not ano:
        return None, None

    ultimo_dia = monthrange(ano, mes)[1]
    return f'01/{mes:02d}/{ano}', f'{ultimo_dia:02d}/{mes:02d}/{ano}'


def separar_descricao_documento(meio):
    meio = meio.strip()
    if not meio:
        return '', ''

    match_doc = re.search(r'([\d.]{3,})$', meio)
    if match_doc:
        documento = match_doc.group(1)
        descricao = meio[:match_doc.start()].strip()
        return descricao, documento

    return meio, ''


def parsear_linha_transacao(linha):
    match_fim = re.search(
        r'([\d.]+,\d{2})\s+([CD])(?:\s+([\d.]+,\d{2})\s+([CD]))?\s*$',
        linha,
        re.IGNORECASE,
    )
    if not match_fim:
        return None

    valor_str, tipo_valor, saldo_str, tipo_saldo = match_fim.groups()
    prefixo = linha[:match_fim.start()].strip()

    match_inicio = re.match(
        r'^(\d{2}/\d{2}/\d{4})\s+(\d{4})\s+(\d{5})\s+(\d{3})\s+(.+)$',
        prefixo,
    )
    if not match_inicio:
        return None

    data, agencia, lote, historico, meio = match_inicio.groups()
    descricao, documento = separar_descricao_documento(meio)

    saldo = None
    if saldo_str and tipo_saldo:
        saldo = valor_assinado(saldo_str, tipo_saldo)

    return {
        'data': data,
        'descricao': descricao,
        'documento': documento,
        'valor': valor_assinado(valor_str, tipo_valor),
        'saldo': saldo,
        'tipo_valor': tipo_valor.upper(),
    }


def eh_saldo_final(descricao):
    return eh_descricao_saldo(descricao)


def eh_saldo_anterior(descricao):
    return eh_descricao_saldo(descricao)


def montar_memo(item, complemento=''):
    partes = [item['descricao']]
    if item.get('documento'):
        partes.append(f"DOC: {item['documento']}")
    if complemento:
        partes.append(complemento)
    return ' '.join(partes).strip()


def parsear_lancamentos(linhas):
    lancamentos = []
    saldo_final = None
    ultimo_lancamento = None

    for linha in linhas:
        texto = linha.strip()
        if deve_ignorar_linha(texto):
            continue

        transacao = parsear_linha_transacao(texto)
        if transacao:
            if eh_saldo_anterior(transacao['descricao']):
                ultimo_lancamento = None
                continue

            if eh_saldo_final(transacao['descricao']):
                saldo_final = transacao['valor']
                ultimo_lancamento = None
                continue

            lancamentos.append({
                'data': transacao['data'],
                'valor': transacao['valor'],
                'memo': montar_memo(transacao),
            })
            ultimo_lancamento = lancamentos[-1]
            if transacao['saldo'] is not None:
                saldo_final = transacao['saldo']
            continue

        if ultimo_lancamento and texto:
            if re.match(r'^\d{2}/\d{2}\s+\d{2}:\d{2}\s+', texto):
                ultimo_lancamento['memo'] += f' {texto}'
            elif not re.match(r'^\d{2}/\d{2}/\d{4}\s+\d{4}\s+', texto):
                ultimo_lancamento['memo'] += f' {texto}'

    return lancamentos, saldo_final


def converter_pdf_banco_brasil_para_ofx(caminho_pdf, caminho_ofx):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta_bb(linhas)
    periodo_inicio, periodo_fim = extrair_periodo_bb(linhas)

    lancamentos, saldo_final = parsear_lancamentos(linhas)
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
        bank_id='001',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Banco do Brasil',
        fi_id='001',
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
        print('Uso: python conversor_extrato_banco_brasil_pdf_ofx.py <arquivo.pdf> <arquivo_saida.ofx>')
        sys.exit(1)

    caminho_pdf = sys.argv[1]
    caminho_ofx = sys.argv[2]

    if not os.path.exists(caminho_pdf):
        print(f"Erro: O arquivo '{caminho_pdf}' não existe.")
        sys.exit(1)

    try:
        resultado = converter_pdf_banco_brasil_para_ofx(caminho_pdf, caminho_ofx)
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
