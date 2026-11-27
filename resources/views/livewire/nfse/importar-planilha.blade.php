<div class="mt-6 space-y-4">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-center">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Importar planilha de serviços</h2>
            <p class="text-sm text-gray-600 mt-2">Envie a planilha, confira o mapeamento sugerido e ajuste os dados antes de gerar os rascunhos.</p>
            <p class="text-sm text-gray-600 mt-2">Nenhuma nota é transmitida nesta etapa.</p>
            <p class="text-xs text-gray-600 mt-1">Se houver endereço, o sistema consulta o CEP e pode usar a IA configurada no escritório para interpretar somente essa coluna.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
                <label class="block text-sm">Layout
                    <select wire:model.live="layoutId" wire:loading.attr="disabled" class="w-full rounded-md border-gray-300">
                        <option value="">Novo layout</option>
                        @foreach($layouts as $layout)<option value="{{ $layout->id }}">{{ $layout->nome }} — v{{ $layout->versao }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm">Competência fiscal
                    <input type="date" wire:model.live="competencia" class="w-full rounded-md border-gray-300">
                </label>
            </div>
        </div>
        <div wire:key="nfse-upload-{{ $empresaId }}-{{ $uploadVersao }}">
            <x-zona-upload input-id="nfse-planilha-{{ $empresaId }}-{{ $uploadVersao }}"
                accept=".csv,.xls,.xlsx" formato="CSV, XLS ou XLSX · até 2 MB e 200 linhas"
                :nome-arquivo="$arquivo instanceof \Illuminate\Http\UploadedFile ? $arquivo->getClientOriginalName() : null"
                wire:model="arquivo" />
            <p wire:loading wire:target="arquivo,detectarColunas,lerCabecalho,layoutId,config.aba" class="mt-2 text-sm text-blue-700" role="status">Reconhecendo a estrutura da planilha...</p>
        </div>
    </div>
    @if(session('nfse_importacao_mensagem'))
        <p role="status" class="bg-gray-50 p-3 rounded-md">{{ session('nfse_importacao_mensagem') }}</p>
    @endif
    @if($errors->any())
        <div role="alert" class="border border-red-300 rounded-md bg-red-50 p-3 text-red-700">
            @foreach($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach
        </div>
    @endif
    @if($arquivo)
        <div class="border rounded-lg p-3 {{ $colunas ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-gray-50' }}">
            <div class="flex flex-wrap gap-x-5 gap-y-2 items-center text-sm">
                <strong class="text-gray-800">{{ $colunas ? 'Colunas reconhecidas automaticamente' : 'Configuração da leitura' }}</strong>
                @if(count($abas) > 1 && !$layoutId)
                    <label class="flex items-center gap-2">Aba
                        <select wire:model.live="config.aba" wire:loading.attr="disabled" class="rounded-md border-gray-300 py-1 text-sm">
                            @foreach($abas as $indice=>$nome)<option value="{{ $indice }}">{{ $nome }}</option>@endforeach
                        </select>
                    </label>
                @elseif(count($abas))
                    <span>Aba: <strong>{{ $abas[$config['aba']] ?? '—' }}</strong></span>
                @endif
                @if($colunas)<span>Cabeçalho: <strong>linha {{ $config['cabecalho'] }}</strong></span>@endif
                @if($tipoArquivo === 'csv' && $colunas)
                    <span>Separador: <strong>{{ ['ponto_virgula'=>'Ponto e vírgula','virgula'=>'Vírgula','tab'=>'Tabulação'][$config['delimitador']] ?? '—' }}</strong></span>
                @endif
                @if($colunas)<span>{{ count($amostra) }} linhas de dados</span>@endif
                @if($ajustes)<span class="text-blue-800">{{ count($ajustes) }} linhas ajustadas. Trocar arquivo, aba ou layout descarta os ajustes.</span>@endif
            </div>
            @if(!$layoutId)
                <details class="mt-2" wire:key="nfse-ajustes-{{ $uploadVersao }}" @if(!$colunas) open @endif>
                    <summary class="cursor-pointer text-sm font-medium text-blue-700">Ajustes manuais</summary>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                        <label class="block text-sm">Linha do cabeçalho
                            <input type="number" min="1" max="20" wire:model="config.cabecalho" class="w-full rounded-md border-gray-300">
                        </label>
                        @if($tipoArquivo === 'csv')
                            <label class="block text-sm">Separador CSV
                                <select wire:model="config.delimitador" class="w-full rounded-md border-gray-300">
                                    <option value="ponto_virgula">Ponto e vírgula</option><option value="virgula">Vírgula</option><option value="tab">Tabulação</option>
                                </select>
                            </label>
                        @endif
                        <div class="flex flex-wrap items-end gap-2">
                            <button type="button" wire:click="lerCabecalho" wire:loading.attr="disabled" class="border border-gray-300 bg-white rounded-md px-3 py-2 text-sm">Aplicar leitura</button>
                            <button type="button" wire:click="detectarColunas" wire:loading.attr="disabled" class="text-blue-700 text-sm px-2 py-2">Detectar novamente</button>
                        </div>
                    </div>
                    <p class="text-xs text-gray-600 mt-2">Alterar a leitura, a aba, o layout ou o arquivo descarta os ajustes das células.</p>
                </details>
            @endif
        </div>
    @endif
    @if($colunas)
        <section class="border border-gray-200 rounded-lg p-4">
            <div class="flex flex-wrap justify-between items-center gap-2">
                <h3 class="font-semibold text-gray-800">Mapeamento de colunas</h3>
                @if($layoutId)
                    <button type="button" wire:click="ajustarLayout" class="text-sm text-blue-700">Ajustar mapeamento e salvar nova versão</button>
                @endif
            </div>
            <p class="text-sm text-gray-600 mt-1">Confira as relações entre as colunas da planilha e os campos da NFS-e.</p>
            <fieldset @disabled($layoutId) class="mt-4">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    @foreach(['coluna_nome'=>'Nome / razão social do tomador','coluna_tomador'=>'CPF/CNPJ ou código do tomador','coluna_endereco'=>'Endereço completo (separar automaticamente)','coluna_valor'=>'Valor do serviço','coluna_servico'=>'Código interno do serviço (opcional)','coluna_descricao'=>'Descrição (opcional)'] as $campo=>$rotulo)
                        <label class="flex flex-col sm:flex-row sm:items-center gap-2 border border-gray-200 bg-gray-50 rounded-md p-2 text-sm" wire:key="mapa-{{ $campo }}">
                            <span class="flex-1 font-medium">{{ $rotulo }}</span>
                            <span class="hidden sm:inline text-gray-500" aria-hidden="true">←</span>
                            <select wire:model.live="config.{{ $campo }}" class="w-full sm:w-1/2 rounded-md border-gray-300 text-sm">
                                <option value="">Não mapeado</option>@foreach($colunas as $indice=>$coluna)<option value="{{ $indice }}">{{ $coluna }}</option>@endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
                @if(!filled($config['coluna_servico'] ?? null))
                    <label class="block text-sm mt-3">Serviço para as notas
                        <select wire:model.live="config.servico_fixo" class="w-full rounded-md border-gray-300">
                            <option value="">Selecione o serviço</option>@foreach($servicos as $servico)<option value="{{ $servico->codigo }}">{{ $servico->nome }} — {{ $servico->codigo }}</option>@endforeach
                        </select>
                    </label>
                @endif
                <label class="flex items-center gap-2 mt-4 text-sm"><input type="checkbox" wire:model.live="config.cadastrar_tomadores" class="rounded border-gray-300"> Cadastrar tomadores inexistentes ao confirmar a importação</label>
                <p class="text-xs text-gray-600 mt-1">Os cadastros existentes não são sobrescritos.</p>
                <details class="mt-3 border rounded-lg p-3">
                    <summary class="cursor-pointer font-medium text-sm">Outros campos e opções do mapeamento</summary>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                        <label class="block text-sm">Identificar tomador por
                            <select wire:model.live="config.identificacao" class="w-full rounded-md border-gray-300"><option value="documento">CPF/CNPJ</option><option value="codigo_externo">Código externo cadastrado</option></select>
                        </label>
                        <label class="block text-sm">Formato de valores digitados ou texto
                            <select wire:model.live="config.formato_valor" class="w-full rounded-md border-gray-300"><option value="br">Brasileiro: 1.234,56</option><option value="decimal">Decimal: 1234.56</option></select>
                        </label>
                        @foreach(['coluna_cep'=>'CEP','coluna_logradouro'=>'Logradouro','coluna_numero'=>'Número','coluna_bairro'=>'Bairro','coluna_complemento'=>'Complemento','coluna_municipio'=>'Código IBGE do município','coluna_cidade'=>'Município (nome)','coluna_uf'=>'UF','coluna_email'=>'E-mail','coluna_telefone'=>'Telefone'] as $campo=>$rotulo)
                            <label class="block text-sm" wire:key="mapa-{{ $campo }}">{{ $rotulo }}<select wire:model.live="config.{{ $campo }}" class="w-full rounded-md border-gray-300"><option value="">Não mapeado</option>@foreach($colunas as $indice=>$coluna)<option value="{{ $indice }}">{{ $coluna }}</option>@endforeach</select></label>
                        @endforeach
                    </div>
                    <p class="text-xs text-gray-600 mt-2">Quando o endereço estiver em uma única coluna, o sistema separa os campos automaticamente. Colunas específicas têm preferência. Confira o código IBGE, número e bairro na grade; os dados originais ficam no histórico.</p>
                </details>
            </fieldset>
        </section>
        <section class="border border-gray-200 rounded-lg p-4">
            <div class="flex flex-wrap justify-between items-center gap-2">
                <h3 class="font-semibold text-gray-800">Prévia completa da planilha</h3>
                <span class="text-sm text-gray-600">{{ count($amostra) }} linhas · {{ count($ajustes) }} ajustadas</span>
            </div>
            <p class="text-sm text-gray-600 mt-1">Todos os campos de endereço e serviço ficam separados para conferência. Use Editar para corrigir os dados. O arquivo original permanece intacto.</p>
            @if($ignoradas)<p class="text-sm text-gray-600 mt-2">Linhas de totalização identificadas e excluídas: {{ implode(', ', $ignoradas) }}.</p>@endif
            @if($enderecos)
                <div @if(count($enderecosProcessados)<count($enderecos) && $linhaEdicao === null) wire:poll.8s="completarEnderecos" @endif class="mt-3 p-3 rounded-md border border-blue-200 bg-blue-50 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div><strong>Reconhecimento de endereços</strong> · {{ count($enderecosProcessados) }} de {{ count($enderecos) }} processados
                            <p class="text-xs text-gray-600 mt-1">CEP/UF por leitura local; município e IBGE por consulta de CEP. {{ ($ia['gemini'] || $ia['groq']) ? 'IA disponível para interpretar endereços ambíguos.' : 'IA não configurada. A leitura local e a consulta de CEP continuam disponíveis.' }} Somente o texto do endereço (sem nome, CPF e valor das demais colunas) é enviado à IA.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if(count($enderecosProcessados)<count($enderecos))
                                <button type="button" wire:click="completarEnderecos" wire:loading.attr="disabled" @disabled($linhaEdicao !== null) class="bg-blue-600 text-white px-3 py-2 rounded-md">Completar próximos endereços agora</button>
                            @else
                                <button type="button" wire:click="tentarNovamenteEnderecos" wire:loading.attr="disabled" @disabled($linhaEdicao !== null) class="text-blue-700">Consultar pendências novamente</button>
                            @endif
                        </div>
                    </div>
                    <p wire:loading wire:target="completarEnderecos,tentarNovamenteEnderecos,arquivo" role="status" class="mt-2 text-blue-800">Consultando CEP e interpretando endereços...</p>
                </div>
            @endif
            <div class="overflow-auto max-h-[42rem] mt-3 border border-gray-200 rounded-md">
                <table class="w-full table-fixed min-w-[1050px] divide-y divide-gray-200 text-xs">
                    <colgroup>
                        <col style="width:3%"><col style="width:12%"><col style="width:8%"><col style="width:6%"><col style="width:10%"><col style="width:9%"><col style="width:13%"><col style="width:5%"><col style="width:10%"><col style="width:13%"><col style="width:6%"><col style="width:5%">
                    </colgroup>
                    <thead class="sticky top-0 bg-gray-100 z-10">
                        <tr><th class="text-left p-2">Lin.</th><th class="text-left p-2">Tomador</th><th class="text-left p-2">CPF/CNPJ</th><th class="text-left p-2">CEP</th><th class="text-left p-2">Município / UF / IBGE</th><th class="text-left p-2">Bairro</th><th class="text-left p-2">Logradouro</th><th class="text-left p-2">Nº</th><th class="text-left p-2">Complemento</th><th class="text-left p-2">Serviço / descrição</th><th class="text-right p-2">Valor</th><th class="text-left p-2">Ação</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($gradeFiscal as $numero=>$linha)
                            @php($e = $enderecos[$numero] ?? [])
                            <tr wire:key="nfse-planilha-linha-{{ $numero }}" class="{{ isset($ajustes[$numero]) || ($e['origem'] ?? '') === 'ajuste manual' ? 'bg-blue-50' : 'hover:bg-gray-50' }}">
                                <td class="p-2 align-top">{{ $numero }}</td>
                                <td class="p-2 align-top break-words"><strong>{{ $linha['nome'] ?: '—' }}</strong>@if($linha['existente'])<p class="text-gray-600 mt-1">{{ $linha['ativo'] ? 'Cadastro existente' : 'Cadastro inativo' }}</p>@endif @if(filled($linha['email'] ?? null))<p class="text-gray-600">{{ $linha['email'] }}</p>@endif @if(filled($linha['telefone'] ?? null))<p class="text-gray-600">{{ $linha['telefone'] }}</p>@endif</td>
                                <td class="p-2 align-top break-all">{{ $linha['documento'] ?: '—' }}</td>
                                <td class="p-2 align-top break-words {{ blank($linha['cep'] ?? null) ? 'text-red-700' : '' }}">{{ $linha['cep'] ?: '—' }}</td>
                                <td class="p-2 align-top break-words">
                                    <p>{{ ($linha['municipio'] ?? '') ?: '—' }} @if(filled($linha['uf'] ?? null)) / {{ $linha['uf'] }}@endif</p>
                                    <p class="{{ blank($linha['codigo_municipio_ibge'] ?? null) ? 'text-red-700' : 'text-gray-600' }}">{{ $linha['codigo_municipio_ibge'] ?: 'IBGE pendente' }}</p>
                                    @if(filled($e['aviso'] ?? null) && !$linha['existente'])<p class="text-red-700 mt-1">{{ $e['aviso'] }}</p>@endif
                                </td>
                                @foreach(['bairro','logradouro','numero','complemento'] as $campo)
                                    <td class="p-2 align-top break-words {{ $campo !== 'complemento' && blank($linha[$campo] ?? null) ? 'text-red-700' : '' }}">{{ ($linha[$campo] ?? '') ?: '—' }}</td>
                                @endforeach
                                <td class="p-2 align-top break-words"><strong>{{ $linha['servico'] ?: '—' }}</strong><p class="text-gray-600">{{ $linha['descricao'] }}</p></td>
                                <td class="p-2 align-top text-right break-words">{{ $linha['valor_formatado'] ?? ($linha['valor'] ?: '—') }}</td>
                                <td class="p-2 align-top">
                                    <button type="button" wire:click="editarLinha({{ $numero }})" wire:loading.attr="disabled" class="text-blue-700 font-medium">Editar</button>
                                    @if(isset($ajustes[$numero]) || ($e['origem'] ?? '') === 'ajuste manual')<button type="button" wire:click="desfazerLinha({{ $numero }})" wire:loading.attr="disabled" class="block mt-1 text-gray-600">Desfazer</button>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-600">Endereços de tomadores existentes são exibidos conforme o cadastro atual, que não é sobrescrito. Para novos tomadores, confirme os campos reconhecidos. Complemento é opcional.</p>
            @if($linhaEdicao !== null)
                <form x-data x-init="$nextTick(() => $el.scrollIntoView({block: 'nearest'}))" wire:submit="salvarEdicao" class="mt-4 border border-blue-200 rounded-lg bg-blue-50 p-4 space-y-3" wire:key="nfse-editor-{{ $linhaEdicao }}">
                    <h4 class="font-semibold">Editar linha {{ $linhaEdicao }}</h4>
                    <p class="text-sm text-gray-600">Os valores originais são preservados. Se alterar o valor do serviço, digite no formato {{ $config['formato_valor'] === 'br' ? 'brasileiro (1.234,56)' : 'decimal (1234.56)' }}.</p>
                    <h5 class="font-medium text-sm">Endereço fiscal reconhecido</h5>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        @foreach(['cep'=>'CEP','municipio'=>'Município','uf'=>'UF','codigo_municipio_ibge'=>'Código IBGE','bairro'=>'Bairro','logradouro'=>'Logradouro','numero'=>'Número','complemento'=>'Complemento'] as $campo=>$rotulo)
                            <label class="block text-sm">{{ $rotulo }}
                                <input type="text" wire:model="edicaoEndereco.{{ $campo }}" maxlength="{{ $campo === 'cep' ? 9 : ($campo === 'uf' ? 2 : ($campo === 'codigo_municipio_ibge' ? 7 : 255)) }}" class="block w-full rounded-md border-gray-300 text-sm">
                            </label>
                        @endforeach
                    </div>
                    <button type="button" wire:click="consultarCepEdicao" wire:loading.attr="disabled" class="text-sm font-medium text-blue-700">Consultar CEP e preencher endereço</button>
                    @if(filled($enderecos[$linhaEdicao]['aviso'] ?? null))
                        <p class="text-sm text-red-700">{{ $enderecos[$linhaEdicao]['aviso'] }}</p>
                    @endif
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="confirmarEndereco"> Conferi o CEP, município/UF e código IBGE do endereço corrigido.</label>
                    <details class="border rounded-md bg-white p-3" open>
                        <summary class="font-medium text-sm cursor-pointer">Dados originais da planilha (nome, documento, valor e demais colunas)</summary>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                        @foreach($colunas as $indice=>$coluna)
                            <label class="block text-sm">{{ $coluna }}
                                <textarea wire:model="edicao.{{ $indice }}" rows="{{ mb_strlen((string)($edicao[$indice] ?? '')) > 100 ? 3 : 1 }}" maxlength="8000" class="block w-full rounded-md border-gray-300 text-sm"></textarea>
                            </label>
                        @endforeach
                        </div>
                    </details>
                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" wire:click="fecharEdicao" class="border border-gray-300 bg-white rounded-md px-4 py-2 text-sm">Cancelar edição</button>
                        <button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-md">Salvar ajustes</button>
                    </div>
                </form>
            @endif
        </section>
        @if(!$layoutId)
            <label class="block text-sm max-w-sm">Salvar layout como
                <input type="text" wire:model="nomeLayout" maxlength="120" class="w-full rounded-md border-gray-300">
            </label>
        @endif
        @if($previa)
            <section class="border border-gray-200 rounded-lg p-4">
                <h3 class="font-bold text-gray-800">Conferência para gerar rascunhos</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                    <div class="bg-gray-50 p-3 rounded-md">Aptas: <strong>{{ $previa['aptas'] }}</strong></div>
                    <div class="bg-gray-50 p-3 rounded-md">Pendentes: <strong>{{ $previa['pendentes'] }}</strong></div>
                    <div class="bg-gray-50 p-3 rounded-md">Total apto: <strong>R$ {{ \App\Services\Nfse\ImportacaoPlanilha::formatarValorBr($previa['total']) }}</strong></div>
                </div>
                @if($previa['novos_tomadores'])<p class="mt-3 text-sm">Serão cadastrados {{ $previa['novos_tomadores'] }} novos tomadores ao confirmar.</p>@endif
                @if(collect($previa['linhas'])->sum('formulas'))<p class="mt-2 text-sm text-amber-900">Há fórmulas em campos mapeados. Foram utilizados os valores salvos, sem executar fórmulas.</p>@endif
                <div class="overflow-auto max-h-[32rem] mt-3">
                    <table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="sticky top-0 bg-gray-100"><tr><th class="text-left p-2">Linha</th><th class="text-left p-2">Tomador</th><th class="text-left p-2">Serviço / descrição</th><th class="text-right p-2">Valor</th><th class="text-left p-2">Situação</th><th></th></tr></thead><tbody>
                        @foreach($previa['linhas'] as $linha)
                            <tr wire:key="previa-{{ $linha['linha'] }}" class="border-b"><td class="p-2">{{ $linha['linha'] }}</td><td class="p-2">{{ $linha['tomador'] }}@if($linha['novo_tomador'])<p class="text-gray-600">Novo cadastro</p>@endif @php($cad = $linha['cadastro_efetivo'] ?? [])
                            @if(filled($cad['logradouro'] ?? null))<p class="text-gray-600">{{ $cad['logradouro'] }}, {{ $cad['numero'] ?? '' }} · {{ $cad['bairro'] ?? '' }} · {{ $cad['cep'] ?? '' }} · {{ $cad['uf'] ?? '' }} (IBGE {{ $cad['codigo_municipio_ibge'] ?? '—' }})</p>@endif</td><td class="p-2">{{ $linha['servico'] }}<p class="text-gray-600">{{ $linha['descricao'] }}</p></td><td class="p-2 text-right">{{ $linha['valor_formatado'] ?? $linha['valor'] }}</td><td class="p-2 {{ $linha['erro'] ? 'text-red-700' : 'text-gray-800' }}">{{ $linha['erro'] ?? 'Apta para rascunho' }}@if($linha['ajustada'])<p class="text-blue-700">Ajustada</p>@endif</td><td class="p-2"><button type="button" wire:click="editarLinha({{ $linha['linha'] }})" class="text-blue-700">Editar</button></td></tr>
                        @endforeach
                    </tbody></table>
                </div>
                <p class="text-sm text-gray-600 mt-3">Corrija as pendências antes de confirmar. Se prosseguir, apenas as linhas aptas serão geradas; as demais ficam no histórico.</p>
            </section>
        @endif
    @endif
    @if($arquivo)
        <div class="flex flex-wrap justify-end items-center gap-3 border-t pt-4">
            <span wire:loading class="text-sm text-gray-600" role="status">Processando...</span>
            <button type="button" wire:click="cancelar" wire:confirm="Descartar a planilha e os ajustes desta importação?" class="border border-gray-300 rounded-md px-4 py-2">Cancelar</button>
            <button type="button" wire:click="conferir" wire:loading.attr="disabled" @disabled(!$colunas || $linhaEdicao !== null || count($enderecosProcessados)<count($enderecos)) class="px-4 py-2 rounded-md disabled:opacity-50 {{ $previa ? 'border border-gray-300 text-blue-700' : 'bg-blue-600 text-white' }}">Conferir dados</button>
            @if($previa)
                <button type="button" wire:click="importar" wire:confirm="Confirmar os cadastros novos e gerar os rascunhos aptos? Nenhuma nota será transmitida." wire:loading.attr="disabled" @disabled(!$previa['aptas'] || $linhaEdicao !== null) class="bg-blue-600 text-white px-4 py-2 rounded-md disabled:opacity-50">Gerar {{ $previa['aptas'] }} rascunhos</button>
            @endif
        </div>
    @endif
    <h3 class="font-bold text-gray-800 mt-6">Últimas importações</h3>
    <div class="overflow-x-auto mt-3"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead><tr><th class="text-left p-3">Arquivo</th><th class="text-left p-3">Competência</th><th class="text-right p-3">Geradas</th><th class="text-right p-3">Pendentes</th><th class="text-left p-3">Ação</th></tr></thead><tbody>
        @forelse($historico as $item)<tr wire:key="historico-{{ $item->id }}"><td class="p-3">{{ $item->arquivo_original }}</td><td class="p-3">{{ $item->competencia->format('m/Y') }}</td><td class="p-3 text-right">{{ $item->quantidade_gerada }}</td><td class="p-3 text-right">{{ $item->quantidade_pendente }}</td><td class="p-3"><button type="button" wire:click="abrirHistorico({{ $item->id }})" class="text-blue-600">Conferência</button></td></tr>
        @empty<tr><td colspan="5" class="p-3 text-gray-600">Nenhuma importação realizada.</td></tr>@endforelse
    </tbody></table></div>
    @if($detalhe)
        <div class="mt-4 border border-gray-200 rounded-md p-3"><div class="flex justify-between"><strong>{{ $detalhe->arquivo_original }}</strong><button type="button" wire:click="fecharHistorico" class="text-blue-600">Fechar</button></div>
        @foreach($detalhe->relatorio as $linha)<p class="text-sm mt-2">Linha {{ $linha['linha'] }} — {{ $linha['tomador'] }} — {{ $linha['valor_formatado'] ?? (isset($linha['dados']['valor']) ? \App\Services\Nfse\ImportacaoPlanilha::formatarValorBr($linha['dados']['valor']) : $linha['valor']) }} — {{ $linha['erro'] ?? 'Rascunho gerado' }} @if(!empty($linha['ajustes'])) · {{ count($linha['ajustes']) }} campo(s) ajustado(s) @endif @if(filled(data_get($linha,'endereco_revisao.origem'))) · Endereço: {{ data_get($linha,'endereco_revisao.origem') }} @endif @if(filled(data_get($linha,'cadastro.endereco_integral'))) — Endereço informado: {{ data_get($linha,'cadastro.endereco_integral') }} @endif</p>@endforeach
        </div>
    @endif
</div>
