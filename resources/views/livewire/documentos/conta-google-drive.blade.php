<div class="p-6">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white shadow-xl rounded-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">Configurações — Documentos</h1>
                <p class="text-sm text-gray-600 mt-1">Autorize o Drive do escritório e indique a pasta de cada empresa.</p>
            </div>
            <div class="p-6">
                <x-documentos-nav ativo="drive" />

                @if (session()->has('message'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('message') }}</div>
                @endif
                @if (session()->has('error'))
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>
                @endif
                @if ($erro)
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ $erro }}</div>
                @endif

                @if ($precisaSelecionarEscritorio)
                    <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                        Selecione um escritório no menu superior para conectar o Google Drive.
                    </div>
                @else
                    <div class="mb-8">
                        <div class="flex gap-2">
                            @foreach ([1, 2, 3] as $i)
                                <div @class([
                                    'h-2 flex-1 rounded-full',
                                    'bg-indigo-600' => $passo >= $i,
                                    'bg-gray-200' => $passo < $i,
                                ])></div>
                            @endforeach
                        </div>
                        <div class="mt-3 grid grid-cols-3 gap-2 text-xs sm:text-sm">
                            <span @class(['font-semibold text-indigo-700' => $passo === 1, 'text-gray-500' => $passo !== 1])>Aplicativo</span>
                            <span @class(['font-semibold text-indigo-700' => $passo === 2, 'text-gray-500' => $passo !== 2])>Conta do escritório</span>
                            <span @class(['font-semibold text-indigo-700' => $passo === 3, 'text-gray-500' => $passo !== 3])>Pastas das empresas</span>
                        </div>
                    </div>

                    @if ($passo === 1)
                        <div class="max-w-2xl" wire:key="wizard-drive-1">
                            <h2 class="text-xl font-semibold text-gray-900">1. Liberar o Google Drive</h2>
                            <p class="mt-1 mb-5 text-sm text-gray-500">
                                Uma vez por escritório. Use a conta Google que já guarda as pastas dos clientes.
                            </p>

                            <ol class="text-sm text-gray-700 space-y-3 list-decimal list-inside mb-6">
                                <li>
                                    Abra o
                                    <a href="https://console.cloud.google.com/" target="_blank" rel="noopener" class="text-indigo-600 font-semibold">Google Cloud</a>
                                    e crie um projeto (ou escolha um existente).
                                </li>
                                <li>
                                    Na biblioteca de APIs, ative só o
                                    <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener" class="text-indigo-600 font-semibold">Google Drive</a>
                                    — é o que permite gravar os arquivos nas pastas do escritório.
                                </li>
                                <li>
                                    Na tela de consentimento, tipo <strong>Externo</strong>, coloque o e-mail do escritório como usuário de teste.
                                </li>
                                <li>
                                    Crie uma credencial do tipo <strong>Aplicativo da Web</strong> e cadastre os dois endereços abaixo.
                                </li>
                            </ol>

                            <div class="mb-4" x-data="{ copiado: false }">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Origem do sistema</label>
                                <div class="flex gap-2">
                                    <input type="text" readonly value="{{ $origemAplicativo }}"
                                           class="flex-1 border-gray-300 rounded-md bg-gray-50 text-sm"
                                           x-ref="origem">
                                    <button type="button"
                                            class="px-4 py-2 rounded-lg bg-gray-100 text-gray-800 text-sm font-semibold"
                                            @click="navigator.clipboard.writeText($refs.origem.value); copiado = true; setTimeout(() => copiado = false, 2000)">
                                        <span x-show="!copiado">Copiar</span>
                                        <span x-cloak x-show="copiado">Copiado</span>
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Cole em “Origens JavaScript autorizadas”.</p>
                            </div>

                            <div class="mb-6" x-data="{ copiado: false }">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Endereço de redirecionamento</label>
                                <div class="flex gap-2">
                                    <input type="text" readonly value="{{ $uriRedirecionamento }}"
                                           class="flex-1 border-gray-300 rounded-md bg-gray-50 text-sm"
                                           x-ref="uri">
                                    <button type="button"
                                            class="px-4 py-2 rounded-lg bg-gray-100 text-gray-800 text-sm font-semibold"
                                            @click="navigator.clipboard.writeText($refs.uri.value); copiado = true; setTimeout(() => copiado = false, 2000)">
                                        <span x-show="!copiado">Copiar</span>
                                        <span x-cloak x-show="copiado">Copiado</span>
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Cole em “URIs de redirecionamento autorizados”, exatamente assim. Se a credencial já for do n8n, mantenha o endereço antigo e acrescente este.</p>
                            </div>

                            <form wire:submit.prevent="salvarAplicativo" class="space-y-4" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">ID do cliente</label>
                                    <input type="text"
                                           wire:model="googleClientId"
                                           name="google_oauth_client_id"
                                           autocomplete="off"
                                           autocorrect="off"
                                           autocapitalize="off"
                                           spellcheck="false"
                                           data-lpignore="true"
                                           data-1p-ignore="true"
                                           data-form-type="other"
                                           class="mt-1 w-full border-gray-300 rounded-md"
                                           placeholder="xxxx.apps.googleusercontent.com">
                                    @error('googleClientId') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">
                                        Chave secreta
                                        @if ($googleConfigurado)
                                            <span class="font-normal text-gray-500">(deixe em branco para manter a atual)</span>
                                        @endif
                                    </label>
                                    <input type="text"
                                           wire:model="googleClientSecret"
                                           name="google_oauth_client_secret"
                                           autocomplete="off"
                                           autocorrect="off"
                                           autocapitalize="off"
                                           spellcheck="false"
                                           data-lpignore="true"
                                           data-1p-ignore="true"
                                           data-form-type="other"
                                           class="mt-1 w-full border-gray-300 rounded-md"
                                           style="-webkit-text-security: disc; text-security: disc;">
                                    @error('googleClientSecret') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                                </div>
                                <button type="submit"
                                        class="w-full h-14 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold"
                                        wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="salvarAplicativo">Salvar e continuar</span>
                                    <span wire:loading wire:target="salvarAplicativo">Salvando...</span>
                                </button>
                                @if ($googleConfigurado)
                                    <button type="button" wire:click="$set('editandoApp', false)"
                                            class="w-full text-sm text-gray-600">
                                        Cancelar
                                    </button>
                                @endif
                            </form>
                        </div>
                    @elseif ($passo === 2)
                        <div class="max-w-2xl" wire:key="wizard-drive-2">
                            <h2 class="text-xl font-semibold text-gray-900">2. Conectar a conta do escritório</h2>
                            <p class="mt-1 mb-5 text-sm text-gray-500">
                                O Google pede para escolher a conta. Use a mesma que já guarda as pastas dos clientes — não a conta pessoal do Chrome, se for outra.
                            </p>
                            <a href="{{ route('oauth.google.redirect') }}"
                               class="inline-flex w-full h-14 items-center justify-center rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold">
                                Conectar conta Google
                            </a>
                            <button type="button" wire:click="editarAplicativo" class="mt-4 w-full text-sm text-gray-600">
                                Alterar aplicativo Google
                            </button>
                        </div>
                    @else
                        <div class="mb-6 border rounded-xl p-4" wire:key="wizard-drive-3">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs uppercase tracking-wide text-gray-500">Conta conectada</p>
                                    <p class="font-semibold text-gray-900">{{ $conta->google_email }}</p>
                                    <p class="text-sm text-gray-500">{{ $conta->status->rotulo() }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('oauth.google.redirect') }}" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-800 text-sm font-semibold">Reconectar</a>
                                    <button type="button" wire:click="desconectar" class="px-4 py-2 rounded-lg bg-gray-600 text-white text-sm font-semibold">Desconectar</button>
                                    <button type="button" wire:click="editarAplicativo" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-800 text-sm font-semibold">Alterar aplicativo</button>
                                </div>
                            </div>
                        </div>

                        <h2 class="text-xl font-semibold text-gray-900 mb-1">3. Pasta de cada empresa</h2>
                        <p class="text-sm text-gray-500 mb-4">
                            Só as empresas com grupo monitorado. Escolha a pasta raiz no Drive; o sistema cria as pastas do ano e do tipo de documento.
                        </p>

                        <div class="flex justify-end mb-3">
                            <button type="button" wire:click="reprocessarPendentes" class="text-sm text-indigo-600 font-semibold">
                                Reprocessar documentos pendentes
                            </button>
                        </div>

                        <div class="overflow-x-auto border rounded-lg">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="text-left px-3 py-2">Empresa</th>
                                        <th class="text-left px-3 py-2">Pasta raiz</th>
                                        <th class="text-left px-3 py-2">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($empresas as $empresa)
                                        @php $raiz = $raizes->get($empresa->id); @endphp
                                        <tr class="border-t">
                                            <td class="px-3 py-2 font-medium">{{ $empresa->nome_fantasia ?: $empresa->nome }}</td>
                                            <td class="px-3 py-2">
                                                @if ($raiz)
                                                    <a href="{{ $raiz->urlDrive() }}" target="_blank" class="text-indigo-600">
                                                        {{ $raiz->google_folder_nome ?: $raiz->google_folder_id }}
                                                    </a>
                                                @else
                                                    <span class="text-gray-400">Escolha a pasta</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 space-x-2">
                                                <button type="button"
                                                        wire:click="abrirSeletor({{ $empresa->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="abrirSeletor,entrarPasta,voltarPasta,confirmarPasta,criarEstrutura,liberarLinks"
                                                        class="text-indigo-600 font-semibold disabled:opacity-50 disabled:pointer-events-none">
                                                    Escolher pasta
                                                </button>
                                                @if ($raiz)
                                                    <button type="button"
                                                            wire:click="criarEstrutura({{ $empresa->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="abrirSeletor,entrarPasta,voltarPasta,confirmarPasta,criarEstrutura,liberarLinks"
                                                            class="text-gray-700 disabled:opacity-50 disabled:pointer-events-none">
                                                        Criar pastas do ano
                                                    </button>
                                                    <button type="button"
                                                            wire:click="liberarLinks({{ $empresa->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="abrirSeletor,entrarPasta,voltarPasta,confirmarPasta,criarEstrutura,liberarLinks"
                                                            class="text-gray-700 disabled:opacity-50 disabled:pointer-events-none">
                                                        Liberar links
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">Nenhuma empresa com grupo monitorado. Ative o monitoramento em Documentos → Grupos.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>

    @if ($seletorAberto)
        <div class="fixed inset-0 bg-black/40 z-40 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[80vh] overflow-hidden">
                <div class="p-4 border-b flex justify-between items-center">
                    <div>
                        <h2 class="font-semibold text-gray-900">
                            {{ $seletorPasso === 'nome' ? 'Nome da pasta' : 'Onde criar a pasta' }}
                        </h2>
                        <p class="text-xs text-gray-500">{{ $empresaSeletorNome }}{{ $seletorPasso === 'local' ? ' · '.$pastaPaiNome : '' }}</p>
                    </div>
                    <button type="button" wire:click="fecharSeletor" class="text-gray-500"
                            wire:loading.attr="disabled"
                            wire:target="abrirSeletor,confirmarNomePasta,entrarPasta,voltarPasta,confirmarPasta,criarEVincular">Fechar</button>
                </div>

                @if ($seletorPasso === 'nome')
                    <form wire:submit.prevent="confirmarNomePasta" class="p-4 space-y-4">
                        <p class="text-sm text-gray-600">
                            Código do sistema + razão social, sem Ltda/Cia Ltda. Você pode ajustar antes de vincular.
                        </p>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nome da pasta no Drive</label>
                            <input type="text" wire:model="pastaNome"
                                   class="mt-1 w-full border-gray-300 rounded-md"
                                   maxlength="255">
                            @error('pastaNome') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <button type="submit"
                                class="w-full h-14 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold"
                                wire:loading.attr="disabled" wire:target="confirmarNomePasta">
                            Continuar e escolher o local
                        </button>
                    </form>
                @else
                    <div class="p-4 border-b bg-gray-50">
                        <p class="text-sm text-gray-700">
                            Vai criar: <span class="font-semibold">{{ $pastaNome }}</span>
                        </p>
                        <button type="button" wire:click="voltarParaNomePasta" class="mt-1 text-xs text-indigo-600">Alterar nome</button>
                    </div>
                    <div class="p-4 space-y-2 overflow-y-auto max-h-[45vh]">
                        @if ($pastaPaiId || count($breadcrumb))
                            <button type="button" wire:click="voltarPasta" class="text-sm text-indigo-600">Voltar</button>
                        @endif
                        @forelse ($pastas as $pasta)
                            <div class="flex items-center justify-between gap-2 border rounded-lg px-3 py-2">
                                <button type="button" wire:click="entrarPasta('{{ $pasta['id'] }}', @js($pasta['nome']))"
                                        wire:loading.attr="disabled"
                                        wire:target="entrarPasta,voltarPasta,confirmarPasta,criarEVincular"
                                        class="text-left font-medium text-gray-800 disabled:opacity-50">
                                    {{ $pasta['nome'] }}
                                </button>
                                <button type="button" wire:click="confirmarPasta('{{ $pasta['id'] }}', @js($pasta['nome']))"
                                        wire:loading.attr="disabled"
                                        wire:target="entrarPasta,voltarPasta,confirmarPasta,criarEVincular"
                                        class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-700 disabled:opacity-50">
                                    Usar existente
                                </button>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Nenhuma pasta aqui.</p>
                        @endforelse
                    </div>
                    <div class="p-4 border-t">
                        <button type="button" wire:click="criarEVincular"
                                wire:loading.attr="disabled"
                                wire:target="entrarPasta,voltarPasta,confirmarPasta,criarEVincular"
                                class="w-full h-14 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold disabled:opacity-50">
                            Criar e vincular aqui
                        </button>
                        <p class="mt-2 text-xs text-center text-gray-500">A pasta será criada dentro de {{ $pastaPaiNome }}.</p>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div wire:loading
         wire:target="abrirSeletor,confirmarNomePasta,entrarPasta,voltarPasta,confirmarPasta,criarEVincular,criarEstrutura,liberarLinks"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6 text-center">
            <div class="mx-auto mb-4 h-10 w-10 animate-spin rounded-full border-b-2 border-indigo-600"></div>
            <p class="font-semibold text-gray-900">Consultando o Google Drive</p>
            <p class="mt-1 text-sm text-gray-500">Aguarde, isso pode levar alguns segundos.</p>
        </div>
    </div>
</div>
