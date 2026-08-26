<div class="p-6">
    <div class="max-w-6xl mx-auto">
        <div class="bg-white shadow-xl rounded-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">Configurações — Documentos</h1>
                <p class="text-sm text-gray-600 mt-1">Arquivos recebidos dos grupos e enviados ao Drive.</p>
            </div>
            <div class="p-6">
                <x-documentos-nav ativo="recebidos" />

                @if ($precisaSelecionarEscritorio)
                    <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                        Selecione um escritório no menu superior para ver os documentos.
                    </div>
                @else
                    <div class="flex flex-wrap gap-3 mb-4">
                        <input type="text" wire:model.live.debounce.400ms="busca" placeholder="Buscar arquivo"
                               class="border-gray-300 rounded-md flex-1 min-w-[180px]">
                        <select wire:model.live="filtroEmpresa" class="border-gray-300 rounded-md">
                            <option value="">Todas as empresas</option>
                            @foreach ($empresas as $empresa)
                                <option value="{{ $empresa->id }}">{{ $empresa->nome_fantasia ?: $empresa->nome }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="filtroStatus" class="border-gray-300 rounded-md">
                            <option value="">Todos os status</option>
                            @foreach ($statusLista as $st)
                                <option value="{{ $st->value }}">{{ $st->rotulo() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="overflow-x-auto border rounded-lg">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left px-3 py-2">Data</th>
                                    <th class="text-left px-3 py-2">Arquivo</th>
                                    <th class="text-left px-3 py-2">Empresa / grupo</th>
                                    <th class="text-left px-3 py-2">Tipo</th>
                                    <th class="text-left px-3 py-2">Status</th>
                                    <th class="text-left px-3 py-2">Drive</th>
                                    <th class="text-left px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($documentos as $doc)
                                    <tr class="border-t align-top">
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $doc->created_at?->format('d/m/Y H:i') }}</td>
                                        <td class="px-3 py-2">{{ $doc->nome_original }}
                                            @if (! empty($doc->metadados['sugestao_nome_arquivo']))
                                                <div class="text-xs text-gray-400">Nome no Drive: {{ $doc->metadados['sugestao_nome_arquivo'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            <div>{{ $doc->empresa?->nome_fantasia ?: $doc->empresa?->nome ?: '—' }}</div>
                                            <div class="text-xs text-gray-400">{{ $doc->grupo?->nome }}</div>
                                        </td>
                                        <td class="px-3 py-2">
                                            <select wire:change="alterarTipo({{ $doc->id }}, $event.target.value)" class="border-gray-300 rounded-md text-xs">
                                                @foreach ($tipos as $tipo)
                                                    <option value="{{ $tipo->value }}" @selected($doc->tipo_documento?->value === $tipo->value)>
                                                        {{ $tipo->rotulo() }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-3 py-2">
                                            <span class="text-xs">{{ $doc->status?->rotulo() }}</span>
                                            @php
                                                $origem = $doc->metadados['origem'] ?? null;
                                                $rotuloOrigem = match ($origem) {
                                                    'xml', 'xml_nfe', 'xml_nfce', 'xml_nfse', 'xml_cte', 'xml_mdfe' => 'XML',
                                                    'pdf_fiscal' => 'PDF fiscal',
                                                    'pdf_extrato' => 'Extrato',
                                                    'llamaparse' => 'PDF escaneado',
                                                    'ia_gemini' => 'Foto / leitura automática',
                                                    'ia_groq' => 'Foto / leitura automática',
                                                    'ofx' => 'OFX',
                                                    default => null,
                                                };
                                            @endphp
                                            @if ($rotuloOrigem)
                                                <div class="text-xs text-gray-400">{{ $rotuloOrigem }}</div>
                                            @endif
                                            @if ($doc->erro_mensagem)
                                                <div class="text-xs text-red-600">{{ $doc->erro_mensagem }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            @if ($doc->urlDrive())
                                                <a href="{{ $doc->urlDrive() }}" target="_blank" class="text-indigo-600">Abrir</a>
                                                <div class="text-xs text-gray-400">{{ $doc->drive_path }}</div>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            <button type="button" wire:click="reprocessar({{ $doc->id }})" class="text-indigo-600 text-xs font-semibold">
                                                Reprocessar
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">Nenhum documento recebido ainda.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if (method_exists($documentos, 'links'))
                        <div class="mt-4">{{ $documentos->links() }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
