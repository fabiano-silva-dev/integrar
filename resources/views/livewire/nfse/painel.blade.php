<div class="max-w-7xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <h1 class="text-2xl font-bold text-gray-800">Visão geral de NFS-e</h1>
        <p class="text-gray-600 mt-1">Acompanhe todas as empresas ativas do escritório por competência, independentemente da empresa do cabeçalho.</p>
        <p class="mt-3 border border-gray-200 bg-gray-50 rounded-md p-3 text-sm">A transmissão fiscal ainda não está habilitada. Valores previstos e preparados são operacionais, não representam NFS-e autorizadas ou faturamento do escritório.</p>
        @if($errors->any())<div role="alert" class="mt-3 border border-red-300 rounded-md bg-red-50 p-3 text-red-700">@foreach($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach</div>@endif
        @if($semContexto)
            <p class="mt-4 text-gray-600">Selecione um escritório no cabeçalho para consultar os indicadores.</p>
        @else
            <div class="mt-4 flex flex-wrap items-end gap-4">
                <label class="block text-sm">Competência<input type="month" wire:model.live="mes" required class="w-full rounded-md border-gray-300"></label>
                <label class="block text-sm">Buscar empresa<input type="search" wire:model.live.debounce.300ms="busca" placeholder="Nome ou CNPJ" class="w-full rounded-md border-gray-300"></label>
                <label class="block text-sm">Filtro<select wire:model.live="filtro" class="w-full rounded-md border-gray-300">
                    <option value="todas">Todas as empresas ativas</option><option value="habilitadas">Preparação habilitada</option><option value="rascunhos">Com rascunhos</option><option value="lotes">Com lotes preparados</option><option value="previstas">Com recorrências previstas</option><option value="recorrencias">Recorrências a gerar ou descartadas</option><option value="importacoes">Histórico de linhas pendentes</option><option value="certificado">Habilitadas sem A1 vigente</option>
                </select></label>
                <span wire:loading class="text-sm text-gray-600">Atualizando...</span>
            </div>
            @if(!$mesValido)
                <p role="alert" class="mt-4 text-red-700">Informe uma competência válida.</p>
            @elseif($resumo)
                <h2 class="font-bold text-gray-800 mt-6">Consolidado do escritório</h2>
                <p class="text-sm text-gray-600">Os totais abaixo abrangem todo o escritório. Clique em um indicador para filtrar a listagem.</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                    @foreach([
                        ['Empresas com preparo habilitado', $resumo->habilitadas.' / '.$resumo->empresas, 'habilitadas'],
                        ['Rascunhos a conferir', $resumo->rascunhos, 'rascunhos'],
                        ['Lotes preparados', $resumo->lotes.' ('.$resumo->em_lote.' notas)', 'lotes'],
                        ['Recorrências previstas', $resumo->previstas, 'previstas'],
                        ['Recorrências sem rascunho', $resumo->recorrencias_pendentes, 'recorrencias'],
                        ['Linhas pendentes na importação (histórico)', $resumo->linhas_pendentes, 'importacoes'],
                    ] as [$rotulo, $valor, $destino])
                        <button type="button" wire:click="filtrar('{{ $destino }}')" class="text-left rounded-md p-3 border border-gray-200 {{ $filtro === $destino ? 'bg-blue-600 text-white' : 'bg-gray-50 text-gray-800' }}">
                            <span class="block text-sm">{{ $rotulo }}</span><strong class="text-xl">{{ $valor }}</strong>
                        </button>
                    @endforeach
                    <div class="bg-gray-50 p-3 rounded-md"><p class="text-sm">Valor contratual previsto</p><strong class="text-xl">R$ {{ \App\Services\Nfse\ConferenciaNfse::formatarValor((string) $resumo->valor_previsto) }}</strong></div>
                    <div class="bg-gray-50 p-3 rounded-md"><p class="text-sm">Valor preparado (rascunhos + em lote)</p><strong class="text-xl">R$ {{ \App\Services\Nfse\ConferenciaNfse::formatarValor((string) $resumo->valor_preparado) }}</strong></div>
                    <div class="bg-gray-50 p-3 rounded-md"><p class="text-sm">Descartadas / recorrências pausadas</p><strong class="text-xl">{{ $resumo->descartadas }} / {{ $resumo->pausadas }}</strong></div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2 mt-6">
                    <h2 class="font-bold text-gray-800">Empresas ({{ $empresas->total() }})</h2>
                    <button type="button" wire:click="filtrar('todas')" class="text-blue-600">Mostrar todas</button>
                </div>
                <div class="overflow-x-auto mt-3"><table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr><th class="text-left p-3">Prestador / configuração</th><th class="text-left p-3">Certificado A1 próprio</th><th class="text-left p-3">Recorrências</th><th class="text-left p-3">Planilhas</th><th class="text-left p-3">Conferência / lotes</th><th class="text-right p-3">Valor previsto / preparado</th><th class="text-left p-3">Ações</th></tr></thead>
                    <tbody>@forelse($empresas as $e)
                        <tr wire:key="nfse-painel-{{ $e->id }}">
                            <td class="p-3"><strong>{{ $e->empresa }}</strong><p class="text-gray-600">{{ $e->cnpj }}</p><p>{{ $e->habilitada ? 'Preparação habilitada' : 'Preparação desabilitada' }}</p></td>
                            <td class="p-3">@if($e->certificado_ate)<p>Vigente por datas</p><p class="text-gray-600">Até {{ \Carbon\CarbonImmutable::parse($e->certificado_ate)->format('d/m/Y') }}</p>@else<span class="text-red-700">Sem A1 vigente cadastrado</span>@endif</td>
                            <td class="p-3"><p>{{ $e->previstas }} previstas · {{ $e->pausadas }} pausadas</p><button type="button" wire:click="abrirEmpresa({{ $e->id }}, 'recorrencias')" class="text-blue-600">{{ $e->recorrencias_pendentes }} sem rascunho</button>@if($e->recorrencias_descartadas)<p class="text-red-700">{{ $e->recorrencias_descartadas }} descartadas</p>@endif</td>
                            <td class="p-3">{{ $e->importacoes }} importações<p><button type="button" wire:click="abrirEmpresa({{ $e->id }}, 'importar')" class="text-blue-600">{{ $e->linhas_pendentes }} pendentes (hist.)</button></p></td>
                            <td class="p-3"><button type="button" wire:click="abrirEmpresa({{ $e->id }}, 'conferencia')" class="text-blue-600">{{ $e->rascunhos }} rascunhos</button><p><button type="button" wire:click="abrirEmpresa({{ $e->id }}, 'conferencia', 'em_lote')" class="text-blue-600">{{ $e->lotes }} lotes · {{ $e->em_lote }} notas</button></p><p class="text-gray-600">{{ $e->descartadas }} descartadas</p></td>
                            <td class="p-3 text-right"><p>R$ {{ \App\Services\Nfse\ConferenciaNfse::formatarValor((string) $e->valor_previsto) }}</p><p class="font-bold">R$ {{ \App\Services\Nfse\ConferenciaNfse::formatarValor((string) $e->valor_preparado) }}</p></td>
                            <td class="p-3"><button type="button" wire:click="abrirEmpresa({{ $e->id }}, 'notas')" class="text-blue-600">Nova nota</button>@if(app(\App\Services\Nfse\PreparacaoNfse::class)->podeConfigurar())<p><button type="button" wire:click="abrirEmpresa({{ $e->id }}, 'configuracao')" class="text-blue-600">Configurar</button></p>@endif</td>
                        </tr>
                    @empty<tr><td colspan="7" class="p-3 text-gray-600">Nenhuma empresa corresponde ao filtro.</td></tr>@endforelse</tbody>
                </table></div>
                {{ $empresas->links() }}
                <p class="text-sm text-gray-600 mt-4">Previstas: contratos vigentes não pausados, pelo preço da competência, mesmo quando há impedimento. Sem rascunho: ainda não existe ocorrência vinculada; pode haver conflito de outra origem. Linhas pendentes: soma histórica registrada nas importações do mês, não saldo de pendências resolvidas. A validade do certificado é avaliada na data atual e não comprova autorização fiscal.</p>
            @endif
        @endif
    </div>
</div>
