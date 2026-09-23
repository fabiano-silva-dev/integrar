@if($aguardandoEscolhaLayout)
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 class="text-lg font-semibold text-blue-800 mb-2">Layouts Disponíveis</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="border-2 border-dashed border-blue-300 rounded-lg p-3 bg-white hover:bg-blue-50 cursor-pointer" wire:click="iniciarNovoLayout">
                <div class="font-medium text-blue-800">Novo layout</div>
                <p class="mt-1 text-sm text-gray-600">Mapear este arquivo</p>
            </div>
            @foreach($layoutsDisponiveis as $layout)
                <div class="border rounded-lg p-3 bg-white hover:bg-gray-50 cursor-pointer" wire:key="layout-disponivel-{{ $layout->id }}" wire:click="carregarLayout({{ $layout->id }})">
                    <div class="font-medium">{{ $layout->nome }}</div>
                    <div class="text-sm text-gray-600">{{ strtoupper($layout->tipo_arquivo) }}</div>
                    @if($layout->regrasAmarracao->isNotEmpty())
                        <ul class="mt-2 text-sm text-gray-700 list-disc list-inside">
                            @foreach($layout->regrasAmarracao as $regraLayout)
                                <li>{{ $regraLayout->nome_regra }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-2 text-sm text-gray-500">Nenhuma regra salva</p>
                    @endif
                    <div class="mt-1 text-xs text-gray-500">{{ $layout->colunas->count() }} colunas mapeadas</div>
                </div>
            @endforeach
        </div>
    </div>
@endif
