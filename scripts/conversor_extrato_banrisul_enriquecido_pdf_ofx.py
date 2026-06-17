#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Converte extrato Banrisul para OFX enriquecendo históricos com relatórios
de Pagamentos de Títulos e PIX (match por data + valor).
"""

import json
import os
import re
import sys
from datetime import datetime, timedelta

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

from conversor_extrato_banrisul_pdf_csv import (  # noqa: E402
    extrair_dados_conta_banrisul,
    extrair_saldo_final_banrisul,
    extrair_texto_pdf,
    montar_memo_banrisul,
    parsear_lancamentos,
)
from gerador_ofx import gerar_arquivo_ofx  # noqa: E402

PADRAO_PAGAMENTO_LINHA = re.compile(
    r'^\d+\s+EFETUADA\s+R\$\s*([\d.]+,\d{2})\s+Título',
    re.IGNORECASE,
)
PADRAO_DATA = re.compile(r'^(\d{2}/\d{2}/\d{4})$')
PADRAO_BENEFICIARIO = re.compile(r'^(.+?)\s*-\s*(\d{44,47})$')
PADRAO_PIX_DATA_VALOR = re.compile(
    r'^Efetivado\s+(?:para|de)?\s*(.*?)\s*-\s*(\d{2}/\d{2}/\d{4})\s*$',
    re.IGNORECASE,
)
PADRAO_PIX_DATA_VALOR_RECEBIDO = re.compile(
    r'^Efetivado\s+-\s*(\d{2}/\d{2}/\d{4})\s+R\$\s*([\d.]+,\d{2})\s*$',
    re.IGNORECASE,
)
PADRAO_PIX_VALOR_ENVIADO = re.compile(
    r'^(?:Enviado|Recebido)\s+([\d.]+,\d{2})\s*$',
    re.IGNORECASE,
)
PADRAO_PAGINA = re.compile(r'^https?://', re.IGNORECASE)


def parsear_valor_brl(valor_str):
    return float(valor_str.replace('.', '').replace(',', '.'))


def chave_match(data, valor):
    return data, round(abs(float(valor)), 2)


def extrair_valor_nominal_codigo(codigo):
    """Valor nominal do título na linha digitável (47) ou código de barras (44)."""
    codigo = re.sub(r'\D', '', codigo or '')
    if len(codigo) == 47:
        return int(codigo[37:47]) / 100
    if len(codigo) == 44:
        return int(codigo[9:19]) / 100
    if len(codigo) > 10:
        return int(codigo[-10:]) / 100
    return None


def parsear_pagamentos_titulos(linhas):
    registros = []
    valor_pendente = None
    data_pendente = None

    for linha_bruta in linhas:
        linha = linha_bruta.strip()
        if not linha or PADRAO_PAGINA.match(linha):
            continue
        if linha.upper().startswith('DATA NSU') or linha.upper().startswith('COMPLEMENTO'):
            continue
        if 'Situação Operação Valor' in linha or linha.upper().startswith('SAC:'):
            continue

        match_valor = PADRAO_PAGAMENTO_LINHA.match(linha)
        if match_valor:
            valor_pendente = parsear_valor_brl(match_valor.group(1))
            data_pendente = None
            continue

        match_data = PADRAO_DATA.match(linha)
        if match_data and valor_pendente is not None:
            data_pendente = match_data.group(1)
            continue

        if valor_pendente is not None and data_pendente:
            match_benef = PADRAO_BENEFICIARIO.match(linha)
            if not match_benef:
                continue
            beneficiario = match_benef.group(1).strip()
            linha_digitavel = match_benef.group(2)
            valor_nominal = extrair_valor_nominal_codigo(linha_digitavel)
            if not beneficiario or valor_nominal is None:
                valor_pendente = None
                data_pendente = None
                continue
            encargos = max(0.0, round(valor_pendente - valor_nominal, 2))
            registros.append({
                'data': data_pendente,
                'valor_pago': valor_pendente,
                'valor': -valor_pendente,
                'valor_nominal': valor_nominal,
                'encargos': encargos,
                'beneficiario': beneficiario,
                'linha_digitavel': linha_digitavel,
                'tipo': 'pagamento_titulo',
            })
            valor_pendente = None
            data_pendente = None

    return registros


def parsear_pix(linhas):
    registros = []
    i = 0
    total = len(linhas)

    while i < total:
        linha = linhas[i].strip()
        i += 1

        if not linha or PADRAO_PAGINA.match(linha):
            continue
        if linha.upper().startswith('OPERAÇÃO') or linha.upper().startswith('BANCO DO ESTADO'):
            continue

        if linha.upper().startswith('PIX DE '):
            nome_partes = [linha[7:].strip()]
            while i < total:
                prox = linhas[i].strip()
                if PADRAO_PIX_DATA_VALOR_RECEBIDO.match(prox):
                    break
                if prox.upper().startswith('PIX '):
                    break
                nome_partes.append(prox)
                i += 1

            if i >= total:
                break

            match_recebido = PADRAO_PIX_DATA_VALOR_RECEBIDO.match(linhas[i].strip())
            if not match_recebido:
                continue

            data = match_recebido.group(1)
            valor = parsear_valor_brl(match_recebido.group(2))
            i += 1

            if i < total and linhas[i].strip().upper().startswith('RECEBIDO '):
                nome_partes.append(linhas[i].strip()[9:].strip())
                i += 1

            nome = ' '.join(part for part in nome_partes if part).strip()
            registros.append({
                'data': data,
                'valor': valor,
                'nome': nome,
                'tipo': 'pix_recebido',
            })
            continue

        if linha.upper().startswith('PIX R$'):
            if i >= total:
                break

            match_efetivado = PADRAO_PIX_DATA_VALOR.match(linhas[i].strip())
            if not match_efetivado:
                continue

            nome = match_efetivado.group(1).strip()
            data = match_efetivado.group(2)
            i += 1

            if i >= total:
                break

            match_valor = PADRAO_PIX_VALOR_ENVIADO.match(linhas[i].strip())
            if not match_valor:
                continue

            valor_str = match_valor.group(1)
            valor = parsear_valor_brl(valor_str)
            linha_valor = linhas[i].strip()
            i += 1

            if linha_valor.upper().startswith('ENVIADO'):
                valor = -valor
                tipo = 'pix_enviado'
            else:
                tipo = 'pix_recebido'

            registros.append({
                'data': data,
                'valor': valor,
                'nome': nome,
                'tipo': tipo,
            })

    return registros


def indexar_auxiliares(registros):
    indice = {}
    for registro in registros:
        chave = chave_match(registro['data'], registro['valor'])
        indice.setdefault(chave, []).append(registro)
    return indice


def consumir_registro(indice, chave):
    fila = indice.get(chave)
    if not fila:
        return None
    return fila.pop(0)


def eh_pagamento_titulo(descricao):
    descricao = descricao.upper()
    return 'PAGAMENTO TITULO' in descricao or ('PAGTO' in descricao and 'TITULO' in descricao)


def processar_lancamento(lancamento, pagamentos_idx, pix_idx):
    """Retorna lista de lançamentos para OFX/prévia (pode dividir pagamento + encargos)."""
    descricao = lancamento['descricao'].strip()
    descricao_upper = descricao.upper()
    valor = lancamento['valor']
    data = lancamento['data']
    documento = lancamento.get('documento', '')
    base = {
        'data': data,
        'historico_original': descricao,
        'documento': documento,
    }

    if eh_pagamento_titulo(descricao_upper):
        registro = consumir_registro(pagamentos_idx, chave_match(data, valor))
        if not registro:
            historico = montar_memo_banrisul(lancamento)
            return [{
                **base,
                'valor': valor,
                'historico': historico,
                'memo': historico,
                'enriquecido': False,
            }]

        beneficiario = registro['beneficiario']
        valor_pago = abs(float(valor))
        valor_nominal = float(registro['valor_nominal'])
        encargos = float(registro.get('encargos') or max(0.0, round(valor_pago - valor_nominal, 2)))

        if encargos > 0.009:
            return [
                {
                    **base,
                    'valor': round(-valor_nominal, 2),
                    'historico': f'PAGAMENTO TITULO - {beneficiario}',
                    'memo': f'PAGAMENTO TITULO - {beneficiario}',
                    'enriquecido': True,
                    'separado_encargos': True,
                },
                {
                    **base,
                    'valor': round(-encargos, 2),
                    'historico': f'JUROS/MULTA TITULO - {beneficiario}',
                    'memo': f'JUROS/MULTA TITULO - {beneficiario}',
                    'enriquecido': True,
                    'encargos': True,
                },
            ]

        historico = f'PAGAMENTO TITULO - {beneficiario}'
        return [{
            **base,
            'valor': valor,
            'historico': historico,
            'memo': historico,
            'enriquecido': True,
        }]

    if descricao_upper.startswith('PIX '):
        registro = consumir_registro(pix_idx, chave_match(data, valor))
        if registro and registro.get('nome'):
            tipo = 'ENVIADO' if valor < 0 else 'RECEBIDO'
            historico = f"PIX {tipo} - {registro['nome']}"
            return [{
                **base,
                'valor': valor,
                'historico': historico,
                'memo': historico,
                'enriquecido': True,
            }]
        if lancamento.get('nome'):
            tipo = 'ENVIADO' if valor < 0 else 'RECEBIDO'
            historico = f"PIX {tipo} - {lancamento['nome']}"
            return [{
                **base,
                'valor': valor,
                'historico': historico,
                'memo': historico,
                'enriquecido': False,
            }]

    historico = montar_memo_banrisul(lancamento)
    return [{
        **base,
        'valor': valor,
        'historico': historico,
        'memo': historico,
        'enriquecido': False,
    }]


def converter_banrisul_enriquecido(caminho_extrato, caminho_pix, caminho_pagamentos, caminho_ofx, caminho_preview=None):
    linhas_extrato = extrair_texto_pdf(caminho_extrato)
    if not linhas_extrato:
        raise ValueError('Não foi possível extrair texto do extrato PDF')

    dados_conta = extrair_dados_conta_banrisul(linhas_extrato)
    saldo_final = extrair_saldo_final_banrisul(linhas_extrato)

    lancamentos = parsear_lancamentos(linhas_extrato)
    if not lancamentos:
        raise ValueError('Nenhum lançamento válido encontrado no extrato')

    pagamentos = parsear_pagamentos_titulos(extrair_texto_pdf(caminho_pagamentos))
    pix_registros = parsear_pix(extrair_texto_pdf(caminho_pix))

    pagamentos_idx = indexar_auxiliares(pagamentos)
    pix_idx = indexar_auxiliares(pix_registros)

    lancamentos_ordenados = sorted(
        lancamentos,
        key=lambda item: datetime.strptime(item['data'], '%d/%m/%Y'),
    )

    transacoes = []
    preview = []
    total_enriquecidos = 0
    total_separados_encargos = 0

    for lancamento in lancamentos_ordenados:
        itens = processar_lancamento(lancamento, pagamentos_idx, pix_idx)
        for item in itens:
            if item.get('enriquecido'):
                total_enriquecidos += 1
            if item.get('separado_encargos'):
                total_separados_encargos += 1

            transacoes.append({
                'data': item['data'],
                'valor': item['valor'],
                'memo': item['memo'],
            })
            preview.append({
                'data': item['data'],
                'historico': item['historico'],
                'historico_original': item['historico_original'],
                'documento': item.get('documento', ''),
                'valor': round(item['valor'], 2),
                'enriquecido': item.get('enriquecido', False),
                'encargos': item.get('encargos', False),
            })

    if saldo_final is None:
        saldo_final = sum(item['valor'] for item in transacoes)

    primeira_data = transacoes[0]['data']
    ultima_data = transacoes[-1]['data']
    inicio = datetime.strptime(primeira_data, '%d/%m/%Y') - timedelta(days=1)
    data_inicial = inicio.strftime('%d/%m/%Y')
    data_final = ultima_data

    gerar_arquivo_ofx(
        transacoes=transacoes,
        output_path=caminho_ofx,
        bank_id='041',
        branch_id=dados_conta.get('branch_id', ''),
        acct_id=dados_conta['acct_id'],
        acct_type='CHECKING',
        balance=saldo_final,
        dtstart=data_inicial,
        dtend=data_final,
        org='Banrisul',
        fi_id='041',
    )

    if caminho_preview:
        with open(caminho_preview, 'w', encoding='utf-8') as arquivo:
            json.dump(preview, arquivo, ensure_ascii=False)

    return {
        'total_lancamentos': len(transacoes),
        'total_enriquecidos': total_enriquecidos,
        'total_separados_encargos': total_separados_encargos,
        'total_pagamentos_aux': len(pagamentos),
        'total_pix_aux': len(pix_registros),
        'saldo_final': saldo_final,
        'cooperativa': dados_conta['cooperativa'],
        'numero_conta': dados_conta['numero_conta'],
        'titular': dados_conta['titular'],
        'acct_id': dados_conta['acct_id'],
        'data_inicial': data_inicial,
        'data_final': data_final,
        'lancamentos': preview,
    }


def main():
    if len(sys.argv) < 5:
        print(
            'Uso: python conversor_extrato_banrisul_enriquecido_pdf_ofx.py '
            '<extrato.pdf> <pix.pdf> <pagamentos.pdf> <saida.ofx> [preview.json]'
        )
        sys.exit(1)

    caminho_extrato = sys.argv[1]
    caminho_pix = sys.argv[2]
    caminho_pagamentos = sys.argv[3]
    caminho_ofx = sys.argv[4]
    caminho_preview = sys.argv[5] if len(sys.argv) > 5 else None

    for rotulo, caminho in [
        ('extrato', caminho_extrato),
        ('pix', caminho_pix),
        ('pagamentos', caminho_pagamentos),
    ]:
        if not os.path.exists(caminho):
            print(f"Erro: arquivo {rotulo} '{caminho}' não existe.")
            sys.exit(1)

    try:
        resultado = converter_banrisul_enriquecido(
            caminho_extrato,
            caminho_pix,
            caminho_pagamentos,
            caminho_ofx,
            caminho_preview,
        )
        if resultado.get('cooperativa'):
            print(f"Agência extraída: {resultado['cooperativa']}")
        if resultado.get('numero_conta'):
            print(f"Conta extraída: {resultado['numero_conta']}")
        if resultado.get('titular'):
            print(f"Titular: {resultado['titular']}")
        print(f"ACCTID OFX: {resultado['acct_id']}")
        print(f"Data inicial: {resultado['data_inicial']}")
        print(f"Data final: {resultado['data_final']}")
        print(f"Total de lançamentos: {resultado['total_lancamentos']}")
        print(f"Lançamentos enriquecidos: {resultado['total_enriquecidos']}")
        print(f"Pagamentos separados (juros/multa): {resultado['total_separados_encargos']}")
        print(f"Registros PIX auxiliares: {resultado['total_pix_aux']}")
        print(f"Registros pagamentos auxiliares: {resultado['total_pagamentos_aux']}")
        print(f"Saldo final: R$ {resultado['saldo_final']:,.2f}")
        print(f"Arquivo OFX gerado em: {caminho_ofx}")
        if caminho_preview:
            print(f"Prévia JSON: {caminho_preview}")
    except Exception as erro:
        print(f'Erro na conversão: {erro}')
        sys.exit(1)


if __name__ == '__main__':
    main()
