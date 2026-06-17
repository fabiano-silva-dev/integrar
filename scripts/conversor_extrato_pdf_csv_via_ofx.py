#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Converte extrato PDF para CSV via OFX (layouts com conversor pdf_ofx).
Usado na importação de extratos para bancos sem conversor pdf_csv dedicado.
"""

import os
import subprocess
import sys
import tempfile

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

LAYOUTS_VIA_OFX = frozenset({
    'santander',
    'itau',
    'bradesco',
    'cresol',
    'banco_brasil',
})


def executar(script, args):
    comando = ['python3', os.path.join(SCRIPT_DIR, script), *args]
    resultado = subprocess.run(comando, capture_output=True, text=True)
    saida = (resultado.stdout or '') + (resultado.stderr or '')
    if resultado.returncode != 0:
        raise RuntimeError(saida.strip() or f'Falha ao executar {script}')
    print(saida.rstrip())
    return saida


def converter(layout, caminho_pdf, caminho_csv, conta_banco='1.1.1.01'):
    if layout not in LAYOUTS_VIA_OFX:
        raise ValueError(f'Layout não suportado para conversão PDF→CSV via OFX: {layout}')

    with tempfile.NamedTemporaryFile(suffix='.ofx', delete=False) as temp_ofx:
        caminho_ofx = temp_ofx.name

    try:
        executar('conversor_extrato_pdf_ofx.py', [layout, caminho_pdf, caminho_ofx])
        executar('conversor_ofx_csv.py', [caminho_ofx, caminho_csv, conta_banco])
    finally:
        if os.path.exists(caminho_ofx):
            os.remove(caminho_ofx)


def main():
    if len(sys.argv) < 4:
        print('Uso: python conversor_extrato_pdf_csv_via_ofx.py <layout> <arquivo.pdf> <arquivo.csv> [conta_banco]')
        sys.exit(1)

    layout = sys.argv[1]
    caminho_pdf = sys.argv[2]
    caminho_csv = sys.argv[3]
    conta_banco = sys.argv[4] if len(sys.argv) > 4 else '1.1.1.01'

    if not os.path.exists(caminho_pdf):
        print(f"Erro: O arquivo '{caminho_pdf}' não existe.")
        sys.exit(1)

    try:
        converter(layout, caminho_pdf, caminho_csv, conta_banco)
    except Exception as erro:
        print(f'Erro na conversão: {erro}')
        sys.exit(1)


if __name__ == '__main__':
    main()
