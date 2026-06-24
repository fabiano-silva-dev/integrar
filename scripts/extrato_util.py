#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Utilitários compartilhados para conversão de extratos bancários."""

import json
import re

_PADROES_SALDO = (
    r'^SALDO\s+ANTERIOR',
    r'^SALDO\s+ANT\b',
    r'^SALDO\s+NA\s+DATA',
    r'^SALDO\s+DO\s+DIA',
    r'^SALDO\s+FINAL',
    r'^SALDO\s+INICIAL',
    r'^SALDO\s+TOTAL',
    r'^SALDO\s+EM\s+CONTA',
    r'^SALDO\s+DISPON',
    r'^SALDO\s*$',
)

_compiled = tuple(re.compile(p, re.IGNORECASE) for p in _PADROES_SALDO)


def eh_descricao_saldo(descricao):
    """
    Retorna True se a descrição representa saldo de extrato (não movimentação).
    """
    texto = (descricao or '').strip()
    if not texto:
        return False

    upper = texto.upper()
    if any(p.search(upper) for p in _compiled):
        return True

    return bool(re.match(r'^SALDO(\s|$)', upper))


def montar_preview_lancamentos(transacoes):
    preview = []
    for item in transacoes:
        preview.append({
            'data': item['data'],
            'historico': item.get('memo', item.get('historico', '')),
            'valor': round(float(item['valor']), 2),
        })
    return preview


def gravar_preview_json(transacoes, caminho_preview):
    preview = montar_preview_lancamentos(transacoes)
    if caminho_preview:
        with open(caminho_preview, 'w', encoding='utf-8') as arquivo:
            json.dump(preview, arquivo, ensure_ascii=False)
    return preview
