<div class="mt-6">
    <h2 class="text-xl font-bold text-gray-800">Serviços recorrentes</h2>
    <p class="text-sm text-gray-600">Contratos mensais. Gere rascunhos para conferência, sem transmissão automática.</p>
    @if(session('nfse_rec_mensagem'))<p role="status" class="mt-3 text-gray-800">{{ session('nfse_rec_mensagem') }}</p>@endif
    @if($errors->any())<div role="alert" class="mt-3 border border-red-300 rounded-md bg-red-50 p-3 text-red-700">@foreach($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach</div>@endif
    <h3 class="font-bold mt-4">Novo contrato</h3>
    <form wire:submit="cadastrar" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-4">
        <label class="block text-sm">Referência<input wire:model="form.referencia" required maxlength="100" class="w-full rounded-md border-gray-300"></label>
        <label class="block text-sm">Tomador<select wire:model="form.tomador_id" required class="w-full rounded-md border-gray-300"><option value="">Selecione</option>@foreach($tomadores->where('ativo', true) as $t)<option value="{{ $t->id }}">{{ $t->nome }} — {{ $t->documento }}</option>@endforeach</select></label>
        <label class="block text-sm">Serviço<select wire:model.live="form.servico_id" required class="w-full rounded-md border-gray-300"><option value="">Selecione</option>@foreach($servicos->where('ativo', true) as $s)<option value="{{ $s->id }}">{{ $s->nome }}</option>@endforeach</select></label>
        <label class="block text-sm">Início (mês)<input type="month" wire:model="form.inicio" required class="w-full rounded-md border-gray-300"></label>
        <label class="block text-sm">Fim (opcional, inclusive)<input type="month" wire:model="form.fim" class="w-full rounded-md border-gray-300"></label>
        <label class="block text-sm">Dia previsto (1–31)<input type="number" wire:model="form.dia" min="1" max="31" required class="w-full rounded-md border-gray-300"></label>
        <label class="block text-sm">Valor mensal (R$)<input type="number" wire:model="form.valor" min="0.01" step="0.01" required class="w-full rounded-md border-gray-300"></label>
        <label class="block text-sm md:col-span-2">Descrição (use {mes} e {ano})<textarea wire:model="form.descricao" rows="2" maxlength="2000" required class="w-full rounded-md border-gray-300"></textarea></label>
        <div><button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Cadastrar contrato</button></div>
    </form>
    <h3 class="font-bold mt-6">Competência de geração</h3>
    <div class="flex flex-wrap gap-2 mt-3 items-center">
        <input type="month" wire:model.live="mes" aria-label="Competência" class="rounded-md border-gray-300">
        <button type="button" wire:click="conferir" wire:loading.attr="disabled" class="bg-gray-50 px-4 py-2 rounded-lg">Conferir recorrências</button>
    </div>
    @if(isset($previa['conferencia']))
        <p class="mt-3 font-bold">{{ $previa['aptas'] }} aptas · R$ {{ \App\Services\Nfse\ConferenciaNfse::formatarValor($previa['total']) }}</p>
        <div class="overflow-x-auto mt-3"><table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead><tr><th class="text-left p-3">Contrato / tomador</th><th class="text-left p-3">Serviço / descrição</th><th class="text-left p-3">Data prevista</th><th class="text-right p-3">Valor</th><th class="text-left p-3">Situação</th><th class="text-left p-3">Ação</th></tr></thead>
            <tbody>@forelse($previa['linhas'] as $l)
                <tr wire:key="rec-prev-{{ $l['id'] }}"><td class="p-3">{{ $l['referencia'] }}<p class="text-gray-600">{{ $l['tomador'] }}</p></td><td class="p-3">{{ $l['servico'] }}<p class="text-gray-600">{{ $l['descricao'] }}</p></td><td class="p-3">{{ $l['data'] }}</td><td class="p-3 text-right">{{ $l['valor'] !== null ? 'R$ '.\App\Services\Nfse\ConferenciaNfse::formatarValor($l['valor']) : '—' }}</td><td class="p-3">{{ match($l['status']) {'apta'=>'Apta para rascunho','pausada'=>'Pausada','gerada'=>'Já gerada #'.$l['nota_id'].' ('.$l['nota_status'].')','outra_origem'=>'Existe nota avulsa/importada do mesmo tomador e serviço; revisar','impedida'=>'Cadastro, descrição ou preço inválido',default=>'Pendente'} }}</td>
                <td class="p-3">@if($l['status']==='pausada')<button type="button" wire:click="pausar({{ $l['id'] }}, false)" class="text-blue-600">Retomar mês</button>@elseif($l['status']!=='gerada')<button type="button" wire:click="pausar({{ $l['id'] }}, true)" class="text-blue-600">Pausar mês</button>@endif</td></tr>
            @empty<tr><td colspan="6" class="p-3 text-gray-600">Nenhum contrato vigente.</td></tr>@endforelse</tbody>
        </table></div>
        <button type="button" wire:click="gerar" wire:confirm="Gerar os {{ $previa['aptas'] }} rascunhos aptos? Nenhuma nota será transmitida." wire:loading.attr="disabled" @disabled(!$previa['aptas']) class="bg-blue-600 text-white px-4 py-2 rounded-lg mt-3 disabled:opacity-50">Gerar rascunhos aptos</button>
    @endif
    <h3 class="font-bold mt-6">Contratos cadastrados</h3>
    <p class="text-sm text-gray-600">Reajustes entram em vigor a partir do mês informado. Para encerrar, selecione acima o último mês do contrato.</p>
    <div class="overflow-x-auto mt-3"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead><tr><th class="text-left p-3">Referência</th><th class="text-left p-3">Tomador</th><th class="text-left p-3">Serviço</th><th class="text-left p-3">Vigência</th><th class="text-left p-3">Ação</th></tr></thead><tbody>
    @forelse($contratos as $r)
        <tr wire:key="rec-{{ $r->id }}"><td class="p-3">{{ $r->referencia }}</td><td class="p-3">{{ $tomadores->firstWhere('id', $r->tomador_id)?->nome }}</td><td class="p-3">{{ $servicos->firstWhere('id', $r->servico_id)?->nome }}</td><td class="p-3">{{ $r->inicio->format('m/Y') }} até {{ $r->fim?->format('m/Y') ?? 'indeterminado' }} · dia {{ $r->dia }}</td><td class="p-3"><button type="button" wire:click="abrirReajuste({{ $r->id }})" class="text-blue-600">Reajustar</button> <button type="button" wire:click="encerrar({{ $r->id }})" wire:confirm="Encerrar este contrato ao final de {{ $mes }}? Nenhuma nota será apagada." class="text-red-700">Encerrar</button></td></tr>
    @empty<tr><td colspan="5" class="p-3 text-gray-600">Nenhum contrato cadastrado.</td></tr>@endforelse
    </tbody></table></div>
    {{ $contratos->links() }}
    @if($reajustando)
        <form wire:submit="salvarReajuste" class="mt-4 border border-gray-200 rounded-md p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <h3 class="font-bold md:col-span-2">Nova condição do contrato #{{ $reajustando }}</h3>
            <label class="block text-sm">Vigência<input type="month" wire:model="reajuste.mes" required class="w-full rounded-md border-gray-300"></label>
            <label class="block text-sm">Novo valor<input type="number" wire:model="reajuste.valor" min="0.01" step="0.01" required class="w-full rounded-md border-gray-300"></label>
            <label class="block text-sm md:col-span-2">Descrição<textarea wire:model="reajuste.descricao" required maxlength="2000" class="w-full rounded-md border-gray-300"></textarea></label>
            <div class="flex gap-2"><button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Salvar nova condição</button><button type="button" wire:click="cancelarReajuste" class="bg-gray-50 px-4 py-2 rounded-lg">Cancelar</button></div>
        </form>
    @endif
</div>
