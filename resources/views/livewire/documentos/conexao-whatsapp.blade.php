<div class="p-6" @if($aguardandoQr) wire:poll.2s="atualizarEstado" @endif>
    <div class="max-w-4xl mx-auto">
        <div class="bg-white shadow-xl rounded-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">Configurações — Documentos</h1>
                <p class="text-sm text-gray-600 mt-1">Conecte o WhatsApp do escritório para receber arquivos dos grupos.</p>
            </div>
            <div class="p-6">
                <x-documentos-nav ativo="whatsapp" />

                @if (session()->has('message'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('message') }}</div>
                @endif
                @if (session()->has('error'))
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>
                @endif

                @if ($precisaSelecionarEscritorio)
                    <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                        Selecione um escritório no menu superior para conectar o WhatsApp.
                    </div>
                @else
                    <div class="max-w-2xl">
                        @if ($conectado)
                            <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-5 mb-6">
                                <p class="text-xs font-semibold uppercase tracking-wide text-green-700">WhatsApp conectado</p>
                                <p class="mt-1 text-lg font-semibold text-gray-900">
                                    {{ $telefone ?: 'Número do escritório' }}
                                </p>
                                <p class="mt-2 text-sm text-green-800">
                                    Arquivos enviados nos grupos monitorados entram neste módulo.
                                </p>
                            </div>

                            <a href="{{ route('documentos.grupos') }}"
                               class="inline-flex w-full h-14 items-center justify-center rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold">
                                Ir para os grupos
                            </a>
                            <button type="button" wire:click="desconectar" wire:loading.attr="disabled"
                                    class="mt-4 w-full text-sm text-gray-600">
                                Desconectar
                            </button>
                        @elseif ($aguardandoQr)
                            <h2 class="text-xl font-semibold text-gray-900">Escaneie o QR Code</h2>
                            <p class="mt-1 mb-5 text-sm text-gray-500">
                                {{ $mensagem ?: 'No celular: WhatsApp → Aparelhos conectados → Conectar um aparelho.' }}
                            </p>

                            <div class="mb-6 flex flex-col items-center rounded-xl border border-gray-200 bg-gray-50 p-6">
                                @if ($qrcode)
                                    <img src="data:image/png;base64,{{ $qrcode }}" alt="QR Code WhatsApp"
                                         class="w-64 h-64 rounded-lg bg-white border border-gray-200">
                                @else
                                    <div class="w-64 h-64 rounded-lg bg-white border border-dashed border-gray-300 flex items-center justify-center text-sm text-gray-500">
                                        Gerando o código...
                                    </div>
                                @endif
                                <p class="mt-4 text-xs text-gray-500">O código atualiza sozinho. Se expirar, gere outro.</p>
                            </div>

                            <button type="button" wire:click="conectar" wire:loading.attr="disabled"
                                    class="w-full h-14 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold">
                                <span wire:loading.remove wire:target="conectar">Gerar novo QR Code</span>
                                <span wire:loading wire:target="conectar">Gerando...</span>
                            </button>
                            <button type="button" wire:click="desconectar" wire:loading.attr="disabled"
                                    class="mt-4 w-full text-sm text-gray-600">
                                Cancelar
                            </button>
                        @else
                            <h2 class="text-xl font-semibold text-gray-900">Conectar o WhatsApp do escritório</h2>
                            <p class="mt-1 mb-5 text-sm text-gray-500">
                                Um número por escritório. Use o celular que já participa dos grupos dos clientes.
                            </p>

                            @if ($falhou)
                                <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    {{ $erro ?: $mensagem ?: 'A última conexão não concluiu. Tente de novo.' }}
                                </div>
                            @endif

                            <ol class="text-sm text-gray-700 space-y-3 list-decimal list-inside mb-6">
                                <li>Abra o WhatsApp no celular do escritório.</li>
                                <li>Toque em <strong>Aparelhos conectados</strong> e depois em <strong>Conectar um aparelho</strong>.</li>
                                <li>Clique abaixo e aponte a câmera para o QR Code.</li>
                            </ol>

                            <button type="button" wire:click="conectar" wire:loading.attr="disabled"
                                    class="w-full h-14 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold">
                                <span wire:loading.remove wire:target="conectar">
                                    {{ $falhou ? 'Tentar de novo' : 'Conectar WhatsApp' }}
                                </span>
                                <span wire:loading wire:target="conectar">Gerando QR Code...</span>
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
