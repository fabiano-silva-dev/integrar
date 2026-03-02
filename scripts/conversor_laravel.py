#!/usr/bin/env python3
"""
Conversor Excel para CSV otimizado para Laravel
===============================================

Este script é otimizado para ser chamado pelo Laravel via exec()
"""

import pandas as pd
import sys
import os
import json
from datetime import datetime

class ConversorLaravel:
    """Conversor otimizado para Laravel"""
    
    def __init__(self):
        self.resultado = {
            'sucesso': False,
            'mensagem': '',
            'arquivo_saida': '',
            'tipos_detectados': {},
            'resumo': {}
        }
    
    def converter(self, arquivo_entrada, arquivo_saida, delimitador=','):
        """
        Converte Excel para CSV e retorna resultado em JSON
        """
        try:
            # Verificar arquivo de entrada
            if not os.path.exists(arquivo_entrada):
                self.resultado['mensagem'] = f"Arquivo não encontrado: {arquivo_entrada}"
                return self._retornar_json()
            
            # Determinar engine baseado na extensão
            extensao = os.path.splitext(arquivo_entrada)[1].lower()
            
            # Para arquivos CSV, usar read_csv com detecção de encoding
            if extensao == '.csv':
                # Tentar diferentes encodings
                encodings = ['utf-8', 'latin-1', 'cp1252', 'iso-8859-1']
                df = None
                
                for encoding in encodings:
                    try:
                        df = pd.read_csv(arquivo_entrada, sep=delimitador, encoding=encoding)
                        break
                    except UnicodeDecodeError:
                        continue
                
                if df is None:
                    raise Exception("Não foi possível decodificar o arquivo CSV com nenhum encoding testado")
            else:
                # Para arquivos Excel: openpyxl (.xlsx), xlrd (.xls), ou auto-detect
                df = None
                extensao_lower = extensao
                
                # Tentativa 1: .xlsx com openpyxl
                if extensao_lower == '.xlsx':
                    try:
                        df = pd.read_excel(arquivo_entrada, engine='openpyxl')
                    except Exception as e:
                        raise Exception(f"Erro ao ler XLSX: {str(e)}")
                
                # Tentativa 2: .xls - tentar xlrd, depois auto-detect (Excel 2003 XML, etc)
                elif extensao_lower == '.xls':
                    engines_tentados = []
                    # xlrd suporta .xls binário (xlrd < 2.0)
                    try:
                        df = pd.read_excel(arquivo_entrada, engine='xlrd')
                        engines_tentados.append('xlrd')
                    except Exception as e1:
                        engines_tentados.append(f'xlrd: {str(e1)}')
                        # Fallback: pandas auto-detect (pode funcionar com Excel 2003 XML)
                        try:
                            df = pd.read_excel(arquivo_entrada)
                            engines_tentados.append('auto')
                        except Exception as e2:
                            # Última tentativa: openpyxl (alguns .xls são na verdade XML)
                            try:
                                df = pd.read_excel(arquivo_entrada, engine='openpyxl')
                                engines_tentados.append('openpyxl')
                            except Exception as e3:
                                raise Exception(
                                    f"Não foi possível ler o arquivo .xls. "
                                    f"Tente salvar como .xlsx no Excel. Detalhes: {str(e2)}"
                                )
                
                # Tentativa 3: outras extensões Excel
                else:
                    try:
                        df = pd.read_excel(arquivo_entrada, engine='openpyxl')
                    except Exception:
                        try:
                            df = pd.read_excel(arquivo_entrada)
                        except Exception as e2:
                            raise Exception(f"Erro ao ler arquivo Excel: {str(e2)}")
            
            # Detectar tipos
            tipos = self._detectar_tipos(df)
            
            # Converter datas
            df = self._converter_datas(df, tipos)
            
            # Salvar CSV
            df.to_csv(arquivo_saida, index=False, sep=delimitador, encoding='utf-8')
            
            # Preparar resultado
            self.resultado['sucesso'] = True
            self.resultado['mensagem'] = 'Conversão realizada com sucesso'
            self.resultado['arquivo_saida'] = arquivo_saida
            self.resultado['tipos_detectados'] = tipos
            self.resultado['resumo'] = {
                'linhas': len(df),
                'colunas': len(df.columns),
                'colunas_data': len([t for t in tipos.values() if 'data' in str(t)]),
                'colunas_numero': len([t for t in tipos.values() if 'numero' in str(t)]),
                'colunas_texto': len([t for t in tipos.values() if 'texto' in str(t)])
            }
            
        except Exception as e:
            self.resultado['mensagem'] = f"Erro: {str(e)}"
        
        return self._retornar_json()
    
    def _detectar_tipos(self, df):
        """Detecta tipos de dados das colunas"""
        tipos = {}
        
        for coluna in df.columns:
            if pd.api.types.is_datetime64_any_dtype(df[coluna]):
                tipos[coluna] = 'data'
            elif pd.api.types.is_numeric_dtype(df[coluna]):
                tipos[coluna] = 'numero'
            elif pd.api.types.is_bool_dtype(df[coluna]):
                tipos[coluna] = 'booleano'
            elif pd.api.types.is_categorical_dtype(df[coluna]):
                tipos[coluna] = 'categoria'
            else:
                # Verificar se parece ser data
                if self._parece_ser_data(df[coluna]):
                    tipos[coluna] = 'data_texto'
                else:
                    tipos[coluna] = 'texto'
        
        return tipos
    
    def _parece_ser_data(self, serie):
        """Verifica se parece ser data"""
        if serie.empty:
            return False
        
        amostra = serie.dropna().head(5)
        if amostra.empty:
            return False
        
        # Padrões de data comuns
        import re
        padroes = [
            r'\d{1,2}/\d{1,2}/\d{2,4}',
            r'\d{1,2}-\d{1,2}-\d{2,4}',
            r'\d{4}-\d{1,2}-\d{1,2}'
        ]
        
        for padrao in padroes:
            if amostra.astype(str).str.match(padrao).any():
                return True
        
        return False
    
    def _converter_datas(self, df, tipos):
        """Converte colunas de data"""
        for coluna, tipo in tipos.items():
            if 'data' in str(tipo):
                try:
                    if tipo == 'data_texto':
                        df[coluna] = pd.to_datetime(df[coluna], errors='coerce')
                    
                    # Formatar como dd/mm/yyyy
                    df[coluna] = df[coluna].dt.strftime('%d/%m/%Y')
                except:
                    pass  # Manter original se der erro
        
        return df
    
    def _retornar_json(self):
        """Retorna resultado em JSON"""
        return json.dumps(self.resultado, ensure_ascii=False)

def main():
    """Função principal para uso via linha de comando"""
    if len(sys.argv) < 3:
        print("Uso: python conversor_laravel.py entrada.xlsx saida.csv [delimitador]")
        sys.exit(1)
    
    entrada = sys.argv[1]
    saida = sys.argv[2]
    delimitador = sys.argv[3] if len(sys.argv) > 3 else ','
    
    conversor = ConversorLaravel()
    resultado = conversor.converter(entrada, saida, delimitador)
    
    print(resultado)

if __name__ == "__main__":
    main()


