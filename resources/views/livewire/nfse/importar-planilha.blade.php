<div class="mt-6">
    <h2 class="text-xl font-bold text-gray-800">Importar planilha de serviços</h2>
    <p class="text-sm text-gray-600 mt-1">Envie a planilha e confira as colunas sugeridas. Relacione os campos, revise os dados e gere os rascunhos. Nenhuma nota é transmitida.</p>
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
        <label class="block text-sm">Planilha (CSV, XLS, XLSX — até 2 MB / 200 linhas)<input type="file" accept=".csv,.xls,.xlsx" wire:model="arquivo" class="w-full"><span wire:loading wire:target="arquivo" class="text-gray-600">Lendo arquivo...</span></label>
    </div>
    @unless ($layoutId)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <label class="block text-sm">Nome do layout<input type="text" wire:model="nomeLayout" maxlength="120" class="w-full rounded-md border-gray-300"></label>
            <label class="block text-sm">Linha do cabeçalho<input type="number" min="1" max="20" wire:model.live="config.cabecalho" class="w-full rounded-md border-gray-300"></label>
            <label class="block text-sm">Aba Excel (1 = primeira)<select wire:model.live="config.aba" class="w-full rounded-md border-gray-300">@for ($i=0; $i<20; $i++)<option value="{{ $i }}">{{ $i+1 }}</option>@endfor</select></label>
            <label class="block text-sm">Separador CSV<select wire:model.live="config.delimitador" class="w-full rounded-md border-gray-300"><option value="ponto_virgula">Ponto e vírgula</option><option value="virgula">Vírgula</option><option value="tab">Tabulação</option></select></label>
            <div class="flex flex-wrap gap-2"><button type="button" wire:click="detectarColunas" wire:loading.attr="disabled" @disabled(!$arquivo) class="bg-blue-600 text-white px-4 py-2 rounded-lg">Detectar colunas</button><button type="button" wire:click="lerCabecalho" wire:loading.attr="disabled" @disabled(!$arquivo) class="border rounded-lg px-4 py-2">Ler colunas da seleção</button></div>
        </div>
        @if ($colunas)
            <div class="mt-4 border border-gray-200 rounded-lg p-3">
                <h3 class="font-semibold">Colunas reconhecidas — {{ $leitura }}</h3>
                <p class="text-sm text-gray-600">Confira a amostra e ajuste as sugestões abaixo. A identificação usa os nomes das colunas; não envia dados a serviços de IA.</p>
                @if($ignoradas)<p class="text-sm text-gray-600">Linhas de totalização identificadas e excluídas: {{ implode(', ', $ignoradas) }}.</p>@endif
                <div class="overflow-x-auto mt-3"><table class="min-w-full text-sm"><thead><tr><th class="text-left p-2">Linha</th>@foreach($colunas as $coluna)<th class="text-left p-2">{{ $coluna }}</th>@endforeach</tr></thead><tbody>@foreach($amostra as $numero=>$linha)<tr class="border-t"><td class="p-2">{{ $numero }}</td>@foreach($linha as $celula)<td class="p-2">{{ $celula }}</td>@endforeach</tr>@endforeach</tbody></table></div>
            </div>
            <h3 class="font-semibold mt-5">Relacionar colunas aos campos da nota</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <label class="block text-sm">Identificar tomador por<select wire:model.live="config.identificacao" class="w-full rounded-md border-gray-300"><option value="documento">CPF/CNPJ</option><option value="codigo_externo">Código externo cadastrado</option></select></label>
                @foreach (['coluna_tomador'=>'CPF/CNPJ ou código do tomador','coluna_nome'=>'Nome / razão social do tomador','coluna_valor'=>'Valor do serviço','coluna_servico'=>'Código interno do serviço (opcional)','coluna_descricao'=>'Descrição (opcional)','coluna_endereco'=>'Endereço completo (referência na conferência)'] as $campo=>$rotulo)
                    <label class="block text-sm" wire:key="mapa-{{ $campo }}">{{ $rotulo }}<select wire:model.live="config.{{ $campo }}" class="w-full rounded-md border-gray-300"><option value="">Não mapeado</option>@foreach($colunas as $indice=>$coluna)<option value="{{ $indice }}">{{ $coluna }}</option>@endforeach</select></label>
                @endforeach
                <label class="block text-sm">Serviço fixo (se não mapear coluna)<select wire:model.live="config.servico_fixo" class="w-full rounded-md border-gray-300"><option value="">Não definido</option>@foreach($servicos as $servico)<option value="{{ $servico->codigo }}">{{ $servico->nome }} — {{ $servico->codigo }}</option>@endforeach</select></label>
                <label class="block text-sm">Formato do valor<select wire:model.live="config.formato_valor" class="w-full rounded-md border-gray-300"><option value="br">Brasileiro: 1.234,56</option><option value="decimal">Decimal / células numéricas Excel: 1234.56</option></select></label>
            </div>
            <label class="block mt-4 text-sm"><input type="checkbox" wire:model.live="config.cadastrar_tomadores"> Cadastrar tomadores ainda inexistentes a partir do CPF/CNPJ e nome mapeados (somente na confirmação). Não altera cadastros existentes.</label>
            <details class="mt-3 border rounded-lg p-3">
                <summary class="cursor-pointer font-medium text-sm">Outros campos cadastrais (opcionais)</summary>
                <p class="text-sm text-gray-600 mt-2">O endereço integral fica preservado no relatório de importação. Para gravar um endereço fiscal, mapeie separadamente logradouro, número, bairro, CEP e código IBGE do município. Revise os endereços antes de emitir.</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                    @foreach(['coluna_cep'=>'CEP','coluna_logradouro'=>'Logradouro','coluna_numero'=>'Número','coluna_bairro'=>'Bairro','coluna_complemento'=>'Complemento','coluna_municipio'=>'Código IBGE do município','coluna_uf'=>'UF','coluna_email'=>'E-mail','coluna_telefone'=>'Telefone'] as $campo=>$rotulo)
                        <label class="block text-sm" wire:key="mapa-{{ $campo }}">{{ $rotulo }}<select wire:model.live="config.{{ $campo }}" class="w-full rounded-md border-gray-300"><option value="">Não mapeado</option>@foreach($colunas as $indice=>$coluna)<option value="{{ $indice }}">{{ $coluna }}</option>@endforeach</select></label>
                    @endforeach
                </div>
            </details>
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
            @if($previa['novos_tomadores'])<p class="mt-3 text-sm">Serão cadastrados {{ $previa['novos_tomadores'] }} novos tomadores ao confirmar.</p>@endif
            @if($previa['ignoradas'])<p class="mt-2 text-sm">Linhas de totalização excluídas: {{ implode(', ', $previa['ignoradas']) }}.</p>@endif
            @if(collect($previa['linhas'])->sum('formulas'))<p class="mt-2 text-sm text-amber-900">Há fórmulas em campos mapeados. Foram utilizados apenas os valores salvos na planilha, sem executar fórmulas. Confira os resultados antes de confirmar.</p>@endif
            <div class="overflow-x-auto mt-3">
                <table class="min-w-full divide-y divide-gray-200 text-sm"><thead><tr><th class="text-left p-3">Linha</th><th class="text-left p-3">Tomador</th><th class="text-left p-3">Serviço / descrição</th><th class="text-right p-3">Valor</th><th class="text-left p-3">Situação</th></tr></thead><tbody>
                @foreach ($previa['linhas'] as $linha)
                    <tr wire:key="previa-{{ $linha['linha'] }}"><td class="p-3">{{ $linha['linha'] }}</td><td class="p-3">{{ $linha['tomador'] }}@if($linha['novo_tomador'])<p class="text-gray-600">Novo cadastro</p>@endif @if($linha['endereco_integral'])<p class="text-gray-600">Endereço informado: {{ $linha['endereco_integral'] }}</p>@endif</td><td class="p-3">{{ $linha['servico'] }}<p class="text-gray-600">{{ $linha['descricao'] }}</p></td><td class="p-3 text-right">{{ $linha['valor'] }}</td><td class="p-3 {{ $linha['erro'] ? 'text-red-700' : 'text-gray-800' }}">{{ $linha['erro'] ?? 'Apta para rascunho' }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
            <p class="text-sm text-gray-600 mt-3">As linhas pendentes ficam no histórico. Corrija o cadastro/arquivo e reimporte; as notas já preparadas serão sinalizadas como possíveis duplicidades.</p>
            <button type="button" wire:click="importar" wire:confirm="Confirmar os cadastros novos e gerar somente os rascunhos aptos desta conferência? Nenhuma nota será transmitida." wire:loading.attr="disabled" @disabled(!$previa['aptas']) class="mt-3 bg-blue-600 text-white px-4 py-2 rounded-lg disabled:opacity-50">Salvar layout e gerar {{ $previa['aptas'] }} rascunhos</button>
        </div>
    @endif
    <h3 class="font-bold text-gray-800 mt-6">Últimas importações</h3>
    <div class="overflow-x-auto mt-3"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead><tr><th class="text-left p-3">Arquivo</th><th class="text-left p-3">Competência</th><th class="text-right p-3">Geradas</th><th class="text-right p-3">Pendentes</th><th class="text-left p-3">Ação</th></tr></thead><tbody>
        @forelse($historico as $item)<tr wire:key="historico-{{ $item->id }}"><td class="p-3">{{ $item->arquivo_original }}</td><td class="p-3">{{ $item->competencia->format('m/Y') }}</td><td class="p-3 text-right">{{ $item->quantidade_gerada }}</td><td class="p-3 text-right">{{ $item->quantidade_pendente }}</td><td class="p-3"><button type="button" wire:click="abrirHistorico({{ $item->id }})" class="text-blue-600">Conferência</button></td></tr>
        @empty<tr><td colspan="5" class="p-3 text-gray-600">Nenhuma importação realizada.</td></tr>@endforelse
    </tbody></table></div>
    @if($detalhe)
        <div class="mt-4 border border-gray-200 rounded-md p-3"><div class="flex justify-between"><strong>{{ $detalhe->arquivo_original }}</strong><button type="button" wire:click="fecharHistorico" class="text-blue-600">Fechar</button></div>
        @foreach($detalhe->relatorio as $linha)<p class="text-sm mt-2">Linha {{ $linha['linha'] }} — {{ $linha['tomador'] }} — {{ $linha['valor'] }} — {{ $linha['erro'] ?? 'Rascunho gerado' }} @if(filled(data_get($linha,'cadastro.endereco_integral'))) — Endereço informado: {{ data_get($linha,'cadastro.endereco_integral') }} @endif</p>@endforeach
        </div>
    @endif
</div>
