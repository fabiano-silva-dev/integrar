#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Conversor de arquivo OFX para CSV
Converte arquivos OFX (Open Financial Exchange) para formato CSV
com colunas padronizadas para importação no sistema.
"""

import sys
import re
import csv
from datetime import datetime
import xml.etree.ElementTree as ET

def parse_ofx_date(date_str):
    """
    Converte data do formato OFX (YYYYMMDDHHMMSS) para formato brasileiro (DD/MM/YYYY)
    """
    if not date_str or len(date_str) < 8:
        return ""
    
    try:
        # Extrair apenas a parte da data (primeiros 8 caracteres)
        date_part = date_str[:8]
        year = date_part[:4]
        month = date_part[4:6]
        day = date_part[6:8]
        return f"{day}/{month}/{year}"
    except:
        return ""

def parse_ofx_amount(amount_str):
    """
    Converte valor do formato OFX para formato brasileiro
    """
    if not amount_str:
        return "0,00"
    
    try:
        # Converter para float e formatar com vírgula como separador decimal
        amount = float(amount_str)
        return f"{amount:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
    except:
        return "0,00"

def extract_transaction_type(trntype, name, memo):
    """
    Determina o tipo de transação baseado nos campos OFX
    """
    trntype_lower = trntype.lower() if trntype else ""
    name_lower = name.lower() if name else ""
    memo_lower = memo.lower() if memo else ""
    
    # Mapear tipos de transação
    if trntype_lower == "credit":
        return "Crédito"
    elif trntype_lower == "debit":
        return "Débito"
    else:
        return "Outros"

def extract_description(name, memo):
    """
    Extrai descrição da transação combinando NAME e MEMO
    """
    desc_parts = []
    
    if name and name.strip():
        desc_parts.append(name.strip())
    
    if memo and memo.strip() and memo.strip() != name.strip():
        desc_parts.append(memo.strip())
    
    return " - ".join(desc_parts) if desc_parts else "Transação"

def parse_ofx_file(file_path, conta_banco='1.1.1.01'):
    """
    Parse do arquivo OFX e extração das transações
    """
    transactions = []
    
    try:
        with open(file_path, 'r', encoding='utf-8', errors='ignore') as file:
            content = file.read()
    except UnicodeDecodeError:
        # Tentar com encoding latin-1 se utf-8 falhar
        with open(file_path, 'r', encoding='latin-1') as file:
            content = file.read()
    
    # Extrair informações da conta
    bank_id_match = re.search(r'<BANKID>(\d+)', content)
    acct_id_match = re.search(r'<ACCTID>([^<]+)', content)
    acct_type_match = re.search(r'<ACCTTYPE>([^<]+)', content)
    
    bank_id = bank_id_match.group(1) if bank_id_match else ""
    acct_id = acct_id_match.group(1) if acct_id_match else ""
    acct_type = acct_type_match.group(1) if acct_type_match else ""
    
    # Extrair período das transações
    dtstart_match = re.search(r'<DTSTART>(\d+)', content)
    dtend_match = re.search(r'<DTEND>(\d+)', content)
    
    dtstart = dtstart_match.group(1) if dtstart_match else ""
    dtend = dtend_match.group(1) if dtend_match else ""
    
    # Extrair saldo
    balance_match = re.search(r'<BALAMT>([^<]+)', content)
    balance = balance_match.group(1) if balance_match else "0"
    
    # Extrair todas as transações
    transaction_pattern = r'<STMTTRN>(.*?)</STMTTRN>'
    transaction_matches = re.findall(transaction_pattern, content, re.DOTALL)
    
    for i, transaction_text in enumerate(transaction_matches):
        # Extrair campos da transação
        trntype_match = re.search(r'<TRNTYPE>([^<\n]+)', transaction_text)
        dtposted_match = re.search(r'<DTPOSTED>(\d+)', transaction_text)
        trnamt_match = re.search(r'<TRNAMT>([^<\n]+)', transaction_text)
        fitid_match = re.search(r'<FITID>([^<\n]+)', transaction_text)
        checknum_match = re.search(r'<CHECKNUM>([^<\n]+)', transaction_text)
        name_match = re.search(r'<NAME>([^<\n]*)', transaction_text)
        memo_match = re.search(r'<MEMO>([^<\n]*)', transaction_text)
        
        trntype = (trntype_match.group(1) if trntype_match else "").strip()
        dtposted = (dtposted_match.group(1) if dtposted_match else "").strip()
        trnamt = (trnamt_match.group(1) if trnamt_match else "0").strip()
        fitid = (fitid_match.group(1) if fitid_match else "").strip()
        checknum = (checknum_match.group(1) if checknum_match else "").strip()
        name = (name_match.group(1) if name_match else "").strip()
        memo = (memo_match.group(1) if memo_match else "").strip()
        
        # Limpar quebras de linha e caracteres especiais
        name = name.replace('\n', ' ').replace('\r', ' ').replace('"', '')
        memo = memo.replace('\n', ' ').replace('\r', ' ').replace('"', '')
        
        # Processar dados
        data_lancamento = parse_ofx_date(dtposted)
        valor = parse_ofx_amount(trnamt)
        tipo_transacao = extract_transaction_type(trntype, name, memo)
        descricao = extract_description(name, memo)
        
        # Determinar conta de débito e crédito pelo sinal do valor (mesmo padrão dos demais conversores)
        try:
            amount = float(str(trnamt).replace(',', '.'))
        except (TypeError, ValueError):
            amount = 0.0

        if amount >= 0:
            conta_debito = conta_banco
            conta_credito = ""
        else:
            conta_debito = ""
            conta_credito = conta_banco
        
        # Limpar todos os campos de quebras de linha e caracteres especiais
        def clean_field(field):
            if field is None:
                return ""
            return str(field).replace('\n', ' ').replace('\r', ' ').replace('"', '').strip()
        
        transaction = {
            'Data do Lançamento': clean_field(data_lancamento),
            'Usuário': 'Sistema',
            'Conta Débito': clean_field(conta_debito),
            'Conta Crédito': clean_field(conta_credito),
            'Valor do Lançamento': clean_field(valor),
            'Histórico': clean_field(descricao),
            'Código da Filial/Matriz': '',
            'Nome da Empresa': 'Banco',
            'Número da Nota': clean_field(fitid if fitid != "000000" else ""),
            'Tipo Transação': clean_field(tipo_transacao),
            'ID Transação': clean_field(fitid),
            'Número Cheque': clean_field(checknum if checknum != "000000" else ""),
            'Banco': clean_field(bank_id),
            'Conta': clean_field(acct_id),
            'Tipo Conta': clean_field(acct_type)
        }
        
        transactions.append(transaction)
    
    return transactions, {
        'bank_id': bank_id,
        'acct_id': acct_id,
        'acct_type': acct_type,
        'dtstart': dtstart,
        'dtend': dtend,
        'balance': balance,
        'total_transactions': len(transactions)
    }

def write_csv(transactions, output_path):
    """
    Escreve as transações em arquivo CSV
    """
    if not transactions:
        print("Nenhuma transação encontrada para exportar.")
        return False
    
    # Definir cabeçalhos do CSV
    headers = [
        'Data do Lançamento',
        'Usuário',
        'Conta Débito',
        'Conta Crédito',
        'Valor do Lançamento',
        'Histórico',
        'Código da Filial/Matriz',
        'Nome da Empresa',
        'Número da Nota',
        'Tipo Transação',
        'ID Transação',
        'Número Cheque',
        'Banco',
        'Conta',
        'Tipo Conta'
    ]
    
    try:
        with open(output_path, 'w', newline='', encoding='utf-8') as csvfile:
            writer = csv.DictWriter(csvfile, fieldnames=headers, delimiter=';', quoting=csv.QUOTE_MINIMAL)
            writer.writeheader()
            writer.writerows(transactions)
        
        print(f"Arquivo CSV gerado com sucesso: {output_path}")
        print(f"Total de transações processadas: {len(transactions)}")
        return True
        
    except Exception as e:
        print(f"Erro ao escrever arquivo CSV: {e}")
        return False

def main():
    """
    Função principal
    """
    if len(sys.argv) < 3:
        print("Uso: python conversor_ofx_csv.py <arquivo_ofx> <arquivo_csv_saida> [conta_banco]")
        print("Exemplo: python conversor_ofx_csv.py extrato.ofx extrato.csv 1.1.1.01")
        sys.exit(1)
    
    input_file = sys.argv[1]
    output_file = sys.argv[2]
    conta_banco = sys.argv[3] if len(sys.argv) > 3 else '1.1.1.01'
    
    print(f"Processando arquivo OFX: {input_file}")
    print(f"Arquivo de saída CSV: {output_file}")
    print(f"Conta do banco: {conta_banco}")
    
    try:
        # Parse do arquivo OFX
        transactions, metadata = parse_ofx_file(input_file, conta_banco)
        
        print(f"\nInformações da conta:")
        print(f"Banco: {metadata['bank_id']}")
        print(f"Conta: {metadata['acct_id']}")
        print(f"Tipo: {metadata['acct_type']}")
        print(f"Período: {parse_ofx_date(metadata['dtstart'])} a {parse_ofx_date(metadata['dtend'])}")
        print(f"Saldo: R$ {parse_ofx_amount(metadata['balance'])}")
        print(f"Total de transações: {metadata['total_transactions']}")
        
        # Escrever CSV
        if write_csv(transactions, output_file):
            print("\nConversão concluída com sucesso!")
        else:
            print("\nErro na conversão!")
            sys.exit(1)
            
    except Exception as e:
        print(f"Erro durante o processamento: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()


