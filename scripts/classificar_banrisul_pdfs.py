#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Classifica PDFs Banrisul em extrato, relatório PIX ou pagamentos de títulos.
Uso: python classificar_banrisul_pdfs.py <pdf1> <pdf2> <pdf3>
Saída: JSON em stdout.
"""

import json
import os
import sys

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

from conversor_extrato_banrisul_pdf_csv import extrair_texto_pdf  # noqa: E402

TIPOS = ('extrato', 'pix', 'pagamentos')

ROTULOS = {
    'extrato': 'Extrato',
    'pix': 'Relatório de PIX',
    'pagamentos': 'Relatório de pagamentos',
}


def pontuar_conteudo(texto):
    texto = (texto or '').upper()
    scores = {tipo: 0 for tipo in TIPOS}

    if 'MOVIMENTOS DA CONTA CORRENTE' in texto:
        scores['extrato'] += 12
    if 'DIA HISTORICO DOCUMENTO' in texto or 'DIA HISTÓRICO DOCUMENTO' in texto:
        scores['extrato'] += 8
    if 'SALDO ANT EM' in texto or 'SALDO NA DATA' in texto:
        scores['extrato'] += 4
    if 'AGENCIA:' in texto and 'CONTA' in texto:
        scores['extrato'] += 2

    if 'OPERAÇÕES PIX' in texto or 'OPERACOES PIX' in texto:
        scores['pix'] += 12
    if 'EFETIVADO PARA' in texto or 'EFETIVADO DE' in texto:
        scores['pix'] += 6
    if 'PAGADOR/RECEBEDOR' in texto:
        scores['pix'] += 4
    if texto.count('ENVIADO') + texto.count('RECEBIDO') >= 2 and 'PIX' in texto:
        scores['pix'] += 3

    if 'CONSULTA OPERAÇÕES' in texto or 'CONSULTA OPERACOES' in texto:
        scores['pagamentos'] += 8
    if 'EFETUADA' in texto and ('TÍTULO' in texto or 'TITULO' in texto):
        scores['pagamentos'] += 12
    if 'EMITE RECIBOS' in texto:
        scores['pagamentos'] += 4
    if 'COMPLEMENTO' in texto and 'NSU' in texto:
        scores['pagamentos'] += 3

    return scores


def classificar_arquivo(caminho):
    linhas = extrair_texto_pdf(caminho)
    amostra = '\n'.join(linhas[:120])
    scores = pontuar_conteudo(amostra)
    melhor = max(scores, key=scores.get)
    return {
        'caminho': caminho,
        'nome': os.path.basename(caminho),
        'scores': scores,
        'tipo_sugerido': melhor if scores[melhor] > 0 else None,
        'score': scores[melhor],
    }


def atribuir_tipos(candidatos):
    """Atribui um tipo único a cada arquivo pelo maior score disponível."""
    disponiveis = set(TIPOS)
    ordenados = sorted(candidatos, key=lambda item: item['score'], reverse=True)
    atribuicoes = {}
    usados = set()

    for item in ordenados:
        ranking = sorted(item['scores'].items(), key=lambda par: par[1], reverse=True)
        for tipo, pontos in ranking:
            if tipo in disponiveis and pontos > 0:
                atribuicoes[tipo] = item
                disponiveis.remove(tipo)
                usados.add(id(item))
                break

    faltando = sorted(disponiveis)
    nao_usados = [item for item in candidatos if id(item) not in usados]

    return atribuicoes, faltando, nao_usados


def classificar_tres(caminhos):
    if len(caminhos) != 3:
        return {
            'ok': False,
            'erro': 'Envie exatamente 3 PDFs: extrato, PIX e pagamentos de títulos.',
        }

    for caminho in caminhos:
        if not os.path.exists(caminho):
            return {
                'ok': False,
                'erro': f"Arquivo não encontrado: {caminho}",
            }
        if not caminho.lower().endswith('.pdf'):
            return {
                'ok': False,
                'erro': f"Arquivo inválido (não é PDF): {os.path.basename(caminho)}",
            }

    candidatos = [classificar_arquivo(caminho) for caminho in caminhos]
    atribuicoes, faltando, nao_usados = atribuir_tipos(candidatos)

    if faltando:
        nomes_faltando = ', '.join(ROTULOS[tipo] for tipo in faltando)
        nomes_problemas = ', '.join(item['nome'] for item in nao_usados) or 'arquivo(s) enviado(s)'
        return {
            'ok': False,
            'erro': (
                f'Não foi possível identificar: {nomes_faltando}. '
                f'Verifique o conteúdo de: {nomes_problemas}.'
            ),
            'candidatos': [
                {
                    'nome': item['nome'],
                    'scores': item['scores'],
                    'tipo_sugerido': item['tipo_sugerido'],
                }
                for item in candidatos
            ],
        }

    arquivos = {}
    for tipo in TIPOS:
        item = atribuicoes[tipo]
        arquivos[tipo] = {
            'indice': caminhos.index(item['caminho']),
            'nome': item['nome'],
            'caminho': item['caminho'],
            'score': item['score'],
        }

    return {
        'ok': True,
        'arquivos': arquivos,
    }


def main():
    args = sys.argv[1:]
    if len(args) == 1:
        item = classificar_arquivo(args[0])
        tipo = item.get('tipo_sugerido')
        print(json.dumps({
            'ok': bool(tipo),
            'tipo': tipo,
            'scores': item.get('scores', {}),
            'nome': item.get('nome'),
            'erro': None if tipo else 'Não foi possível identificar o tipo deste PDF.',
        }, ensure_ascii=False))
        sys.exit(0 if tipo else 2)

    if len(args) < 3:
        print(json.dumps({
            'ok': False,
            'erro': 'Uso: classificar_banrisul_pdfs.py <pdf> | <pdf1> <pdf2> <pdf3>',
        }, ensure_ascii=False))
        sys.exit(1)

    resultado = classificar_tres(args[:3])
    print(json.dumps(resultado, ensure_ascii=False))
    sys.exit(0 if resultado.get('ok') else 2)


if __name__ == '__main__':
    main()
