<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Listagem de notas fiscais</title>
    <style>
        @page { margin: 16mm 10mm 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 8px; }
        h1 { margin: 0 0 7px; font-size: 18px; color: #1e3a8a; }
        p { margin: 0 0 5px; }
        .contexto { color: #475569; }
        .resumo { margin: 11px 0 13px; padding: 8px; background: #eff6ff; border: 1px solid #dbeafe; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        th { background: #1e3a8a; color: white; text-align: left; padding: 6px 4px; }
        td { border-bottom: 1px solid #e2e8f0; padding: 5px 4px; overflow-wrap: anywhere; word-wrap: break-word; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .numerico { text-align: right; white-space: nowrap; }
        .chave { font-size: 6.5px; word-break: break-all; }
        .rodape { margin-top: 13px; color: #64748b; font-size: 7px; }
    </style>
</head>
<body>
    <h1>Listagem de notas fiscais</h1>
    <p class="contexto"><strong>Empresa:</strong> {{ $empresa->nome }} &nbsp; <strong>Portal:</strong> {{ $portal->nome }} &nbsp; <strong>Competência:</strong> {{ \App\Services\AutomacaoFiscal\AnaliseFiscalService::formatarCompetencia($competencia) }}</p>
    @if ($ehNfse)
        <p class="contexto"><strong>Tipo:</strong> {{ \App\Services\AutomacaoFiscal\AnaliseFiscalService::formatarTipoListagem($tipoListagem) }}</p>
    @endif
    <p class="contexto"><strong>Data de {{ $ehNfse ? 'geração' : 'emissão' }}:</strong> {{ $dataInicio ? \Carbon\Carbon::parse($dataInicio)->format('d/m/Y') : 'Sem data inicial' }} a {{ $dataFim ? \Carbon\Carbon::parse($dataFim)->format('d/m/Y') : 'Sem data final' }}</p>
    <div class="resumo"><strong>{{ $documentos->count() }} documentos</strong> &nbsp; • &nbsp; <strong>Total: R$ {{ number_format((float) $total, 2, ',', '.') }}</strong></div>
    <table>
        <thead>
            <tr>
                @foreach ($cabecalhos as $cabecalho)
                    <th class="{{ str_contains($cabecalho, '(R$)') ? 'numerico' : '' }}">{{ $cabecalho }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($documentos as $doc)
                <tr>
                    @foreach (app(\App\Services\AutomacaoFiscal\ExportadorListagemFiscalService::class)->linha($doc, $ehNfse) as $index => $valor)
                        <td class="{{ is_float($valor) ? 'numerico' : '' }} {{ $index === count($cabecalhos) - 1 ? 'chave' : '' }}">
                            {{ is_float($valor) ? number_format($valor, 2, ',', '.') : $valor }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="rodape">IntegraExpert • Exportação da listagem armazenada em {{ now()->format('d/m/Y H:i') }}. Não é uma consulta em tempo real ao portal.</div>
</body>
</html>
