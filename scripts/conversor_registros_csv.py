import sys
import pandas as pd
import re
import os
from datetime import datetime

def valor_para_float(valor):
    """Converte valor para float, tratando formato brasileiro"""
    if pd.isna(valor) or valor == '' or valor == '""':
        return 0.0
    
    # Remove aspas e R$ se existirem
    valor_str = str(valor).replace('R$', '').replace('"', '').strip()
    
    # Se estiver vazio após limpeza
    if not valor_str:
        return 0.0
    
    # Substitui vírgula por ponto para conversão
    valor_str = valor_str.replace('.', '').replace(',', '.')
    
    try:
        return float(valor_str)
    except ValueError:
        return 0.0

def formatar_valor_brl(valor):
    """Formata valor para padrão brasileiro"""
    try:
        return f"{float(valor):,.2f}".replace('.', 'X').replace(',', '.').replace('X', ',')
    except Exception:
        return "0,00"

def processar_documento(documento):
    """Processa documento conforme regras especificadas"""
    if pd.isna(documento) or documento == '' or documento == '""':
        return ''
    
    documento_str = str(documento).strip()
    
    # Se começar com número, adiciona NF
    if documento_str and documento_str[0].isdigit():
        return f"NF {documento_str}"
    
    return documento_str

def gerar_historico(tipo, documento, pessoa):
    """Gera histórico conforme formato especificado"""
    doc_processado = processar_documento(documento)
    
    if tipo == 'entrada':
        historico = f"RCTO REF {doc_processado} {pessoa}".strip()
    else:  # saida
        historico = f"PGTO CFE {doc_processado} {pessoa}".strip()
    
    # Remover espaços duplos
    historico = re.sub(r'\s+', ' ', historico)
    return historico

def processar_registros(csv_path, output_path=None, conta_banco=None):
    """Processa arquivo registros.csv e gera CSV no formato padrão"""
    
    # Ler o arquivo CSV, pulando a primeira linha (Cadastros)
    df = pd.read_csv(csv_path, sep=';', skiprows=1)
    
    # Usar conta do banco fornecida ou padrão
    conta_banco_padrao = conta_banco if conta_banco else '1.1.01.001'
    
    lancamentos = []
    
    # Processar apenas linhas que começam com data (formato DD/MM/YYYY)
    for index, row in df.iterrows():
        data_str = str(row['Data']).strip()
        
        # Verificar se a linha começa com data válida
        if not re.match(r'^\d{2}/\d{2}/\d{4}$', data_str):
            continue
            
        # Verificar se não é linha de total
        if data_str == 'Total':
            continue
            
        pessoa = str(row['Pessoa']).strip() if not pd.isna(row['Pessoa']) else ''
        documento = str(row['Documento']).strip() if not pd.isna(row['Documento']) else ''
        entrada = valor_para_float(row['Entrada (R$)'])
        saida = valor_para_float(row['Saída (R$)'])
        
        # Processar entrada (se houver valor)
        if entrada > 0:
            lancamentos.append({
                'Data': data_str,
                'Histórico': gerar_historico('entrada', documento, pessoa),
                'Conta Débito': conta_banco_padrao,  # Conta do banco
                'Conta Crédito': '',  # Vazio para usuário preencher
                'Valor': formatar_valor_brl(entrada),
                'Nome da Empresa': pessoa  # Pessoa do CSV para coluna Terceiro
            })
        
        # Processar saída (se houver valor)
        if saida > 0:
            lancamentos.append({
                'Data': data_str,
                'Histórico': gerar_historico('saida', documento, pessoa),
                'Conta Débito': '',  # Vazio para usuário preencher
                'Conta Crédito': conta_banco_padrao,  # Conta do banco
                'Valor': formatar_valor_brl(saida),
                'Nome da Empresa': pessoa  # Pessoa do CSV para coluna Terceiro
            })
    
    # Criar DataFrame de saída
    if lancamentos:
        df_saida = pd.DataFrame(lancamentos)
        
        # Ordenar por data
        df_saida['Data'] = pd.to_datetime(df_saida['Data'], format='%d/%m/%Y')
        df_saida = df_saida.sort_values('Data')
        df_saida['Data'] = df_saida['Data'].dt.strftime('%d/%m/%Y')
        
        # Renomear colunas para o formato esperado pelo importador
        df_saida = df_saida.rename(columns={
            'Data': 'Data do Lançamento',
            'Histórico': 'Histórico',
            'Conta Débito': 'Conta Débito',
            'Conta Crédito': 'Conta Crédito',
            'Valor': 'Valor do Lançamento'
        })
        
        # Adicionar colunas obrigatórias que estão faltando
        df_saida['Usuário'] = 'Sistema'
        df_saida['Código da Filial/Matriz'] = '0000001'
        df_saida['Número da Nota'] = ''
        
        # Reordenar colunas na sequência esperada
        colunas_ordenadas = [
            'Data do Lançamento',
            'Usuário', 
            'Conta Débito',
            'Conta Crédito',
            'Valor do Lançamento',
            'Histórico',
            'Código da Filial/Matriz',
            'Nome da Empresa',
            'Número da Nota'
        ]
        df_saida = df_saida[colunas_ordenadas]
        
        # Definir caminho de saída
        if not output_path:
            base_name = os.path.basename(csv_path)
            nome_sem_ext = os.path.splitext(base_name)[0]
            output_path = f"padrao-{nome_sem_ext}.csv"
        
        # Salvar arquivo
        df_saida.to_csv(output_path, index=False, sep=';')
        print(f"Arquivo CSV gerado: {output_path}")
        print(f"Total de lançamentos processados: {len(lancamentos)}")
    else:
        print("Nenhum lançamento válido encontrado no arquivo.")

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Uso: python conversor_registros_csv.py <arquivo_entrada.csv> [arquivo_saida.csv] [conta_banco]")
        sys.exit(1)
    
    csv_path = sys.argv[1]
    output_path = sys.argv[2] if len(sys.argv) > 2 else None
    conta_banco = sys.argv[3] if len(sys.argv) > 3 else None
    
    try:
        processar_registros(csv_path, output_path, conta_banco)
    except Exception as e:
        print(f"Erro ao processar arquivo: {e}")
        sys.exit(1)
