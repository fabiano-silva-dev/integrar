#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extrato PDF InfinitePay (CloudWalk) para OFX."""

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
from extrato_util import gravar_preview_json  # noqa: E402

MESES = {
    'JAN': 1,
    'FEV': 2,
    'MAR': 3,
    'ABR': 4,
    'MAI': 5,
    'JUN': 6,
    'JUL': 7,
    'AGO': 8,
    'SET': 9,
    'OUT': 10,
    'NOV': 11,
    'DEZ': 12,
}

LINHAS_IGNORAR = (
    'relatório de movimentações',
    'relatorio de movimentacoes',
    'data hora tipo de transação',
    'data hora tipo de transacao',
    'a central de ajuda',
    'saldo final do período',
    'saldo final do periodo',
    'saldo inicial',
    'total de entradas',
    'total de saídas',
    'total de saidas',
    'saldo do dia',
    'valor em r$',
)

PADRAO_LANCAMENTO = re.compile(
    r'^(?:(\d{2})\s+([A-Za-z]{3}),\s+(\d{4})\s+)?'
    r'(\d{2}:\d{2})\s+'
    r'(.+?)\s+'
    r'([+-][\d.]+,\d{2})\s*$'
)
PADRAO_PERIODO = re.compile(
    r'(\d{2})\s+([A-Za-z]{3}),\s+(\d{4})\s*-\s*(\d{2})\s+([A-Za-z]{3}),\s+(\d{4})',
    re.IGNORECASE,
)
PADRAO_CONTA = re.compile(
    r'CLOUDWALK\s*-\s*(\d+)\s*-\s*([\d.\-]+)',
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
    sinal = -1 if valor_str.strip().startswith('-') else 1
    numero = valor_str.strip().lstrip('+-').strip()
    return sinal * float(numero.replace('.', '').replace(',', '.'))


def data_de_mes_abreviado(dia, mes_abrev, ano):
    mes = MESES.get(mes_abrev.upper()[:3])
    if not mes:
        return None
    return datetime(int(ano), mes, int(dia)).strftime('%d/%m/%Y')


def deve_ignorar_linha(texto):
    lower = texto.lower().strip()
    if not lower:
        return True
    if re.match(r'^r\$\s*[\d.]+,\d{2}', lower):
        return True
    if re.match(r'^página\s+\d+\s+de\s+\d+', lower) or re.search(r'página\s+\d+\s+de\s+\d+', lower):
        return True
    return any(marca in lower for marca in LINHAS_IGNORAR)


def extrair_dados_conta(linhas):
    titular = ''
    agencia = ''
    numero_conta = ''

    for linha in linhas[:15]:
        texto = linha.strip()
        if not titular and 'CNPJ' in texto.upper():
            titular = re.split(r'\s*-\s*CNPJ', texto, flags=re.IGNORECASE)[0].strip()

        match = PADRAO_CONTA.search(texto)
        if match:
            agencia = match.group(1).strip()
            numero_conta = match.group(2).strip()
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
    for linha in linhas[:20]:
        match = PADRAO_PERIODO.search(linha)
        if match:
            inicio = data_de_mes_abreviado(match.group(1), match.group(2), match.group(3))
            fim = data_de_mes_abreviado(match.group(4), match.group(5), match.group(6))
            if inicio and fim:
                return inicio, fim
    return None, None


def extrair_saldo_final(linhas):
    for linha in linhas[:30]:
        match = re.search(
            r'Saldo final do per[ií]odo\s+[+-]?\s*([\d.]+,\d{2})',
            linha.strip(),
            re.IGNORECASE,
        )
        if match:
            return parsear_valor_brl(match.group(1))

    for indice, linha in enumerate(linhas[:30]):
        if 'Saldo final do período' in linha or 'Saldo final do periodo' in linha.lower():
            if indice + 1 < len(linhas):
                match = re.search(r'R\$\s*([\d.]+,\d{2})', linhas[indice + 1])
                if match:
                    return parsear_valor_brl(match.group(1))
    return None


def montar_memo(meio):
    memo = re.sub(r'\s+', ' ', (meio or '').strip())
    # Remove duplicação "Pix Pix Nome" -> "Pix Nome"
    memo = re.sub(r'^Pix\s+Pix\s+', 'Pix ', memo, flags=re.IGNORECASE)
    return memo


def parsear_lancamentos(linhas):
    lancamentos = []
    data_corrente = None

    for linha in linhas:
        texto = linha.strip()
        if not texto or deve_ignorar_linha(texto):
            continue

        match = PADRAO_LANCAMENTO.match(texto)
        if not match:
            continue

        if match.group(1):
            data = data_de_mes_abreviado(match.group(1), match.group(2), match.group(3))
            if data:
                data_corrente = data

        if not data_corrente:
            continue

        hora = match.group(4)
        meio = match.group(5).strip()
        valor = parsear_valor_brl(match.group(6))
        memo = montar_memo(meio)
        if hora:
            memo = f'{memo} {hora}'.strip()

        lancamentos.append({
            'data': data_corrente,
            'hora': hora,
            'valor': valor,
            'memo': memo,
        })

    return lancamentos


def converter_pdf_infinitepay_para_ofx(caminho_pdf, caminho_ofx, caminho_preview=None):
    linhas = extrair_texto_pdf(caminho_pdf)
    if not linhas:
        raise ValueError('Não foi possível extrair texto do PDF')

    dados_conta = extrair_dados_conta(linhas)
    periodo_inicio, periodo_fim = extrair_periodo(linhas)
    lancamentos = parsear_lancamentos(linhas)

    if not lancamentos:
        raise ValueError('Nenhum lançamento válido encontrado no PDF')

    lancamentos_ordenados = sorted(
        lancamentos,
        key=lambda item: (
            datetime.strptime(item['data'], '%d/%m/%Y'),
            item.get('hora') or '00:00',
        ),
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
        bank_id='542',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='InfinitePay',
        fi_id='542',
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
            'Uso: python conversor_extrato_infinitepay_pdf_ofx.py '
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
        resultado = converter_pdf_infinitepay_para_ofx(caminho_pdf, caminho_ofx, caminho_preview)
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
