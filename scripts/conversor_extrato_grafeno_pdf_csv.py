import sys
import csv
import re
from PyPDF2 import PdfReader
import datetime
import pandas as pd

if len(sys.argv) < 3:
    print("Uso: python conversor_extrato_grafeno_pdf_csv.py <arquivo.pdf> <arquivo_saida.csv> [conta_banco]")
    sys.exit(1)

pdf_path = sys.argv[1]
csv_path = sys.argv[2]
conta_banco = sys.argv[3] if len(sys.argv) > 3 else '1.1.1.01'  # Conta padrão se não fornecida

reader = PdfReader(pdf_path)

lancamentos = []
processar = False
cabecalho_seq = ['Data', 'Lançamento', 'Nome']
cabecalho_idx = 0
saldo_inicial_valor = None
saldo_final_valor = None

for page in reader.pages:
    text = page.extract_text()
    if not text:
        continue
    lines = text.split('\n')
    for i in range(len(lines)):
        linha = lines[i].strip()
        if not processar:
            if linha == cabecalho_seq[cabecalho_idx]:
                cabecalho_idx += 1
                if cabecalho_idx == len(cabecalho_seq):
                    processar = True
                    continue
            else:
                cabecalho_idx = 0
            continue
        if re.match(r"\d{2}/\d{2}/\d{4}( \d{2}:\d{2})?", linha):
            data = linha
            lancamento = lines[i+1].strip() if i+1 < len(lines) else ''
            nome = ''
            valor = ''
            j = i+2
            nome_partes = []
            encontrou_num = False
            while j < len(lines):
                ltest = lines[j].strip()
                if re.match(r"^-?R\$ [\d\.,]+", ltest):
                    valor = ltest
                    encontrou_num = True
                    break
                if re.match(r"^[\d\-]{3,12}$", ltest):
                    encontrou_num = True
                    break
                nome_partes.append(ltest)
                j += 1
            nome = ' '.join(nome_partes).strip()
            if encontrou_num and valor == '':
                while j < len(lines):
                    ltest = lines[j].strip()
                    if re.match(r"^-?R\$ [\d\.,]+", ltest):
                        valor = ltest
                        break
                    j += 1
            if 'SALDO FINAL' in lancamento or 'SALDO INICIAL' in lancamento:
                continue
            if not re.fullmatch(r"\d{2}/\d{2}/\d{4}( \d{2}:\d{2})?", data):
                continue
            if lancamento == "Tarifas de conta":
                nome = "TARIFA BANCARIA"
            lancamentos.append([data, lancamento, nome, valor])

def parse_data(data):
    try:
        return datetime.datetime.strptime(data, "%d/%m/%Y %H:%M")
    except ValueError:
        try:
            return datetime.datetime.strptime(data, "%d/%m/%Y")
        except ValueError:
            return datetime.datetime(1900, 1, 1)

lancamentos.sort(key=lambda x: parse_data(x[0]))

def formatar_valor_brl(valor):
    try:
        # Transformar valor negativo em positivo
        valor_absoluto = abs(float(valor))
        return f"{valor_absoluto:,.2f}".replace('.', 'X').replace(',', '.').replace('X', ',')
    except Exception:
        return "0,00"

def extrair_cnpj_cpf(texto):
    """Extrai CNPJ ou CPF do texto"""
    # Padrão para CNPJ: XX.XXX.XXX/XXXX-XX
    cnpj_match = re.search(r'\d{2}\.\d{3}\.\d{3}/\d{4}-\d{2}', texto)
    if cnpj_match:
        return cnpj_match.group()
    
    # Padrão para CPF: XXX.XXX.XXX-XX
    cpf_match = re.search(r'\d{3}\.\d{3}\.\d{3}-\d{2}', texto)
    if cpf_match:
        return cpf_match.group()
    
    return ""

def extrair_nome_empresa(texto):
    """Extrai o nome da empresa do texto, removendo CNPJ/CPF"""
    # Remove CNPJ/CPF
    texto_limpo = re.sub(r'\d{2}\.\d{3}\.\d{3}/\d{4}-\d{2}', '', texto)
    texto_limpo = re.sub(r'\d{3}\.\d{3}\.\d{3}-\d{2}', '', texto_limpo)
    # Remove caracteres especiais e espaços extras
    texto_limpo = re.sub(r'[^\w\s]', '', texto_limpo)
    texto_limpo = re.sub(r'\s+', ' ', texto_limpo).strip()
    return texto_limpo

with open(csv_path, 'w', newline='', encoding='utf-8') as csvfile:
    writer = csv.writer(csvfile, delimiter=';')
    # Escrever cabeçalho com as colunas que o sistema PHP espera
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
        valor_brl = l[3].replace('R$ ', '').replace('R$', '').replace('.', '').replace(',', '.')
        valor_brl = valor_brl.strip()
        try:
            valor_float = float(valor_brl)
        except Exception:
            valor_float = 0.0
        
        # Extrair nome da empresa e CNPJ/CPF
        nome_empresa = l[2]
        cnpj_cpf = extrair_cnpj_cpf(nome_empresa)
        nome_limpo = extrair_nome_empresa(nome_empresa)
        
        # Aplicar lógica de contas baseada no valor
        if valor_float > 0:
            # Recebimento (positivo): conta do banco no débito, outra conta vazia
            conta_debito = conta_banco
            conta_credito = ''
            historico_final = f"RCTO REF {nome_limpo}"
        else:
            # Pagamento (negativo): conta do banco no crédito, outra conta vazia
            conta_debito = ''
            conta_credito = conta_banco
            historico_final = f"PGTO REF {nome_limpo}"
        
        # Se tem CNPJ/CPF, adicionar ao histórico
        if cnpj_cpf:
            historico_final += f" {cnpj_cpf}"
        
        writer.writerow([
            l[0].split(' ')[0],  # Data do Lançamento
            'Sistema',           # Usuário
            conta_debito,        # Conta Débito
            conta_credito,       # Conta Crédito
            formatar_valor_brl(valor_float),  # Valor do Lançamento
            historico_final,     # Histórico
            '',                  # Código da Filial/Matriz
            nome_limpo,          # Nome da Empresa
            '',                  # Número da Nota
            cnpj_cpf or '',      # CNPJ/CPF
        ])

print(f'CSV padronizado gerado em: {csv_path}')
