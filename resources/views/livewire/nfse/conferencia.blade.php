<div class="mt-6">
    <h2 class="text-xl font-bold text-gray-800">Conferência e lotes</h2>
    <p class="text-sm text-gray-600 mt-1">Revise rascunhos de todas as origens. Preparar lote não aprova nem transmite documentos fiscais.</p>
    @if(session('nfse_conferencia_mensagem'))<p role="status" class="mt-3 text-gray-800">{{ session('nfse_conferencia_mensagem') }}</p>@endif
    @if($errors->any())<div role="alert" class="mt-3 border border-red-300 rounded-md bg-red-50 p-3 text-red-700">@foreach($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach</div>@endif
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <label class="block text-sm">Competência<input type="month" wire:model.live="mes" class="w-full rounded-md border-gray-300"></label>
        <label class="block text-sm">Situação<select wire:model.live="status" class="w-full rounded-md border-gray-300"><option value="rascunho">Rascunhos</option><option value="em_lote">Em lote preparado</option><option value="descartada">Descartadas</option><option value="">Todas</option></select></label>
        <label class="block text-sm">Buscar<input type="search" wire:model.live.debounce.300ms="busca" placeholder="Tomador, documento ou serviço" class="w-full rounded-md border-gray-300"></label>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <div class="bg-gray-50 rounded-md p-3"><p class="text-sm text-gray-600">Notas no filtro</p><p class="text-xl font-bold">{{ $notas->total() }}</p></div>
        <div class="bg-gray-50 rounded-md p-3"><p class="text-sm text-gray-600">Selecionadas</p><p class="text-xl font-bold">{{ count($selecionadas) }}</p></div>
        <div class="bg-gray-50 rounded-md p-3"><p class="text-sm text-gray-600">Valor selecionado</p><p class="text-xl font-bold">{{ $selecaoValida ? 'R$ '.\App\Services\Nfse\ConferenciaNfse::formatarValor($total) : 'Seleção desatualizada' }}</p></div>
    </div>
    <div class="flex flex-wrap gap-2 mt-4">
        <button type="button" wire:click="selecionarTodas" wire:loading.attr="disabled" class="bg-gray-50 px-4 py-2 rounded-lg">Selecionar todos os rascunhos do filtro (até 200)</button>
        <button type="button" wire:click="limparSelecao" class="bg-gray-50 px-4 py-2 rounded-lg">Limpar seleção</button>
    </div>
    @unless($selecaoValida)<p class="text-red-700 mt-3">Há notas alteradas por outro operador. Limpe a seleção e selecione novamente.</p>@endunless
    <div class="overflow-x-auto mt-3">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead><tr><th class="p-3">Sel.</th><th class="text-left p-3">Tomador</th><th class="text-left p-3">Serviço / descrição</th><th class="text-left p-3">Competência / origem</th><th class="text-right p-3">Valor</th><th class="text-right p-3">Mês anterior*</th><th class="text-left p-3">Situação / alertas</th><th class="text-left p-3">Ação</th></tr></thead>
            <tbody>
            @forelse($notas as $nota)
                <tr wire:key="conf-nota-{{ $nota->id }}" class="{{ isset($selecionadas[$nota->id]) ? 'bg-gray-50' : '' }}">
                    <td class="p-3 text-center">@if($nota->status === 'rascunho')<input type="checkbox" wire:click="alternar({{ $nota->id }})" @checked(isset($selecionadas[$nota->id])) aria-label="Selecionar nota {{ $nota->id }}" wire:loading.attr="disabled">@endif</td>
                    <td class="p-3">{{ data_get($nota->snapshot, 'tomador.nome') }}<p class="text-gray-600">{{ data_get($nota->snapshot, 'tomador.documento') }}</p></td>
                    <td class="p-3">{{ data_get($nota->snapshot, 'servico.nome') }}<p class="text-gray-600">{{ $nota->descricao }}</p></td>
                    <td class="p-3">{{ $nota->competencia->format('d/m/Y') }}<p class="text-gray-600">Nota interna #{{ $nota->id }}</p><p class="text-gray-600">{{ $nota->recorrencia_id ? 'Recorrência #'.$nota->recorrencia_id : ($nota->importacao_id ? 'Planilha #'.$nota->importacao_id.' · linha '.$nota->linha_importacao : 'Avulsa') }}</p></td>
                    <td class="p-3 text-right">R$ {{ \App\Services\Nfse\ConferenciaNfse::formatarValor($nota->valor) }}</td>
                    <td class="p-3 text-right">@if($detalhes[$nota->id]['anterior'] !== null)R$ {{ \App\Services\Nfse\ConferenciaNfse::formatarValor($detalhes[$nota->id]['anterior']) }} @if($detalhes[$nota->id]['anterior'] !== $nota->valor)<p class="text-gray-600">Valor diferente</p>@endif @else — @endif</td>
                    <td class="p-3">{{ match($nota->status) {'rascunho'=>'Rascunho','em_lote'=>'Lote #'.$nota->lote_id.' preparado','descartada'=>'Descartada',default=>$nota->status} }}@foreach($detalhes[$nota->id]['alertas'] as $alerta)<p class="text-red-700">{{ $alerta }}</p>@endforeach</td>
                    <td class="p-3">@if($nota->status === 'rascunho')<button type="button" wire:click="abrirEdicao({{ $nota->id }})" class="text-blue-600">Editar</button>@endif</td>
                </tr>
                @if(isset($versaoEdicao[$nota->id]))
                    <tr wire:key="conf-editar-{{ $nota->id }}"><td colspan="8" class="p-3 bg-gray-50">
                        <form wire:submit="salvarEdicao" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="block text-sm">Tomador<select wire:model="edicao.tomador_id" @disabled($nota->recorrencia_id) required class="w-full rounded-md border-gray-300"><option value="">Selecione</option>@foreach($tomadores as $tomador)<option value="{{ $tomador->id }}">{{ $tomador->nome }} — {{ $tomador->documento }}</option>@endforeach</select></label>
                            <label class="block text-sm">Competência<input type="date" wire:model="edicao.competencia" required class="w-full rounded-md border-gray-300"></label>
                            <label class="block text-sm">Serviço<select wire:model="edicao.servico_id" @disabled($nota->recorrencia_id) required class="w-full rounded-md border-gray-300"><option value="">Selecione</option>@foreach($servicos as $servico)<option value="{{ $servico->id }}">{{ $servico->nome }}</option>@endforeach</select></label>
                            <label class="block text-sm">Valor<input type="number" min="0.01" step="0.01" wire:model="edicao.valor" required class="w-full rounded-md border-gray-300"></label>
                            <label class="block text-sm md:col-span-2">Descrição<textarea wire:model="edicao.descricao" required rows="3" class="w-full rounded-md border-gray-300"></textarea></label>
                            <div class="flex gap-2 md:col-span-2"><button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Salvar revisão</button><button type="button" wire:click="cancelarEdicao" class="bg-white px-4 py-2 rounded-lg">Cancelar</button></div>
                        </form>
                    </td></tr>
                @endif
            @empty <tr><td colspan="8" class="p-3 text-gray-600">Nenhuma nota encontrada.</td></tr> @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-sm text-gray-600 mt-2">* Comparação com o único registro não descartado do mesmo tomador e serviço no mês anterior, quando existir. Não representa necessariamente nota autorizada.</p>
    {{ $notas->links() }}
    @if($selecionadas !== [] && $selecaoValida)
        <div class="mt-6 border border-gray-200 rounded-md p-4">
            <h3 class="font-bold text-gray-800">Alterar selecionadas</h3>
            <p class="text-sm text-gray-600">Somente os campos preenchidos serão alterados; a descrição não muda automaticamente ao trocar o serviço.</p>
            <form wire:submit="alterarMassa" class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                <label class="block text-sm">Serviço<select wire:model="massa.servico_id" class="w-full rounded-md border-gray-300"><option value="">Manter atual</option>@foreach($servicos as $servico)<option value="{{ $servico->id }}">{{ $servico->nome }}</option>@endforeach</select></label>
                <label class="block text-sm">Valor<input type="number" min="0.01" step="0.01" wire:model="massa.valor" placeholder="Manter atual" class="w-full rounded-md border-gray-300"></label>
                <label class="block text-sm md:col-span-2">Descrição<textarea wire:model="massa.descricao" rows="2" placeholder="Manter atual" class="w-full rounded-md border-gray-300"></textarea></label>
                <div><button type="submit" wire:confirm="Aplicar os campos preenchidos a todas as notas selecionadas?" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Aplicar alterações</button></div>
            </form>
            @if($alertasSelecionados !== [])<p class="text-red-700 mt-4">{{ count($alertasSelecionados) }} selecionadas com possível duplicidade. Notas internas: {{ implode(', #', array_slice($alertasSelecionados, 0, 20)) }}{{ count($alertasSelecionados) > 20 ? '…' : '' }}. Revise antes de preparar.</p>@endif
            <label class="block text-sm mt-4">Justificativa para possíveis duplicidades (quando houver)<textarea wire:model="justificativa" maxlength="1000" rows="2" placeholder="Explique por que as notas semelhantes são legítimas." class="w-full rounded-md border-gray-300"></textarea></label>
            <div class="flex flex-wrap gap-2 mt-3">
                <button type="button" wire:click="prepararLote" wire:confirm="Preparar {{ count($selecionadas) }} notas no valor total de R$ {{ \App\Services\Nfse\ConferenciaNfse::formatarValor($total) }}? Não haverá transmissão fiscal." wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Preparar lote</button>
                <button type="button" wire:click="descartar" wire:confirm="Descartar os rascunhos selecionados? O histórico será preservado." wire:loading.attr="disabled" class="text-red-700 px-4 py-2 rounded-lg">Descartar selecionadas</button>
            </div>
        </div>
    @endif
    <h3 class="text-xl font-bold text-gray-800 mt-6">Lotes da competência</h3>
    <div class="overflow-x-auto mt-3"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead><tr><th class="text-left p-3">Lote</th><th class="text-left p-3">Preparado em</th><th class="text-right p-3">Notas</th><th class="text-right p-3">Valor</th><th class="text-left p-3">Situação</th><th class="text-left p-3">Ação</th></tr></thead><tbody>
        @forelse($lotes as $lote)
            <tr wire:key="conf-lote-{{ $lote->id }}"><td class="p-3">#{{ $lote->id }}</td><td class="p-3">{{ $lote->created_at->format('d/m/Y H:i') }}</td><td class="p-3 text-right">{{ $lote->quantidade }}</td><td class="p-3 text-right">R$ {{ \App\Services\Nfse\ConferenciaNfse::formatarValor($lote->valor_total) }}</td><td class="p-3">{{ $lote->status === 'preparado' ? 'Preparado — não transmitido' : 'Desfeito' }}</td><td class="p-3">@if($lote->status === 'preparado')<button type="button" wire:click="desfazerLote({{ $lote->id }})" wire:confirm="Desfazer lote e liberar os rascunhos?" wire:loading.attr="disabled" class="text-blue-600">Desfazer lote</button>@endif</td></tr>
        @empty <tr><td colspan="6" class="p-3 text-gray-600">Nenhum lote nesta competência.</td></tr> @endforelse
    </tbody></table></div>
    {{ $lotes->links() }}
</div>
