<div wire:key="servico-tributacao-{{ $campo }}" class="block text-sm md:col-span-3">
    <p class="font-medium">{{ $rotulo }}</p>
    @if($campo === 'codigo_tributacao_municipal')
        <p class="text-xs text-gray-600 mt-1">Complemento do município {{ $empresa->municipio ?: $empresa->codigo_municipio_ibge ?: 'não informado' }} para o código nacional selecionado.</p>
        @if($codigosMunicipais)
            <select wire:model="form.codigo_tributacao_municipal" class="w-full rounded-md border-gray-300 mt-1">
                <option value="">Não informado / sem complemento</option>
                @if(filled($form[$campo] ?? null) && !collect($codigosMunicipais)->contains('municipal',$form[$campo]))
                    <option value="{{ $form[$campo] }}">{{ $form[$campo] }} — código salvo, não localizado nesta lista</option>
                @endif
                @foreach($codigosMunicipais as $item)
                    <option value="{{ $item['municipal'] }}">{{ $item['municipal'] }} — {{ $item['descricao'] }}</option>
                @endforeach
            </select>
            <p class="text-xs text-gray-600 mt-1">Lista importada pelo escritório. Confirme a vigência e o município de incidência antes da emissão.</p>
        @else
            <input type="text" wire:model="form.codigo_tributacao_municipal" maxlength="30" class="w-full rounded-md border-gray-300 mt-1" placeholder="Código municipal, se exigido">
            <p class="text-xs text-amber-800 mt-1">{{ blank($form['codigo_tributacao_nacional'] ?? null) ? 'Selecione primeiro o código nacional.' : 'Nenhum complemento municipal disponível na lista carregada. Informe somente se confirmado pela prefeitura; não use o código nacional neste campo.' }}</p>
        @endif
        @if(app(\App\Services\Nfse\PreparacaoNfse::class)->podeConfigurar() && filled($empresa->codigo_municipio_ibge))
            <details class="mt-2 border rounded-md p-3">
                <summary class="cursor-pointer text-blue-700">Carregar tabela municipal</summary>
                <p class="text-xs text-gray-600 mt-2">Utilize a exportação Lista Simples do município, com código completo (XX.XX.XX.XXX), descrição e código original opcional separados por |. A carga substitui a lista de complementos deste município para o escritório. Não altera serviços cadastrados.</p>
                <input type="file" wire:model="tabelaMunicipal" accept=".csv,.txt" class="block w-full mt-2">
                <button type="button" wire:click="importarTabelaMunicipal" wire:confirm="Confirma que a lista corresponde ao município selecionado e deseja substituir a tabela deste município?" wire:loading.attr="disabled" class="mt-2 border rounded-md px-3 py-2">Carregar códigos</button>
            </details>
        @endif
    @else
        @php($tipo = $campo === 'nbs' ? 'nbs' : 'nacional')
        @php($descricao = $tipo === 'nbs' ? $descricaoNbs : $descricaoNacional)
        <div class="mt-1 flex flex-wrap gap-2 items-start">
            <div class="flex-1 min-w-0 border border-gray-300 rounded-md p-2 bg-gray-50 break-words">
                @if(filled($form[$campo] ?? null))
                    <strong>{{ $form[$campo] }}</strong> — {{ $descricao ?? 'Código salvo, não localizado na tabela' }}
                @else
                    <span class="text-gray-600">{{ $tipo === 'nbs' ? 'Selecione a NBS, quando aplicável' : 'Selecione o código nacional' }}</span>
                @endif
            </div>
            <button type="button" wire:click="abrirPesquisaTributaria('{{ $tipo }}')" class="border border-blue-300 text-blue-700 rounded-md px-3 py-2">Pesquisar</button>
            @if($tipo === 'nbs' && filled($form[$campo] ?? null))
                <button type="button" wire:click="limparNbs" class="text-gray-600 px-2 py-2">Limpar</button>
            @endif
        </div>
        @if($pesquisaTributaria === $tipo)
            <div class="mt-2 border rounded-md p-3">
                <div class="flex gap-2 items-center">
                    <input type="search" wire:model.live.debounce.250ms="buscaCodigoTributario" maxlength="100" placeholder="{{ $tipo === 'nacional' ? 'Ex.: advocacia ou 17.14' : 'Ex.: serviços jurídicos ou 1.1301' }}" class="min-w-0 flex-1 rounded-md border-gray-300" aria-label="Pesquisar código ou descrição">
                    <button type="button" wire:click="fecharPesquisaTributaria" class="text-blue-700">Fechar</button>
                </div>
                <p class="text-xs text-gray-600 mt-2">Anexo B v1.01 — 22/01/2026. Até 30 resultados; refine a busca pela atividade ou código.</p>
                @if($catalogoErro)
                    <p class="text-red-700 mt-2">Catálogo indisponível. Solicite a atualização dos arquivos do sistema.</p>
                @else
                    <div class="max-h-72 overflow-y-auto mt-2 divide-y">
                        @forelse($codigosTributarios as $item)
                            <button type="button" wire:key="opcao-{{ $tipo }}-{{ $item['codigo'] }}" wire:click="selecionarCodigoTributario('{{ $tipo }}','{{ $item['codigo'] }}')" class="block w-full text-left p-2 hover:bg-blue-50 focus:bg-blue-50">
                                <strong>{{ $item['codigo'] }}</strong> — {{ $item['descricao'] }}
                            </button>
                        @empty
                            <p class="p-2 text-gray-600">Nenhum código encontrado.</p>
                        @endforelse
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
