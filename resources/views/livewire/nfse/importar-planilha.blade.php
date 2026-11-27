<div class="mt-6">
    <h2 class="text-xl font-bold text-gray-800">Importar planilha de serviços</h2>
    <p class="text-sm text-gray-600 mt-1">Use o layout do cliente ou configure um novo. Uma linha gera um rascunho; nenhuma nota é transmitida.</p>
    @if (session('nfse_importacao_mensagem'))
        <p role="status" class="mt-4 bg-gray-50 p-3 rounded-md">{{ session('nfse_importacao_mensagem') }}</p>
    @endif
    @if ($errors->any())
        <div role="alert" class="mt-4 border border-red-300 rounded-md bg-red-50 p-3 text-red-700">
            @foreach ($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach
        </div>
    @endif
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <label class="block text-sm">Layout
            <select wire:model.live="layoutId" class="w-full rounded-md border-gray-300"><option value="">Novo layout</option>@foreach($layouts as $layout)<option value="{{ $layout->id }}">{{ $layout->nome }} — v{{ $layout->versao }}</option>@endforeach</select>
        </label>
        <label class="block text-sm">Competência fiscal<input type="date" wire:model.live="competencia" class="w-full rounded-md border-gray-300"></label>
        <label class="block text-sm">Planilha (CSV, XLS, XLSX — até 2 MB / 200 linhas)<input type="file" accept=".csv,.xls,.xlsx" wire:model="arquivo" class="w-full"></label>
    </div>
    @unless ($layoutId)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <label class="block text-sm">Nome do layout<input type="text" wire:model="nomeLayout" maxlength="120" class="w-full rounded-md border-gray-300"></label>
            <label class="block text-sm">Linha do cabeçalho<input type="number" min="1" max="20" wire:model.live="config.cabecalho" class="w-full rounded-md border-gray-300"></label>
            <label class="block text-sm">Aba Excel (1 = primeira)<select wire:model.live="config.aba" class="w-full rounded-md border-gray-300">@for ($i=0; $i<20; $i++)<option value="{{ $i }}">{{ $i+1 }}</option>@endfor</select></label>
            <label class="block text-sm">Separador CSV<select wire:model.live="config.delimitador" class="w-full rounded-md border-gray-300"><option value="ponto_virgula">Ponto e vírgula</option><option value="virgula">Vírgula</option><option value="tab">Tabulação</option></select></label>
            <div><button type="button" wire:click="lerCabecalho" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Ler colunas</button></div>
        </div>
        @if ($colunas)
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <label class="block text-sm">Identificar tomador por<select wire:model.live="config.identificacao" class="w-full rounded-md border-gray-300"><option value="documento">CPF/CNPJ</option><option value="codigo_externo">Código externo cadastrado</option></select></label>
                @foreach (['coluna_tomador'=>'Identificador do tomador','coluna_valor'=>'Valor','coluna_servico'=>'Código interno do serviço (opcional)','coluna_descricao'=>'Descrição (opcional)'] as $campo=>$rotulo)
                    <label class="block text-sm" wire:key="mapa-{{ $campo }}">{{ $rotulo }}<select wire:model.live="config.{{ $campo }}" class="w-full rounded-md border-gray-300"><option value="">Não mapeado</option>@foreach($colunas as $indice=>$coluna)<option value="{{ $indice }}">{{ $coluna }}</option>@endforeach</select></label>
                @endforeach
                <label class="block text-sm">Serviço fixo (se não mapear coluna)<select wire:model.live="config.servico_fixo" class="w-full rounded-md border-gray-300"><option value="">Não definido</option>@foreach($servicos as $servico)<option value="{{ $servico->codigo }}">{{ $servico->nome }} — {{ $servico->codigo }}</option>@endforeach</select></label>
                <label class="block text-sm">Formato do valor<select wire:model.live="config.formato_valor" class="w-full rounded-md border-gray-300"><option value="br">Brasileiro: 1.234,56</option><option value="decimal">Decimal / células numéricas Excel: 1234.56</option></select></label>
            </div>
        @endif
    @endunless
    <div class="mt-4">
        <button type="button" wire:click="conferir" wire:loading.attr="disabled" @disabled(!$layoutId && !$colunas) class="bg-blue-600 text-white px-4 py-2 rounded-lg disabled:opacity-50">Pré-conferir planilha</button>
        <span wire:loading class="text-sm text-gray-600">Processando...</span>
    </div>
    @if ($previa)
        <div class="mt-6 border border-gray-200 rounded-lg p-4">
            <h3 class="font-bold text-gray-800">Pré-conferência da importação</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                <div class="bg-gray-50 p-3 rounded-md">Aptas para rascunho: <strong>{{ $previa['aptas'] }}</strong></div>
                <div class="bg-gray-50 p-3 rounded-md">Pendentes: <strong>{{ $previa['pendentes'] }}</strong></div>
                <div class="bg-gray-50 p-3 rounded-md">Total apto: <strong>R$ {{ str_replace('.', ',', $previa['total']) }}</strong></div>
            </div>
            <div class="overflow-x-auto mt-3">
                <table class="min-w-full divide-y divide-gray-200 text-sm"><thead><tr><th class="text-left p-3">Linha</th><th class="text-left p-3">Tomador</th><th class="text-left p-3">Serviço / descrição</th><th class="text-right p-3">Valor</th><th class="text-left p-3">Situação</th></tr></thead><tbody>
                @foreach ($previa['linhas'] as $linha)
                    <tr wire:key="previa-{{ $linha['linha'] }}"><td class="p-3">{{ $linha['linha'] }}</td><td class="p-3">{{ $linha['tomador'] }}</td><td class="p-3">{{ $linha['servico'] }}<p class="text-gray-600">{{ $linha['descricao'] }}</p></td><td class="p-3 text-right">{{ $linha['valor'] }}</td><td class="p-3 {{ $linha['erro'] ? 'text-red-700' : 'text-gray-800' }}">{{ $linha['erro'] ?? 'Apta para rascunho' }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
            <p class="text-sm text-gray-600 mt-3">As linhas pendentes ficam no histórico. Corrija o cadastro/arquivo e reimporte; as notas já preparadas serão sinalizadas como possíveis duplicidades.</p>
            <button type="button" wire:click="importar" wire:confirm="Gerar somente os rascunhos aptos desta conferência? Nenhuma nota será transmitida." wire:loading.attr="disabled" @disabled(!$previa['aptas']) class="mt-3 bg-blue-600 text-white px-4 py-2 rounded-lg disabled:opacity-50">Salvar layout e gerar {{ $previa['aptas'] }} rascunhos</button>
        </div>
    @endif
    <h3 class="font-bold text-gray-800 mt-6">Últimas importações</h3>
    <div class="overflow-x-auto mt-3"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead><tr><th class="text-left p-3">Arquivo</th><th class="text-left p-3">Competência</th><th class="text-right p-3">Geradas</th><th class="text-right p-3">Pendentes</th><th class="text-left p-3">Ação</th></tr></thead><tbody>
        @forelse($historico as $item)<tr wire:key="historico-{{ $item->id }}"><td class="p-3">{{ $item->arquivo_original }}</td><td class="p-3">{{ $item->competencia->format('m/Y') }}</td><td class="p-3 text-right">{{ $item->quantidade_gerada }}</td><td class="p-3 text-right">{{ $item->quantidade_pendente }}</td><td class="p-3"><button type="button" wire:click="abrirHistorico({{ $item->id }})" class="text-blue-600">Conferência</button></td></tr>
        @empty<tr><td colspan="5" class="p-3 text-gray-600">Nenhuma importação realizada.</td></tr>@endforelse
    </tbody></table></div>
    @if($detalhe)
        <div class="mt-4 border border-gray-200 rounded-md p-3"><div class="flex justify-between"><strong>{{ $detalhe->arquivo_original }}</strong><button type="button" wire:click="fecharHistorico" class="text-blue-600">Fechar</button></div>
        @foreach($detalhe->relatorio as $linha)<p class="text-sm mt-2">Linha {{ $linha['linha'] }} — {{ $linha['tomador'] }} — {{ $linha['valor'] }} — {{ $linha['erro'] ?? 'Rascunho gerado' }}</p>@endforeach
        </div>
    @endif
</div>
