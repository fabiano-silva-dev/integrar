<div class="p-6">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white shadow-xl rounded-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">Configurações — Documentos</h1>
                <p class="text-sm text-gray-600 mt-1">Informe as chaves para ler fotos e PDFs que as regras do sistema não reconhecerem.</p>
            </div>
            <div class="p-6">
                <x-documentos-nav ativo="ia" />

                @if (session()->has('message'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('message') }}</div>
                @endif
                @if ($erro)
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ $erro }}</div>
                @endif

                @if ($precisaSelecionarEscritorio)
                    <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                        Selecione um escritório no menu superior para cadastrar as chaves.
                    </div>
                @else
                    <div class="max-w-2xl">
                        <h2 class="text-xl font-semibold text-gray-900">Leitura automática</h2>
                        <p class="mt-1 mb-6 text-sm text-gray-500">
                            XML, DANFE e extrato de banco conhecido o sistema identifica sozinho.
                            As chaves abaixo entram só no que restar: foto, PDF escaneado e documentos sem layout conhecido.
                            Groq e LlamaParse podem ficar em branco.
                        </p>

                        <div class="space-y-3 mb-6 text-sm">
                            <div class="rounded-lg border border-gray-200 px-3 py-2 flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-gray-500">Gemini</div>
                                    <div class="font-semibold {{ $status['gemini'] ? 'text-green-700' : 'text-gray-400' }}">
                                        {{ $status['gemini'] ? 'Informado' : 'Faltando' }}
                                    </div>
                                    @if ($testeGemini)
                                        <p class="text-xs mt-1 {{ $testeGemini['ok'] ? 'text-green-700' : 'text-red-600' }}">{{ $testeGemini['mensagem'] }}</p>
                                    @endif
                                </div>
                                <button type="button"
                                        wire:click="testarGemini"
                                        wire:loading.attr="disabled"
                                        wire:target="testarGemini"
                                        class="shrink-0 h-10 px-4 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-semibold">
                                    <span wire:loading.remove wire:target="testarGemini">Testar</span>
                                    <span wire:loading wire:target="testarGemini">Testando...</span>
                                </button>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2 flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-gray-500">Groq</div>
                                    <div class="font-semibold {{ $status['groq'] ? 'text-green-700' : 'text-gray-400' }}">
                                        {{ $status['groq'] ? 'Informado' : 'Faltando' }}
                                    </div>
                                    @if ($testeGroq)
                                        <p class="text-xs mt-1 {{ $testeGroq['ok'] ? 'text-green-700' : 'text-red-600' }}">{{ $testeGroq['mensagem'] }}</p>
                                    @endif
                                </div>
                                <button type="button"
                                        wire:click="testarGroq"
                                        wire:loading.attr="disabled"
                                        wire:target="testarGroq"
                                        class="shrink-0 h-10 px-4 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-semibold">
                                    <span wire:loading.remove wire:target="testarGroq">Testar</span>
                                    <span wire:loading wire:target="testarGroq">Testando...</span>
                                </button>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2 flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-gray-500">LlamaParse</div>
                                    <div class="font-semibold {{ $status['llama_cloud'] ? 'text-green-700' : 'text-gray-400' }}">
                                        {{ $status['llama_cloud'] ? 'Informado' : 'Faltando' }}
                                    </div>
                                    @if ($testeLlama)
                                        <p class="text-xs mt-1 {{ $testeLlama['ok'] ? 'text-green-700' : 'text-red-600' }}">{{ $testeLlama['mensagem'] }}</p>
                                    @endif
                                </div>
                                <button type="button"
                                        wire:click="testarLlamaParse"
                                        wire:loading.attr="disabled"
                                        wire:target="testarLlamaParse"
                                        class="shrink-0 h-10 px-4 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-semibold">
                                    <span wire:loading.remove wire:target="testarLlamaParse">Testar</span>
                                    <span wire:loading wire:target="testarLlamaParse">Testando...</span>
                                </button>
                            </div>
                        </div>

                        @if ($configuradoEm)
                            <p class="text-xs text-gray-400 mb-4">Última alteração em {{ $configuradoEm->format('d/m/Y H:i') }}.</p>
                        @endif

                        <form wire:submit="salvar" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    Gemini
                                    @if ($status['gemini'])
                                        <span class="font-normal text-gray-500">(deixe em branco para manter a atual)</span>
                                    @endif
                                </label>
                                <p class="text-xs text-gray-500 mt-0.5 mb-1">Lê fotos de comprovante e DANFE. Chave do Google AI Studio.</p>
                                <input type="text"
                                       wire:model="geminiApiKey"
                                       autocomplete="off"
                                       autocorrect="off"
                                       autocapitalize="off"
                                       spellcheck="false"
                                       data-lpignore="true"
                                       data-1p-ignore="true"
                                       data-form-type="other"
                                       class="mt-1 w-full border-gray-300 rounded-md"
                                       style="-webkit-text-security: disc; text-security: disc;">
                                @error('geminiApiKey') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    Groq
                                    @if ($status['groq'])
                                        <span class="font-normal text-gray-500">(deixe em branco para manter a atual)</span>
                                    @endif
                                </label>
                                <p class="text-xs text-gray-500 mt-0.5 mb-1">Reserva quando a cota do Gemini acaba. Crie em console.groq.com.</p>
                                <input type="text"
                                       wire:model="groqApiKey"
                                       autocomplete="off"
                                       autocorrect="off"
                                       autocapitalize="off"
                                       spellcheck="false"
                                       data-lpignore="true"
                                       data-1p-ignore="true"
                                       data-form-type="other"
                                       class="mt-1 w-full border-gray-300 rounded-md"
                                       style="-webkit-text-security: disc; text-security: disc;">
                                @error('groqApiKey') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    LlamaParse
                                    @if ($status['llama_cloud'])
                                        <span class="font-normal text-gray-500">(deixe em branco para manter a atual)</span>
                                    @endif
                                </label>
                                <p class="text-xs text-gray-500 mt-0.5 mb-1">Lê PDF escaneado (várias páginas). Chave do LlamaCloud.</p>
                                <input type="text"
                                       wire:model="llamaCloudApiKey"
                                       autocomplete="off"
                                       autocorrect="off"
                                       autocapitalize="off"
                                       spellcheck="false"
                                       data-lpignore="true"
                                       data-1p-ignore="true"
                                       data-form-type="other"
                                       class="mt-1 w-full border-gray-300 rounded-md"
                                       style="-webkit-text-security: disc; text-security: disc;">
                                @error('llamaCloudApiKey') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <button type="submit"
                                    class="w-full h-14 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="salvar">Salvar chaves</span>
                                <span wire:loading wire:target="salvar">Salvando...</span>
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
