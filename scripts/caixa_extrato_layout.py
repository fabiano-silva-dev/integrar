#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Identifica o layout do extrato PDF da Caixa e chama o motor de conversão.

Layouts:
- data_efetiva — paisagem, colunas Data / Data Efetiva
- internet_banking — retrato, Extrato por período (IB)
- historico — Extrato Histórico da Conta (modelo antigo / JasperReports)
"""

from __future__ import annotations

import os
import sys
from dataclasses import dataclass

from PyPDF2 import PdfReader

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

LAYOUT_DATA_EFETIVA = 'data_efetiva'
LAYOUT_INTERNET_BANKING = 'internet_banking'
LAYOUT_HISTORICO = 'historico'

ROTULOS = {
    LAYOUT_DATA_EFETIVA: 'data efetiva (paisagem)',
    LAYOUT_INTERNET_BANKING: 'Internet Banking (retrato)',
    LAYOUT_HISTORICO: 'Extrato Histórico da Conta',
}


@dataclass(frozen=True)
class SinaisLayout:
    paisagem: bool
    texto: str
    producer: str
    creator: str


def _ler_sinais(pdf_path: str) -> SinaisLayout:
    reader = PdfReader(pdf_path)
    if not reader.pages:
        return SinaisLayout(False, '', '', '')

    pagina = reader.pages[0]
    box = pagina.mediabox
    largura = float(box.width)
    altura = float(box.height)
    texto = (pagina.extract_text() or '').lower().replace('\xa0', ' ')

    meta = reader.metadata or {}
    producer = str(meta.get('/Producer') or meta.get('/producer') or '').lower()
    creator = str(meta.get('/Creator') or meta.get('/creator') or '').lower()

    return SinaisLayout(
        paisagem=largura > altura,
        texto=texto,
        producer=producer,
        creator=creator,
    )


def identificar_layout(pdf_path: str) -> str:
    """
    Retorna a chave do layout detectado pelos padrões do PDF.
    A ordem prioriza sinais mais específicos.
    """
    sinais = _ler_sinais(pdf_path)
    texto = sinais.texto

    if (
        sinais.paisagem
        or 'data efetiva' in texto
        or 'saldo anterior ao período solicitado' in texto
        or 'saldo anterior ao periodo solicitado' in texto
    ):
        return LAYOUT_DATA_EFETIVA

    if (
        'extrato histórico da conta' in texto
        or 'extrato historico da conta' in texto
        or 'jasperreports' in sinais.creator
        or 'jasperreports' in sinais.producer
    ):
        return LAYOUT_HISTORICO

    if (
        'extrato por período' in texto
        or 'extrato por periodo' in texto
        or 'inte-r_net' in texto
        or 'internet banking' in texto
        or 'data mov' in texto
    ):
        return LAYOUT_INTERNET_BANKING

    # Retrato sem marcas claras: histórico é o fallback do modelo antigo.
    return LAYOUT_HISTORICO


def _motor_data_efetiva():
    from conversor_extrato_caixa_data_efetiva_pdf_csv import converter

    return converter


def _motor_internet_banking():
    from conversor_extrato_caixa_pdf_csv import converter_internet_banking

    return converter_internet_banking


def _motor_historico():
    from conversor_extrato_caixa_federal_pdf_csv import converter_historico

    return converter_historico


MOTORES = {
    LAYOUT_DATA_EFETIVA: _motor_data_efetiva,
    LAYOUT_INTERNET_BANKING: _motor_internet_banking,
    LAYOUT_HISTORICO: _motor_historico,
}


def converter(pdf_path: str, csv_path: str, conta_banco: str = '1.1.1.01') -> int:
    """Identifica o padrão e chama o motor correspondente."""
    layout = identificar_layout(pdf_path)
    rotulo = ROTULOS.get(layout, layout)
    print(f'Layout Caixa identificado: {rotulo}.')

    obter_motor = MOTORES[layout]
    motor = obter_motor()
    return motor(pdf_path, csv_path, conta_banco)


def main():
    if len(sys.argv) < 3:
        print(
            'Uso: python caixa_extrato_layout.py '
            '<arquivo.pdf> <arquivo_saida.csv> [conta_banco]'
        )
        sys.exit(1)

    pdf_path = sys.argv[1]
    csv_path = sys.argv[2]
    conta_banco = sys.argv[3] if len(sys.argv) > 3 else '1.1.1.01'

    if not os.path.exists(pdf_path):
        print(f"Erro: O arquivo '{pdf_path}' não existe.")
        sys.exit(1)

    total = converter(pdf_path, csv_path, conta_banco)
    print(f'CSV padronizado gerado em: {csv_path}')
    print(f'Total de lançamentos processados: {total}')


if __name__ == '__main__':
    main()
