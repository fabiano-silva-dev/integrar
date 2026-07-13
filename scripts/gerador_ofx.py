#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Gera arquivos OFX no padrão XML usado pelo Sicoob / ferramentas de importação brasileiras.
Compatível com importação no Sistema Domínio (CHECKNUM + encoding Latin-1).
"""

from datetime import datetime, timedelta
import re
import unicodedata


def formatar_data_ofx(data_br):
    """Converte DD/MM/YYYY para o formato OFX com fuso horário."""
    dt = datetime.strptime(data_br.strip(), '%d/%m/%Y')
    return f"{dt.strftime('%Y%m%d')}000000.000[-3:-03]"


def texto_latin1(texto):
    """Normaliza texto para Latin-1 (ISO-8859-1), aceito pelo Domínio/Money."""
    if not texto:
        return ''

    texto = str(texto).replace('\n', ' ').replace('\r', ' ').replace('"', "'")
    texto = re.sub(r'\s+', ' ', texto).strip()

    # Substitui bullets e símbolos comuns do Nubank/PDF por ASCII.
    substituicoes = {
        '•': '*',
        '–': '-',
        '—': '-',
        '“': "'",
        '”': "'",
        '‘': "'",
        '’': "'",
        'º': 'o',
        'ª': 'a',
        '€': 'EUR',
        '™': '',
        '®': '',
    }
    for origem, destino in substituicoes.items():
        texto = texto.replace(origem, destino)

    normalizado = unicodedata.normalize('NFKC', texto)
    try:
        normalizado.encode('latin-1')
        return normalizado
    except UnicodeEncodeError:
        return normalizado.encode('latin-1', errors='replace').decode('latin-1')


def limpar_texto_ofx(texto, max_len=1024):
    if not texto:
        return ''
    return texto_latin1(texto)[:max_len]


def _tag(indent, name, value):
    return f'{" " * indent}<{name}>{value}</{name}>'


def gerar_fitid_por_dia(data_br, sequencia_dia):
    dt = datetime.strptime(data_br.strip(), '%d/%m/%Y')
    return f"{dt.strftime('%Y%m%d')}{sequencia_dia:03d}"


def gerar_arquivo_ofx(
    transacoes,
    output_path,
    bank_id='756',
    branch_id='',
    acct_id='00000000',
    acct_type='CHECKING',
    balance=None,
    dtstart=None,
    dtend=None,
    org='Sicoob',
    fi_id='756',
):
    if not transacoes:
        raise ValueError('Nenhuma transação para gerar OFX')

    transacoes_ordenadas = sorted(
        transacoes,
        key=lambda t: datetime.strptime(t['data'], '%d/%m/%Y'),
    )

    primeira_data = transacoes_ordenadas[0]['data']
    ultima_data = transacoes_ordenadas[-1]['data']

    if dtstart is None:
        inicio = datetime.strptime(primeira_data, '%d/%m/%Y') - timedelta(days=1)
        dtstart = formatar_data_ofx(inicio.strftime('%d/%m/%Y'))
    elif not dtstart.endswith('[-3:-03]'):
        dtstart = formatar_data_ofx(dtstart)

    if dtend is None:
        dtend = formatar_data_ofx(ultima_data)
    elif not dtend.endswith('[-3:-03]'):
        dtend = formatar_data_ofx(dtend)

    if balance is None:
        balance = sum(float(t['valor']) for t in transacoes_ordenadas)

    dtserver = dtend
    contadores_por_dia = {}
    linhas = [
        'OFXHEADER:100',
        'DATA:OFXSGML',
        'VERSION:102',
        'SECURITY:NONE',
        'ENCODING:USASCII',
        'CHARSET:1252',
        'COMPRESSION:NONE',
        'OLDFILEUID:NONE',
        'NEWFILEUID:NONE',
        '',
        '<OFX>',
        '  <SIGNONMSGSRSV1>',
        '    <SONRS>',
        '      <STATUS>',
        '        <CODE>0</CODE>',
        '        <SEVERITY>INFO</SEVERITY>',
        '      </STATUS>',
        f'      <DTSERVER>{dtserver}</DTSERVER>',
        '      <LANGUAGE>POR</LANGUAGE>',
        '      <FI>',
        f'        <ORG>{limpar_texto_ofx(org, 32)}</ORG>',
        f'        <FID>{limpar_texto_ofx(fi_id, 10)}</FID>',
        '      </FI>',
        '    </SONRS>',
        '  </SIGNONMSGSRSV1>',
        '  <BANKMSGSRSV1>',
        '    <STMTTRNRS>',
        '      <TRNUID>1</TRNUID>',
        '      <STATUS>',
        '        <CODE>0</CODE>',
        '        <SEVERITY>INFO</SEVERITY>',
        '      </STATUS>',
        '      <STMTRS>',
        '        <CURDEF>BRL</CURDEF>',
        '        <BANKACCTFROM>',
        f'          <BANKID>{limpar_texto_ofx(bank_id, 10)}</BANKID>',
    ]

    if branch_id:
        linhas.append(f'          <BRANCHID>{limpar_texto_ofx(branch_id, 20)}</BRANCHID>')

    linhas.extend([
        f'          <ACCTID>{limpar_texto_ofx(acct_id, 20)}</ACCTID>',
        f'          <ACCTTYPE>{limpar_texto_ofx(acct_type, 20)}</ACCTTYPE>',
        '        </BANKACCTFROM>',
        '        <BANKTRANLIST>',
        f'          <DTSTART>{dtstart}</DTSTART>',
        f'          <DTEND>{dtend}</DTEND>',
    ])

    for transacao in transacoes_ordenadas:
        valor = float(transacao['valor'])
        trntype = 'CREDIT' if valor >= 0 else 'DEBIT'
        memo = limpar_texto_ofx(transacao.get('memo') or transacao.get('historico', 'Transacao'))
        data = transacao['data']
        data_chave = datetime.strptime(data, '%d/%m/%Y').strftime('%Y%m%d')
        contadores_por_dia[data_chave] = contadores_por_dia.get(data_chave, 0) + 1
        fitid = limpar_texto_ofx(
            transacao.get('fitid') or gerar_fitid_por_dia(data, contadores_por_dia[data_chave]),
            32,
        )
        # Domínio exige CHECKNUM como número do documento; sem ela a importação
        # pode avisar e não gravar lançamentos (Central de Soluções #9596).
        checknum = limpar_texto_ofx(
            transacao.get('checknum')
            or transacao.get('documento')
            or fitid,
            32,
        )
        dtposted = formatar_data_ofx(data)

        linhas.extend([
            '          <STMTTRN>',
            f'            <TRNTYPE>{trntype}</TRNTYPE>',
            f'            <DTPOSTED>{dtposted}</DTPOSTED>',
            f'            <TRNAMT>{valor:.2f}</TRNAMT>',
            f'            <FITID>{fitid}</FITID>',
            f'            <CHECKNUM>{checknum}</CHECKNUM>',
            f'            <MEMO>{memo}</MEMO>',
            '          </STMTTRN>',
        ])

    linhas.extend([
        '        </BANKTRANLIST>',
        '        <LEDGERBAL>',
        f'          <BALAMT>{float(balance):.2f}</BALAMT>',
        f'          <DTASOF>{dtend}</DTASOF>',
        '        </LEDGERBAL>',
        '      </STMTRS>',
        '    </STMTTRNRS>',
        '  </BANKMSGSRSV1>',
        '</OFX>',
    ])

    conteudo = '\n'.join(linhas)
    with open(output_path, 'w', encoding='latin-1', newline='\n') as arquivo:
        arquivo.write(conteudo)

    return {
        'total_transactions': len(transacoes_ordenadas),
        'dtstart': dtstart,
        'dtend': dtend,
        'balance': balance,
        'bank_id': bank_id,
        'branch_id': branch_id,
        'acct_id': acct_id,
    }
