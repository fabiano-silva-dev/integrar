#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Motor: Extrato Histórico da Conta (Caixa — modelo antigo).

Entrada CLI: identifica o layout e despacha via caixa_extrato_layout.
"""

import csv
import datetime
import os
import re
import sys

from PyPDF2 import PdfReader

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from extrato_util import eh_descricao_saldo


def extrair_nome_empresa(texto):
    texto_limpo = re.sub(r'\d{2}\.\d{3}\.\d{3}/\d{4}-\d{2}', '', texto or '')
    texto_limpo = re.sub(r'\d{3}\.\d{3}\.\d{3}-\d{2}', '', texto_limpo)
    texto_limpo = re.sub(r'[^\w\s]', '', texto_limpo)
    texto_limpo = re.sub(r'\s+', ' ', texto_limpo).strip()
    return texto_limpo


def parse_data(data):
    try:
        return datetime.datetime.strptime(data, '%d/%m/%Y')
    except ValueError:
        return datetime.datetime(1900, 1, 1)


def formatar_valor_brl(valor):
    try:
        valor_absoluto = abs(float(valor))
        return f'{valor_absoluto:,.2f}'.replace('.', 'X').replace(',', '.').replace('X', ',')
    except Exception:
        return '0,00'


def extrair_cnpj_cpf(texto):
    cnpj_match = re.search(r'\d{2}\.\d{3}\.\d{3}/\d{4}-\d{2}', texto or '')
    if cnpj_match:
        return cnpj_match.group()
    cpf_match = re.search(r'\d{3}\.\d{3}\.\d{3}-\d{2}', texto or '')
    if cpf_match:
        return cpf_match.group()
    return ''


def converter_historico(pdf_path, csv_path, conta_banco='1.1.1.01'):
    reader = PdfReader(pdf_path)
    lancamentos = []
    processar = False

    for page in reader.pages:
        text = page.extract_text()
        if not text:
            continue
        lines = text.split('\n')

        for i in range(len(lines)):
            linha = lines[i].strip()

            if 'SALDO ANTERIOR' in linha:
                processar = True
                continue

            if not processar:
                continue

            if re.match(r'^\d{2}/\d{2}/\d{4} ', linha):
                partes = linha.split()
                if len(partes) < 6:
                    continue

                data = partes[0]
                valores = [p for p in partes if re.match(r'^[\d\.,]+$', p)]
                if len(valores) < 2:
                    continue

                valor_str = valores[0]
                tipo_mov = next((p for p in partes if p in ('C', 'D')), '')

                historico_partes = []
                for parte in partes[1:]:
                    if re.match(r'^[\d\.,]+$', parte):
                        break
                    historico_partes.append(parte)
                historico = ' '.join(historico_partes).strip()

                try:
                    valor = float(valor_str.replace('.', '').replace(',', '.'))
                    if tipo_mov == 'D':
                        valor = -valor
                except Exception:
                    valor = 0

                cnpj_cpf = ''
                nome_empresa = ''
                j = i + 1
                while j < len(lines) and j < i + 3:
                    ltest = lines[j].strip()
                    if re.match(r'^\d{2}/\d{2}/\d{4}', ltest):
                        break

                    cnpj_match = re.search(r'\d{2}\.\d{3}\.\d{3}/\d{4}-\d{2}', ltest)
                    cpf_match = re.search(r'\d{3}\.\d{3}\.\d{3}-\d{2}', ltest)

                    if cnpj_match and not cnpj_cpf:
                        cnpj_cpf = cnpj_match.group()
                    elif cpf_match and not cnpj_cpf:
                        cnpj_cpf = cpf_match.group()

                    if not cnpj_match and not cpf_match and ltest:
                        nome_empresa += ' ' + ltest
                    j += 1

                nome_empresa = nome_empresa.strip()

                if eh_descricao_saldo(historico) or valor == 0:
                    continue

                if historico:
                    lancamentos.append([data, historico, nome_empresa, valor, cnpj_cpf])

    lancamentos.sort(key=lambda x: parse_data(x[0]))

    with open(csv_path, 'w', newline='', encoding='utf-8') as csvfile:
        writer = csv.writer(csvfile, delimiter=';')
        writer.writerow(
            [
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
            ]
        )

        for data, historico, nome_empresa, valor, cnpj_cpf in lancamentos:
            nome_limpo = (
                extrair_nome_empresa(nome_empresa)
                if nome_empresa
                else extrair_nome_empresa(historico)
            )
            cnpj_cpf_final = cnpj_cpf if cnpj_cpf else extrair_cnpj_cpf(historico)

            if valor > 0:
                conta_debito = conta_banco
                conta_credito = ''
                historico_final = f'RCTO REF {nome_limpo}'
            else:
                conta_debito = ''
                conta_credito = conta_banco
                historico_final = f'PGTO REF {nome_limpo}'

            if cnpj_cpf_final:
                historico_final += f' {cnpj_cpf_final}'

            writer.writerow(
                [
                    data,
                    'Sistema',
                    conta_debito,
                    conta_credito,
                    formatar_valor_brl(valor),
                    historico_final,
                    '',
                    nome_limpo,
                    '',
                    cnpj_cpf_final or '',
                ]
            )

    return len(lancamentos)


def converter(pdf_path, csv_path, conta_banco='1.1.1.01'):
    from caixa_extrato_layout import converter as despachar

    return despachar(pdf_path, csv_path, conta_banco)


def main():
    if len(sys.argv) < 3:
        print(
            'Uso: python conversor_extrato_caixa_federal_pdf_csv.py '
            '<arquivo.pdf> <arquivo_saida.csv> [conta_banco]'
        )
        sys.exit(1)

    pdf_path = sys.argv[1]
    csv_path = sys.argv[2]
    conta_banco = sys.argv[3] if len(sys.argv) > 3 else '1.1.1.01'

    if not os.path.exists(pdf_path):
        print(f"Erro: O arquivo '{pdf_path}' não existe.")
        sys.exit(1)

    total = converter(pdf_path, csv_path, conta_banco)
    print(f'CSV padronizado gerado em: {csv_path}')
    print(f'Total de lançamentos processados: {total}')


if __name__ == '__main__':
    main()
