#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script para importar PDF SICREDI e gerar CSV para o importador avançado.
Uso direto via terminal.
"""

import sys
from pathlib import Path

# Adicionar diretório do script ao path
SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

from conversor_extrato_sicredi_pdf_csv import main

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Uso: python importar_sicredi.py <arquivo.pdf> [conta_banco]")
        print("Exemplo: python importar_sicredi.py ~/Downloads/extrato_sicredi.pdf 1.1.1.01")
        print("\nO CSV será gerado no mesmo diretório do PDF com sufixo _importado.csv")
        sys.exit(1)
    
    pdf_path = Path(sys.argv[1]).expanduser().resolve()
    conta_banco = sys.argv[2] if len(sys.argv) > 2 else '1.1.1.01'
    
    if not pdf_path.exists():
        print(f"Erro: Arquivo não encontrado: {pdf_path}")
        sys.exit(1)
    
    csv_path = pdf_path.parent / f"{pdf_path.stem}_importado.csv"
    
    # Simular argumentos para o main do conversor
    sys.argv = [sys.argv[0], str(pdf_path), str(csv_path), conta_banco]
    main()
    
    print(f"\nPróximo passo: Acesse o Importador Avançado, selecione o layout")
    print(f"'SICREDI (PDF)', informe a conta do banco e faça upload")
    print(f"do arquivo CSV gerado: {csv_path}")
    print(f"\nOu faça upload direto do PDF no Importador Avançado com o layout selecionado.")
