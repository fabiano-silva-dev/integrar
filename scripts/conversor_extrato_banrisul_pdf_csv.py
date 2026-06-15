#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Converte extrato PDF Banrisul (conta corrente) para CSV do importador avançado.

Layout do PDF:
  DIA HISTORICO DOCUMENTO VALOR
  Linhas com dia explícito (ex.: 03 RESGATE CDB 000006 10.000,00) iniciam o dia.
  Demais lançamentos do mesmo dia não repetem o dia.
  Débitos terminam com hífen no valor (ex.: 62,40-).
  PIX pode trazer linha seguinte NOME: <beneficiário>.
"""

import csv
import re
import sys
from datetime import datetime
from pathlib import Path

try:
    import pdfplumber
except ImportError:
    print("Erro: pdfplumber não encontrado. Instale com: pip install pdfplumber")
    sys.exit(1)

MESES = {
    'JAN': 1, 'FEV': 2, 'MAR': 3, 'ABR': 4, 'MAI': 5, 'JUN': 6,
    'JUL': 7, 'AGO': 8, 'SET': 9, 'OUT': 10, 'NOV': 11, 'DEZ': 12,
}

PADRAO_LANCAMENTO = re.compile(
    r'^(?:(\d{2})\s+)?(.+?)\s+(\d{6})\s+(\d{1,3}(?:\.\d{3})*,\d{2})(-)?\s*$'
)
PADRAO_MOVIMENTOS_MES = re.compile(
    r'\+\+\s+MOVIMENTOS\s+([A-Z]{3})/(\d{4})',
    re.IGNORECASE,
)
PADRAO_SALDO_ANT = re.compile(
    r'SALDO\s+ANT\s+EM\s+(\d{2}/\d{2}/\d{4})',
    re.IGNORECASE,
)
PADRAO_CABECALHO_DATA = re.compile(
    r'B\s*A\s*N\s*R\s*I\s*S\s*U\s*L\s+(\d{2}/\d{2}/\d{4})',
    re.IGNORECASE,
)
PADRAO_NOME = re.compile(r'^NOME:\s*(.+)$', re.IGNORECASE)
PADRAO_PAGINA = re.compile(r'^--\s+\d+\s+of\s+\d+\s+--$', re.IGNORECASE)


def extrair_texto_pdf(caminho_pdf):
    linhas = []
    with pdfplumber.open(caminho_pdf) as pdf:
        for page in pdf.pages:
            text = page.extract_text()
            if text:
                linhas.extend(text.split('\n'))
    return linhas


def extrair_contexto_periodo(linhas):
    ano = datetime.now().year
    mes = datetime.now().month

    texto_inicial = '\n'.join(linhas[:80])
    match_cab = PADRAO_CABECALHO_DATA.search(texto_inicial)
    if match_cab:
        try:
            data_cab = datetime.strptime(match_cab.group(1), '%d/%m/%Y')
            ano = data_cab.year
            mes = data_cab.month
        except ValueError:
            pass

    match_saldo = PADRAO_SALDO_ANT.search(texto_inicial)
    if match_saldo:
        try:
            data_saldo = datetime.strptime(match_saldo.group(1), '%d/%m/%Y')
            ano = data_saldo.year
        except ValueError:
            pass

    return mes, ano


def montar_data(dia, mes, ano):
    try:
        return datetime(ano, mes, int(dia)).strftime('%d/%m/%Y')
    except ValueError:
        return None


def parsear_lancamentos(linhas):
    mes_atual, ano_atual = extrair_contexto_periodo(linhas)
    dia_atual = None
    lancamentos = []
    dentro_movimentos = False
    nome_pendente = None

    for linha_bruta in linhas:
        linha = linha_bruta.strip()
        if not linha or PADRAO_PAGINA.match(linha):
            continue

        if 'MOVIMENTOS DA CONTA CORRENTE' in linha.upper():
            dentro_movimentos = True
            continue

        if not dentro_movimentos:
            continue

        if linha.startswith('-') or linha.startswith('+-'):
            continue

        match_mes = PADRAO_MOVIMENTOS_MES.search(linha)
        if match_mes:
            mes_sigla = match_mes.group(1).upper()
            ano_atual = int(match_mes.group(2))
            mes_atual = MESES.get(mes_sigla, mes_atual)
            dia_atual = None
            continue

        if re.search(r'^SALDO\s+(ANT|NA\s+DATA)\b', linha, re.IGNORECASE):
            continue

        match_nome = PADRAO_NOME.match(linha)
        if match_nome:
            nome = match_nome.group(1).strip()
            if lancamentos:
                lancamentos[-1]['nome'] = nome
            else:
                nome_pendente = nome
            continue

        match = PADRAO_LANCAMENTO.match(linha)
        if not match:
            continue

        dia_linha, descricao, documento, valor_str, sinal_debito = match.groups()
        if dia_linha:
            dia_atual = dia_linha

        if not dia_atual:
            continue

        data = montar_data(dia_atual, mes_atual, ano_atual)
        if not data:
            continue

        try:
            valor_float = float(valor_str.replace('.', '').replace(',', '.'))
        except ValueError:
            continue

        if sinal_debito:
            valor_float = -abs(valor_float)
        else:
            valor_float = abs(valor_float)

        if valor_float == 0:
            continue

        lancamentos.append({
            'data': data,
            'descricao': descricao.strip(),
            'documento': documento,
            'valor': valor_float,
            'nome': nome_pendente or '',
        })
        nome_pendente = None

    return lancamentos


def formatar_valor_brl(valor):
    try:
        return f"{abs(float(valor)):,.2f}".replace('.', 'X').replace(',', '.').replace('X', ',')
    except Exception:
        return "0,00"


def extrair_cnpj_cpf_nome(nome):
    """Extrai CPF/CNPJ sem máscara ao final do nome (ex.: NOME 02446736084)."""
    nome = (nome or '').strip()
    if not nome:
        return '', ''

    match = re.search(r'\b(\d{11}|\d{14})\s*$', nome)
    if not match:
        return nome, ''

    documento = match.group(1)
    nome_limpo = re.sub(r'\s*' + re.escape(documento) + r'\s*$', '', nome).strip()
    return nome_limpo, documento


def main():
    if len(sys.argv) < 3:
        print("Uso: python conversor_extrato_banrisul_pdf_csv.py <arquivo.pdf> <arquivo_saida.csv> [conta_banco]")
        sys.exit(1)

    pdf_path = sys.argv[1]
    csv_path = sys.argv[2]
    conta_banco = sys.argv[3] if len(sys.argv) > 3 else '1.1.1.01'

    if not Path(pdf_path).exists():
        print(f"Erro: Arquivo '{pdf_path}' não encontrado.")
        sys.exit(1)

    linhas = extrair_texto_pdf(pdf_path)
    lancamentos = parsear_lancamentos(linhas)
    lancamentos.sort(key=lambda x: datetime.strptime(x['data'], '%d/%m/%Y'))

    with open(csv_path, 'w', newline='', encoding='utf-8') as csvfile:
        writer = csv.writer(csvfile, delimiter=';')
        writer.writerow([
            'Data do Lançamento',
            'Usuário',
            'Conta Débito',
            'Conta Crédito',
            'Valor do Lançamento',
            'Histórico',
            'Código da Filial/Matriz',
            'Nome da Empresa',
            'Número da Nota',
            'CNPJ/CPF',
        ])

        for item in lancamentos:
            valor = item['valor']
            historico = item['descricao']
            nome, cnpj_cpf = extrair_cnpj_cpf_nome(item['nome'])
            documento = item['documento']

            if valor > 0:
                conta_debito = conta_banco
                conta_credito = ''
            else:
                conta_debito = ''
                conta_credito = conta_banco

            writer.writerow([
                item['data'],
                'Sistema',
                conta_debito,
                conta_credito,
                formatar_valor_brl(valor),
                historico,
                '',
                nome,
                documento,
                cnpj_cpf,
            ])

    print(f"CSV gerado em: {csv_path}")
    print(f"Total de lançamentos: {len(lancamentos)}")


if __name__ == '__main__':
    main()
