import sys
import csv
import re
from PyPDF2 import PdfReader
import datetime

if len(sys.argv) < 3:
    print("Uso: python conversor_extrato_caixa_federal_pdf_csv.py <arquivo.pdf> <arquivo_saida.csv> [conta_banco]")
    sys.exit(1)

pdf_path = sys.argv[1]
csv_path = sys.argv[2]
conta_banco = sys.argv[3] if len(sys.argv) > 3 else '1.1.1.01'  # Conta padrão se não fornecida

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
        
        # Procurar por "SALDO ANTERIOR" para começar a processar
        if 'SALDO ANTERIOR' in linha:
            processar = True
            continue
            
        if not processar:
            continue
            
        # Padrão: DD/MM/YYYY HISTORICO VALOR SALDO NUMERO_DOC C/D C/D HORA
        # Exemplo: 03/01/2025 CREDITO TRANSF INTERNET 300,00 300,00 000031449 C C 03/01 02:49
        if re.match(r"^\d{2}/\d{2}/\d{4} ", linha):
            partes = linha.split()
            if len(partes) >= 6:
                data = partes[0]
                
                # Procurar o valor (terceiro número da linha)
                valores = []
                for parte in partes:
                    if re.match(r"^[\d\.,]+$", parte):
                        valores.append(parte)
                
                if len(valores) >= 2:
                    valor_str = valores[0]  # Primeiro valor é o valor do lançamento
                    saldo_str = valores[1]  # Segundo valor é o saldo
                    
                    # Procurar tipo de movimento (C ou D)
                    tipo_mov = ''
                    for parte in partes:
                        if parte in ['C', 'D']:
                            tipo_mov = parte
                            break
                    
                    # Extrair histórico (tudo entre data e primeiro valor)
                    historico_partes = []
                    encontrou_valor = False
                    for parte in partes[1:]:
                        if re.match(r"^[\d\.,]+$", parte):
                            encontrou_valor = True
                            break
                        historico_partes.append(parte)
                    
                    historico = ' '.join(historico_partes).strip()
                    
                    # Processar valor
                    try:
                        valor = float(valor_str.replace('.', '').replace(',', '.'))
                        if tipo_mov == 'D':
                            valor = -valor
                    except:
                        valor = 0
                    
                    # Extrair CNPJ/CPF e nome da empresa das próximas linhas
                    cnpj_cpf = ''
                    nome_empresa = ''
                    j = i + 1
                    while j < len(lines) and j < i + 3:
                        ltest = lines[j].strip()
                        
                        # Se encontrar próxima data, parar
                        if re.match(r"^\d{2}/\d{2}/\d{4}", ltest):
                            break
                        
                        # Extrair CNPJ/CPF
                        cnpj_match = re.search(r'\d{2}\.\d{3}\.\d{3}/\d{4}-\d{2}', ltest)
                        cpf_match = re.search(r'\d{3}\.\d{3}\.\d{3}-\d{2}', ltest)
                        
                        if cnpj_match and not cnpj_cpf:
                            cnpj_cpf = cnpj_match.group()
                        elif cpf_match and not cnpj_cpf:
                            cnpj_cpf = cpf_match.group()
                        
                        # Adicionar ao nome da empresa se não for CNPJ/CPF
                        if not cnpj_match and not cpf_match and ltest:
                            nome_empresa += ' ' + ltest
                        
                        j += 1
                    
                    nome_empresa = nome_empresa.strip()
                    
                    # Pular linhas que não são lançamentos válidos
                    if 'SALDO ANTERIOR' in historico or 'SALDO FINAL' in historico or valor == 0:
                        continue
                        
                    if historico:
                        lancamentos.append([data, historico, nome_empresa, valor, cnpj_cpf])

def extrair_nome_empresa(texto):
    """Extrai o nome da empresa do texto, removendo CNPJ/CPF"""
    # Remove CNPJ/CPF
    texto_limpo = re.sub(r'\d{2}\.\d{3}\.\d{3}/\d{4}-\d{2}', '', texto)
    texto_limpo = re.sub(r'\d{3}\.\d{3}\.\d{3}-\d{2}', '', texto_limpo)
    # Remove caracteres especiais e espaços extras
    texto_limpo = re.sub(r'[^\w\s]', '', texto_limpo)
    texto_limpo = re.sub(r'\s+', ' ', texto_limpo).strip()
    return texto_limpo

def parse_data(data):
    try:
        return datetime.datetime.strptime(data, "%d/%m/%Y")
    except ValueError:
        return datetime.datetime(1900, 1, 1)

# Ordenar por data
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
        data, historico, nome_empresa, valor, cnpj_cpf = l
        
        # Extrair nome da empresa e CNPJ/CPF
        nome_limpo = extrair_nome_empresa(nome_empresa) if nome_empresa else extrair_nome_empresa(historico)
        cnpj_cpf_final = cnpj_cpf if cnpj_cpf else extrair_cnpj_cpf(historico)
        
        # Aplicar lógica de contas baseada no valor
        if valor > 0:
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
        if cnpj_cpf_final:
            historico_final += f" {cnpj_cpf_final}"
        
        writer.writerow([
            data,                    # Data do Lançamento
            'Sistema',               # Usuário
            conta_debito,            # Conta Débito
            conta_credito,           # Conta Crédito
            formatar_valor_brl(valor),  # Valor do Lançamento
            historico_final,         # Histórico
            '',                      # Código da Filial/Matriz
            nome_limpo,              # Nome da Empresa
            '',                      # Número da Nota
            cnpj_cpf_final or '',    # CNPJ/CPF
        ])

print(f'CSV padronizado gerado em: {csv_path}')
print(f'Total de lançamentos processados: {len(lancamentos)}') 