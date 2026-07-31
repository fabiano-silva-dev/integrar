#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Converte extrato PDF para OFX conforme o layout do importador avançado.
"""

import os
import subprocess
import sys
import tempfile

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

LAYOUTS = {
    'sicoob': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_sicoob_pdf_ofx.py',
    },
    'grafeno': {
        'tipo': 'csv',
        'script_csv': 'conversor_extrato_grafeno_pdf_csv.py',
    },
    'sicredi': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_sicredi_pdf_ofx.py',
    },
    'caixa_federal': {
        'tipo': 'csv',
        'script_csv': 'caixa_extrato_layout.py',
    },
    'caixa': {
        'tipo': 'csv',
        'script_csv': 'caixa_extrato_layout.py',
    },
    'santander': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_santander_pdf_ofx.py',
    },
    'itau': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_itau_pdf_ofx.py',
    },
    'bradesco': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_bradesco_pdf_ofx.py',
    },
    'cresol': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_cresol_pdf_ofx.py',
    },
    'cresol_modelo2': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_cresol_modelo2_pdf_ofx.py',
    },
    'banco_brasil': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_banco_brasil_pdf_ofx.py',
    },
    'banrisul': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_banrisul_pdf_ofx.py',
    },
    'nubank': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_nubank_pdf_ofx.py',
    },
    'infinitepay': {
        'tipo': 'ofx_direto',
        'script': 'conversor_extrato_infinitepay_pdf_ofx.py',
    },
}


def executar(script, args):
    comando = ['python3', os.path.join(SCRIPT_DIR, script), *args]
    resultado = subprocess.run(comando, capture_output=True, text=True)
    saida = (resultado.stdout or '') + (resultado.stderr or '')
    if resultado.returncode != 0:
        raise RuntimeError(saida.strip() or f'Falha ao executar {script}')
    print(saida.rstrip())
    return saida


def converter(layout, caminho_pdf, caminho_ofx, caminho_preview=None):
    config = LAYOUTS.get(layout)
    if not config:
        raise ValueError(f'Layout não suportado para conversão PDF→OFX: {layout}')

    if config['tipo'] == 'ofx_direto':
        args = [caminho_pdf, caminho_ofx]
        if caminho_preview:
            args.append(caminho_preview)
        executar(config['script'], args)
        return

    with tempfile.NamedTemporaryFile(suffix='.csv', delete=False) as temp_csv:
        caminho_csv = temp_csv.name

    try:
        executar(config['script_csv'], [caminho_pdf, caminho_csv, '0'])
        executar('conversor_csv_padrao_ofx.py', [caminho_csv, caminho_ofx, layout])
    finally:
        if os.path.exists(caminho_csv):
            os.remove(caminho_csv)


def main():
    if len(sys.argv) < 4:
        print('Uso: python conversor_extrato_pdf_ofx.py <layout> <arquivo.pdf> <arquivo.ofx>')
        sys.exit(1)

    layout = sys.argv[1]
    caminho_pdf = sys.argv[2]
    caminho_ofx = sys.argv[3]
    caminho_preview = sys.argv[4] if len(sys.argv) > 4 else None

    if not os.path.exists(caminho_pdf):
        print(f"Erro: O arquivo '{caminho_pdf}' não existe.")
        sys.exit(1)

    try:
        converter(layout, caminho_pdf, caminho_ofx, caminho_preview)
    except Exception as erro:
        print(f'Erro na conversão: {erro}')
        sys.exit(1)


if __name__ == '__main__':
    main()
