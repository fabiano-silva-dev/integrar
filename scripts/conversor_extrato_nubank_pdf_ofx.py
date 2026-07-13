#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Converte extrato PDF Nubank (Nu Pagamentos) para OFX."""

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
    'JANEIRO': 1,
    'FEVEREIRO': 2,
    'MARÇO': 3,
    'MARCO': 3,
    'ABRIL': 4,
    'MAIO': 5,
    'JUNHO': 6,
    'JULHO': 7,
    'AGOSTO': 8,
    'SETEMBRO': 9,
    'OUTUBRO': 10,
    'NOVEMBRO': 11,
    'DEZEMBRO': 12,
}

LINHAS_IGNORAR = (
    'tem alguma dúvida',
    'caso a solução',
    'extrato gerado',
    'valores em r$',
    'movimentações',
    'o saldo líquido',
    'não nos responsabilizamos',
    'asseguramos a autenticidade',
    'nu financeira',
    'disponíveis em nubank.com.br',
    'saldo inicial',
    'rendimento líquido',
    'total de entradas',
    'total de saídas',
    'saldo final do período',
    'saldo do dia',
    'metropolitanas) ou',
    'e investimento',
)

PREFIXOS_CREDITO = (
    'transferência recebida',
    'transferencia recebida',
    'reembolso recebido',
    'resgate rdb',
)

PREFIXOS_DEBITO = (
    'transferência enviada',
    'transferencia enviada',
    'pagamento de boleto',
    'pagamento de fatura',
    'aplicação rdb',
    'aplicacao rdb',
)

PADRAO_DIA = re.compile(
    r'^(\d{2})\s+([A-ZÁÉÍÓÚÂÊÔÃÕÇ]{3})\s+(\d{4})\s+Total de\s+(entradas|saídas|saidas)',
    re.IGNORECASE,
)
PADRAO_VALOR_FINAL = re.compile(r'^(.*?)(?:\s+-\s+|\s+)([+-]?[\d.]+,\d{2})\s*$')
PADRAO_VALOR_SIMPLES = re.compile(r'^([\d.]+,\d{2})$')


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
    if lower.startswith('nu pagamentos s.a'):
        return True
    if re.match(r'^cnpj:\s*[\d./\-]+$', lower):
        return True
    if re.search(r'cnpj\s+[\d./\-]+\s+ag[eê]ncia\s+\d+\s+conta', lower):
        return True
    if re.search(r'\d{1,2}\s+de\s+\w+\s+de\s+\d{4}\s+a\s+\d{1,2}\s+de\s+\w+\s+de\s+\d{4}', lower):
        return True
    if re.match(r'^r\$\s*[\d.]+,\d{2}', lower):
        return True
    return any(marca in lower for marca in LINHAS_IGNORAR)


def tipo_lancamento(texto):
    lower = texto.lower().strip()
    for prefixo in PREFIXOS_CREDITO:
        if lower.startswith(prefixo):
            return 'C'
    for prefixo in PREFIXOS_DEBITO:
        if lower.startswith(prefixo):
            return 'D'
    return None


def eh_inicio_lancamento(texto):
    return tipo_lancamento(texto) is not None


def data_de_mes_abreviado(dia, mes_abrev, ano):
    mes = MESES.get(mes_abrev.upper())
    if not mes:
        return None
    return datetime(int(ano), mes, int(dia)).strftime('%d/%m/%Y')


def data_por_extenso(dia, mes_nome, ano):
    mes = MESES.get(mes_nome.upper())
    if not mes:
        return None
    return datetime(int(ano), mes, int(dia)).strftime('%d/%m/%Y')


def extrair_dados_conta(linhas):
    titular = ''
    agencia = ''
    numero_conta = ''

    for indice, linha in enumerate(linhas[:20]):
        texto = linha.strip()
        if not titular and texto and not texto.upper().startswith('CNPJ'):
            if 'AGÊNCIA' not in texto.upper() and 'AGENCIA' not in texto.upper():
                titular = texto

        match = re.search(
            r'Ag[eê]ncia\s+(\d+)\s+Conta',
            texto,
            re.IGNORECASE,
        )
        if match:
            agencia = match.group(1).strip()
            if indice + 1 < len(linhas):
                candidata = linhas[indice + 1].strip()
                if re.match(r'^[\d.\-]+$', candidata):
                    numero_conta = candidata
            break

        match_mesma = re.search(
            r'Ag[eê]ncia\s+(\d+)\s+Conta\s+([\d.\-]+)',
            texto,
            re.IGNORECASE,
        )
        if match_mesma:
            agencia = match_mesma.group(1).strip()
            numero_conta = match_mesma.group(2).strip()
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
            r'(\d{1,2})\s+DE\s+(\w+)\s+DE\s+(\d{4})\s+a\s+(\d{1,2})\s+DE\s+(\w+)\s+DE\s+(\d{4})',
            linha,
            re.IGNORECASE,
        )
        if match:
            inicio = data_por_extenso(match.group(1), match.group(2), match.group(3))
            fim = data_por_extenso(match.group(4), match.group(5), match.group(6))
            if inicio and fim:
                return inicio, fim
    return None, None


def extrair_saldo_final(linhas):
    for linha in linhas[:40]:
        match = re.search(
            r'Saldo final do per[ií]odo\s+([\d.]+,\d{2})',
            linha.strip(),
            re.IGNORECASE,
        )
        if match:
            return parsear_valor_brl(match.group(1))

    for indice, linha in enumerate(linhas[:40]):
        if 'Saldo final do período' in linha or 'Saldo final do periodo' in linha.lower():
            if indice + 1 < len(linhas):
                match = re.search(r'R\$\s*([\d.]+,\d{2})', linhas[indice + 1])
                if match:
                    return parsear_valor_brl(match.group(1))
    return None


def extrair_valor_da_linha(texto):
    match = PADRAO_VALOR_FINAL.match(texto)
    if not match:
        return None, texto

    resto = match.group(1).strip()
    valor_str = match.group(2).strip()

    # Evita capturar CNPJ/CPF como valor (ex.: 0001-06 no meio da linha).
    if not resto and not eh_inicio_lancamento(texto):
        return None, texto

    return valor_str, resto


def parsear_lancamentos(linhas, titular='', numero_conta=''):
    lancamentos = []
    data_corrente = None
    pendente = None
    titular_norm = re.sub(r'\s+', ' ', (titular or '').strip().lower())
    conta_norm = (numero_conta or '').strip()

    def finalizar_pendente():
        nonlocal pendente
        if not pendente:
            return
        if pendente.get('valor') is None or not pendente.get('memo'):
            pendente = None
            return

        valor = parsear_valor_brl(pendente['valor'])
        if pendente['tipo'] == 'D':
            valor = -abs(valor)
        else:
            valor = abs(valor)

        lancamentos.append({
            'data': pendente['data'],
            'valor': valor,
            'memo': re.sub(r'\s+', ' ', pendente['memo']).strip(),
        })
        pendente = None

    for linha in linhas:
        texto = linha.strip()
        if not texto:
            continue

        match_dia = PADRAO_DIA.match(texto)
        if match_dia:
            finalizar_pendente()
            data = data_de_mes_abreviado(match_dia.group(1), match_dia.group(2), match_dia.group(3))
            if data:
                data_corrente = data
            continue

        # Cabeçalho repetido em cada página (titular / conta).
        if titular_norm and re.sub(r'\s+', ' ', texto.lower()) == titular_norm:
            continue
        if conta_norm and texto == conta_norm:
            continue

        if deve_ignorar_linha(texto) and not eh_inicio_lancamento(texto):
            if pendente and (
                texto.lower().startswith('tem alguma')
                or texto.lower().startswith('caso a solução')
                or texto.lower().startswith('extrato gerado')
                or texto.lower().startswith('nu financeira')
                or texto.lower().startswith('nu pagamentos s.a')
                or 'nubank.com.br' in texto.lower()
                or texto.lower().strip() == 'e investimento'
                or re.match(r'^cnpj:\s*[\d./\-]+$', texto.lower().strip())
            ):
                finalizar_pendente()
            continue

        if texto.lower().startswith('saldo do dia'):
            finalizar_pendente()
            continue

        tipo = tipo_lancamento(texto)
        if tipo:
            finalizar_pendente()
            if not data_corrente:
                continue

            valor_str, resto = extrair_valor_da_linha(texto)
            memo = resto if resto else texto
            memo = re.sub(r'\s+-\s*$', '', memo).strip()

            pendente = {
                'data': data_corrente,
                'tipo': tipo,
                'valor': valor_str,
                'memo': memo,
            }
            continue

        if pendente:
            if PADRAO_VALOR_SIMPLES.match(texto) and pendente.get('valor') is None:
                pendente['valor'] = texto
            else:
                pendente['memo'] = f"{pendente['memo']} {texto}".strip()
            continue

    finalizar_pendente()
    return lancamentos


def converter_pdf_nubank_para_ofx(caminho_pdf, caminho_ofx, caminho_preview=None):
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
        bank_id='260',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Nubank',
        fi_id='260',
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
        print('Uso: python conversor_extrato_nubank_pdf_ofx.py <arquivo.pdf> <arquivo_saida.ofx> [preview.json]')
        sys.exit(1)

    caminho_pdf = sys.argv[1]
    caminho_ofx = sys.argv[2]
    caminho_preview = sys.argv[3] if len(sys.argv) > 3 else None

    if not os.path.exists(caminho_pdf):
        print(f"Erro: O arquivo '{caminho_pdf}' não existe.")
        sys.exit(1)

    try:
        resultado = converter_pdf_nubank_para_ofx(caminho_pdf, caminho_ofx, caminho_preview)
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
