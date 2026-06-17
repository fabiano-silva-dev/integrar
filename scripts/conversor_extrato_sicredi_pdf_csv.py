#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script para converter extrato PDF SICREDI para CSV no formato do importador avançado.
Layout: Data | Descrição | Documento | Valor | Saldo
"""

import os
import sys
import re
import csv
from datetime import datetime
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from extrato_util import eh_descricao_saldo

try:
    import pdfplumber
except ImportError:
    print("Erro: pdfplumber não encontrado. Instale com: pip install pdfplumber")
    sys.exit(1)


def extrair_dados_conta_sicredi(linhas):
    """
    Extrai associado, cooperativa e conta do cabeçalho do extrato Sicredi.

    Exemplo:
      Associado: LIMARINDRAGO AUTOMOVEIS LTDA.
      Cooperativa: 0434
      Conta: 33025-3
    """
    texto_cabecalho = '\n'.join(linhas[:20])

    titular = ''
    cooperativa = ''
    numero_conta = ''

    match_associado = re.search(r'Associado:\s*(.+)', texto_cabecalho, re.IGNORECASE)
    if match_associado:
        titular = match_associado.group(1).strip()

    match_coop = re.search(r'Cooperativa:\s*([\d\-]+)', texto_cabecalho, re.IGNORECASE)
    if match_coop:
        cooperativa = match_coop.group(1).strip()

    match_conta = re.search(r'Conta:\s*([\d.\-]+)', texto_cabecalho, re.IGNORECASE)
    if match_conta:
        numero_conta = match_conta.group(1).strip()

    acct_id = numero_conta.replace('.', '') if numero_conta else '00000000'

    return {
        'titular': titular,
        'cooperativa': cooperativa,
        'branch_id': cooperativa,
        'numero_conta': numero_conta,
        'acct_id': acct_id,
    }


def extrair_periodo_sicredi(linhas):
    """
    Extrai período do cabeçalho Sicredi.
    Ex.: Extrato (Período de 01/01/2026 a 31/01/2026)
    """
    texto_cabecalho = '\n'.join(linhas[:20])
    match = re.search(
        r'Per[ií]odo de\s*(\d{2}/\d{2}/\d{4})\s*a\s*(\d{2}/\d{2}/\d{4})',
        texto_cabecalho,
        re.IGNORECASE,
    )
    if not match:
        return None, None

    return match.group(1), match.group(2)


def extrair_saldo_anterior_sicredi(linhas):
    for linha in linhas[:30]:
        match = re.search(r'SALDO ANTERIOR\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})', linha, re.IGNORECASE)
        if match:
            valor = match.group(1).replace('.', '').replace(',', '.')
            try:
                return abs(float(valor))
            except ValueError:
                pass
    return None


def extrair_texto_pdf(caminho_pdf):
    """
    Extrai texto de todas as páginas do PDF usando pdfplumber.
    Usa análise de layout do pdfminer para extração correta (evita espaços espúrios
    que bibliotecas como PyPDF2 inserem em certos PDFs).
    """
    linhas = []
    with pdfplumber.open(caminho_pdf) as pdf:
        for page in pdf.pages:
            text = page.extract_text()
            if text:
                linhas.extend(text.split('\n'))
    return linhas


def parsear_lancamentos(linhas):
    """
    Parseia o layout SICREDI.
    Cada lançamento está em UMA linha: Data Descrição Documento Valor Saldo
    Ex: 02/02/2026 RECEBIMENTO PIX 02092576011 RODRIGO BASSO MARIOT PIX_CRED 43,75 88.169,17
    """
    # Padrão: data + texto + valor + saldo (saldo pode ser negativo)
    padrao_linha = re.compile(
        r'^(\d{2}/\d{2}/\d{4})\s+(.+?)\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})\s*$'
    )
    # Documento: PIX_CRED, PIX_DEB, PIX_CRE, CX123456, COB000004
    padrao_doc = re.compile(r'\s+(PIX_CRED|PIX_DEB|PIX_CRE|CX\d+|COB\d+)\s*$')
    
    lancamentos = []
    
    for linha in linhas:
        linha = linha.strip()
        if not linha:
            continue
        
        match = padrao_linha.match(linha)
        if not match:
            continue
        
        data, meio, valor_str, saldo_str = match.groups()

        doc_match = padrao_doc.search(meio)
        if doc_match:
            documento = doc_match.group(1)
            descricao = meio[:doc_match.start()].strip()
        else:
            documento = ''
            descricao = meio

        if eh_descricao_saldo(meio) or eh_descricao_saldo(descricao):
            continue

        try:
            valor_limpo = valor_str.replace('.', '').replace(',', '.')
            saldo_limpo = saldo_str.replace('.', '').replace(',', '.')
            valor_float = float(valor_limpo)
            saldo_float = float(saldo_limpo)
            if valor_float != 0:
                lancamentos.append({
                    'data': data,
                    'descricao': descricao,
                    'documento': documento,
                    'valor': valor_float,
                    'saldo': saldo_float,
                })
        except ValueError:
            pass

    return lancamentos


def extrair_nome_empresa(descricao):
    """
    Extrai nome do pagador/recebedor da descrição.
    Ex: "RECEBIMENTO PIX 02092576011 RODRIGO BASSO MARIOT" -> "RODRIGO BASSO MARIOT"
    Ex: "RECEBIMENTO PIX SICREDI 01587516047 RICARDO ANTO" -> "RICARDO ANTO"
    Ex: "MANUTENCAO DE TITULOS" -> "MANUTENCAO DE TITULOS"
    """
    descricao = descricao.strip()
    if not descricao:
        return ''
    
    # Padrão RECEBIMENTO PIX [CPF/CNPJ] NOME
    match = re.match(r'RECEBIMENTO PIX (?:SICREDI )?(\d{11,14})\s+(.+)$', descricao, re.IGNORECASE)
    if match:
        return match.group(2).strip()
    
    # Padrão PAGAMENTO PIX [CPF/CNPJ] NOME
    match = re.match(r'PAGAMENTO PIX (?:SICREDI )?(\d{11,14})\s+(.+)$', descricao, re.IGNORECASE)
    if match:
        return match.group(2).strip()
    
    # Padrão RECEBIMENTO PIX SICREDI CNPJ NOME
    match = re.match(r'RECEBIMENTO PIX SICREDI (\d{14})\s+(.+)$', descricao, re.IGNORECASE)
    if match:
        return match.group(2).strip()
    
    # Padrão genérico: RECEBIMENTO PIX ... NOME (pegar último bloco após números)
    match = re.match(r'(?:RECEBIMENTO|PAGAMENTO) PIX\s+(?:SICREDI\s+)?[\d\s]+(.+)$', descricao, re.IGNORECASE)
    if match:
        return match.group(1).strip()
    
    return descricao


def extrair_cnpj_cpf(descricao):
    """Extrai CPF/CNPJ da descrição de PIX do Sicredi."""
    descricao = (descricao or '').strip()
    if not descricao:
        return ''

    match = re.match(
        r'(?:RECEBIMENTO|PAGAMENTO)\s+PIX(?:\s+SICREDI)?\s+(\d{11,14})\s+',
        descricao,
        re.IGNORECASE,
    )
    if match:
        return match.group(1)

    return ''


def formatar_valor_brl(valor):
    """Formata valor para padrão brasileiro (1.234,56)."""
    try:
        valor_abs = abs(float(valor))
        return f"{valor_abs:,.2f}".replace('.', 'X').replace(',', '.').replace('X', ',')
    except Exception:
        return "0,00"


def main():
    if len(sys.argv) < 3:
        print("Uso: python conversor_extrato_sicredi_pdf_csv.py <arquivo.pdf> <arquivo_saida.csv> [conta_banco]")
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    csv_path = sys.argv[2]
    conta_banco = sys.argv[3] if len(sys.argv) > 3 else '1.1.1.01'
    
    if not Path(pdf_path).exists():
        print(f"Erro: Arquivo '{pdf_path}' não encontrado.")
        sys.exit(1)
    
    linhas = extrair_texto_pdf(pdf_path)
    lancamentos = parsear_lancamentos(linhas)
    
    # Ordenar por data
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
        
        for l in lancamentos:
            valor = l['valor']
            nome = extrair_nome_empresa(l['descricao'])
            documento = l['documento']
            cnpj_cpf = extrair_cnpj_cpf(l['descricao'])
            # Histórico = descrição original do PDF (para amarração por descrição)
            historico = l['descricao'].strip()
            if documento and documento not in ('PIX_CRED', 'PIX_CRE'):
                historico += f" DOC: {documento}"

            if valor > 0:
                conta_debito = conta_banco
                conta_credito = ''
            else:
                conta_debito = ''
                conta_credito = conta_banco

            writer.writerow([
                l['data'],
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
