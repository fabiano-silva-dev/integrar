import sys
import pandas as pd
import re
import os

# Função para converter o valor para float
def valor_para_float(valor):
    if pd.isna(valor):
        return 0.0
    valor = str(valor).replace('R$', '').replace('.', '').replace(',', '.').strip()
    try:
        return float(valor)
    except ValueError:
        return 0.0

# Função para extrair o nome após ' para ' em caixa alta
def extrair_nome_para(historico):
    if pd.isna(historico):
        return ''
   
    match = re.search(r' para ([A-Z0-9 \-\.]+)', historico)
    if match:
        return match.group(1).strip()
    return ''

def formatar_valor_brl(valor):
    try:
        return f"{float(valor):,.2f}".replace('.', 'X').replace(',', '.').replace('X', ',')
    except Exception:
        return "0,00"

def processar_lancamentos(csv_path, output_path=None, codigo_empresa=None):
    df = pd.read_csv(csv_path)
    lancamentos = []
    i = 0
    while i < len(df):
        linha1 = df.iloc[i]
        data = linha1['lançamentos_data']
        historico = linha1['lançamentos_histórico'] if not pd.isna(linha1['lançamentos_histórico']) and str(linha1['lançamentos_histórico']).strip() != '' else 'VLR REF'
        valor1 = valor_para_float(linha1['partidas_valor'])
        conta1_tipo = linha1['partidas_tipo']
        conta1_cl = linha1['partidas_classificacao_da_conta']
        conta1_nome = linha1['partidas_conta']
        nome_extra = extrair_nome_para(historico)
        if i+1 >= len(df):
            break
        linha2 = df.iloc[i+1]
        valor2 = valor_para_float(linha2['partidas_valor'])
        conta2_tipo = linha2['partidas_tipo']
        conta2_cl = linha2['partidas_classificacao_da_conta']
        conta2_nome = linha2['partidas_conta']
        if abs(valor1 - valor2) < 0.01:
            if conta1_tipo == 'Credito':
                conta_credito = conta1_cl
                conta_debito = conta2_cl
            else:
                conta_credito = conta2_cl
                conta_debito = conta1_cl
            lancamentos.append({
                'Data': data,
                'Histórico': historico,
                'Conta Débito': conta_debito,
                'Conta Crédito': conta_credito,
                'Valor': formatar_valor_brl(valor1)
            })
            i += 2
        else:
            if conta1_tipo == 'Credito':
                conta_credito = conta1_cl
                valor_credito = valor1
                conta_debito = conta2_cl
                valor_debito = valor2
                i += 2
            else:
                conta_credito = conta2_cl
                valor_credito = valor2
                conta_debito = conta1_cl
                valor_debito = valor1
                i += 2
            lancamentos.append({
                'Data': data,
                'Histórico': historico,
                'Conta Débito': conta_debito,
                'Conta Crédito': conta_credito,
                'Valor': formatar_valor_brl(valor_debito)
            })
            soma_debitos = valor_debito
            while i < len(df) and abs(soma_debitos - valor_credito) > 0.01:
                linha_debito = df.iloc[i]
                if linha_debito['partidas_tipo'] == 'Credito':
                    break
                valor_debito = valor_para_float(linha_debito['partidas_valor'])
                conta_debito = linha_debito['partidas_classificacao_da_conta']
                lancamentos.append({
                    'Data': data,
                    'Histórico': historico,
                    'Conta Débito': conta_debito,
                    'Conta Crédito': conta_credito,
                    'Valor': formatar_valor_brl(valor_debito)
                })
                soma_debitos += valor_debito
                i += 1
    df_saida = pd.DataFrame(lancamentos)
    
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
    # Usar o código da empresa se fornecido, senão usar padrão
    codigo_filial = codigo_empresa if codigo_empresa else '0000001'
    df_saida['Código da Filial/Matriz'] = codigo_filial
    df_saida['Nome da Empresa'] = ''
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
    
    df_saida['Data do Lançamento'] = pd.to_datetime(df_saida['Data do Lançamento'], format='%d/%m/%Y')
    df_saida = df_saida.sort_values('Data do Lançamento')
    df_saida['Data do Lançamento'] = df_saida['Data do Lançamento'].dt.strftime('%d/%m/%Y')
    if not output_path:
        base_name = os.path.basename(csv_path)
        nome_sem_ext = os.path.splitext(base_name)[0]
        output_path = f"padrao-{nome_sem_ext}.csv"
    df_saida.to_csv(output_path, index=False, sep=';')
    print(f"Arquivo CSV gerado: {output_path}")

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Uso: python conversor_connectere_csv.py <arquivo_entrada.csv> [arquivo_saida.csv] [codigo_empresa]")
        sys.exit(1)
    csv_path = sys.argv[1]
    output_path = sys.argv[2] if len(sys.argv) > 2 else None
    codigo_empresa = sys.argv[3] if len(sys.argv) > 3 else None
    processar_lancamentos(csv_path, output_path, codigo_empresa) 