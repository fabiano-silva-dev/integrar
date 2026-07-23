<div class="max-w-5xl mx-auto py-10">
    <div class="bg-white rounded-2xl shadow-xl p-10 border border-gray-200">
        <div class="flex items-center gap-3 mb-8">
            <h2 class="text-3xl font-extrabold text-blue-800">🏛️ Escritórios (Operadoras)</h2>
        </div>

        @if (session()->has('message'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('message') }}</div>
        @endif
        @if (session()->has('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>
        @endif

        <form wire:submit.prevent="salvarEmpresa" class="mb-12">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div>
                    <label class="block font-semibold mb-2 text-gray-700">Razão Social <span class="text-red-500">*</span></label>
                    <input type="text" wire:model.defer="razao_social" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm" required>
                    @error('razao_social') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block font-semibold mb-2 text-gray-700">Nome Fantasia</label>
                    <input type="text" wire:model.defer="nome_fantasia" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm">
                </div>
                <div x-data="{ cnpj: @entangle('cnpj') }">
                    <label class="block font-semibold mb-2 text-gray-700">CNPJ <span class="text-red-500">*</span></label>
                    <input type="text" x-mask="99.999.999/9999-99" x-model="cnpj" wire:model.defer="cnpj" maxlength="18" placeholder="00.000.000/0000-00 ou só números" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm" required>
                    @error('cnpj') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block font-semibold mb-2 text-gray-700">Inscrição Estadual</label>
                    <input type="text" wire:model.defer="inscricao_estadual" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm">
                </div>
                <div x-data="{ tel: @entangle('telefone') }">
                    <label class="block font-semibold mb-2 text-gray-700">Telefone</label>
                    <input type="text" x-mask="(99) 99999-9999" x-model="tel" wire:model.defer="telefone" maxlength="15" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm">
                </div>
                <div>
                    <label class="block font-semibold mb-2 text-gray-700">E-mail</label>
                    <input type="email" wire:model.defer="email" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm">
                    @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block font-semibold mb-2 text-gray-700">Responsável</label>
                    <input type="text" wire:model.defer="responsavel" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm">
                </div>
                <div>
                    <label class="block font-semibold mb-2 text-gray-700">Logotipo</label>
                    <input type="file" wire:model="logo" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm">
                    @if($logo)
                        <img src="{{ $logo->temporaryUrl() }}" class="h-12 mt-2 rounded shadow">
                    @elseif($logo_atual)
                        <img src="{{ Storage::url($logo_atual) }}" class="h-12 mt-2 rounded shadow">
                    @endif
                    @error('logo') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block font-semibold mb-2 text-gray-700">Plano</label>
                    <select wire:model.defer="plano" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm">
                        <option value="basico">Básico</option>
                        <option value="profissional">Profissional</option>
                        <option value="enterprise">Enterprise</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold mb-2 text-gray-700">Limite de empresas</label>
                    <input type="number" wire:model.defer="limite_empresas" min="1" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm" placeholder="Ilimitado">
                </div>
                <div>
                    <label class="block font-semibold mb-2 text-gray-700">Limite de usuários</label>
                    <input type="number" wire:model.defer="limite_usuarios" min="1" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm" placeholder="Ilimitado">
                </div>
                <div>
                    <label class="block font-semibold mb-2 text-gray-700">Subdomínio</label>
                    <input type="text" wire:model.defer="subdominio" class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-blue-400 focus:border-blue-500 shadow-sm" placeholder="ex: dalongaro">
                    @error('subdominio') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="mt-8 flex justify-end gap-2">
                <button type="submit" class="bg-blue-700 text-white px-8 py-2 rounded-lg font-semibold hover:bg-blue-800 shadow">{{ $modoEdicao ? 'Atualizar' : 'Cadastrar' }}</button>
                @if($modoEdicao)
                    <button type="button" wire:click="resetarCampos" class="bg-gray-400 text-white px-8 py-2 rounded-lg font-semibold hover:bg-gray-500">Cancelar</button>
                @endif
            </div>
        </form>

        @if($modoEdicao && $empresa_id)
            <div class="border-t border-gray-200 pt-8 mt-8 mb-8">
                <h3 class="text-xl font-bold text-gray-900 mb-1">Certificado A1 do escritório</h3>
                <p class="text-sm text-gray-600 mb-4">
                    Usado no e-CAC quando a integração da empresa cliente escolher este certificado:
                    o portal seleciona <strong>CNPJ (Empresa Contábil)</strong>.
                    O certificado A1 da própria empresa cliente usa <strong>CPF do Responsável</strong>.
                </p>

                <form wire:submit.prevent="uploadCertificadoEscritorio" class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl border rounded-xl p-4 bg-gray-50 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Nome</label>
                        <input type="text" wire:model="certificadoNome" class="mt-1 w-full rounded-lg border-gray-300" placeholder="Ex.: e-CNPJ Contabilidade">
                        @error('certificadoNome') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Arquivo PFX/P12</label>
                        <input type="file" wire:model="certificadoArquivo" accept=".pfx,.p12" class="mt-1 w-full text-sm">
                        @error('certificadoArquivo') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Senha</label>
                        <input type="password" wire:model="certificadoSenha" autocomplete="new-password" class="mt-1 w-full rounded-lg border-gray-300">
                        @error('certificadoSenha') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg">
                            Enviar certificado do escritório
                        </button>
                    </div>
                </form>

                <ul class="divide-y divide-gray-200 border rounded-lg max-w-3xl bg-white">
                    @forelse($certificadosEscritorio as $cert)
                        <li class="px-4 py-3 text-sm flex flex-wrap justify-between gap-2 items-center">
                            <div>
                                <span class="font-medium text-gray-900">{{ $cert->nome }}</span>
                                <span class="text-gray-500"> — {{ $cert->titular ?: 'sem titular' }}</span>
                                @if($cert->valido_ate)
                                    <span class="text-gray-400"> (até {{ $cert->valido_ate->format('d/m/Y') }})</span>
                                @endif
                            </div>
                            <button type="button"
                                    wire:click="desativarCertificadoEscritorio({{ $cert->id }})"
                                    wire:confirm="Desativar este certificado do escritório?"
                                    class="text-red-600 hover:underline text-xs font-semibold">
                                Desativar
                            </button>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-gray-400 text-sm">Nenhum certificado A1 do escritório cadastrado.</li>
                    @endforelse
                </ul>
            </div>
        @endif

        <div class="border-t border-gray-200 pt-8 mt-8">
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white rounded shadow text-sm">
                    <thead class="bg-blue-100 text-blue-900">
                        <tr>
                            <th class="px-4 py-3 font-bold">Logo</th>
                            <th class="px-4 py-3 font-bold">Razão Social</th>
                            <th class="px-4 py-3 font-bold">CNPJ</th>
                            <th class="px-4 py-3 font-bold">Telefone</th>
                            <th class="px-4 py-3 font-bold">E-mail</th>
                            <th class="px-4 py-3 font-bold">Plano</th>
                            <th class="px-4 py-3 font-bold">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($empresas as $empresa)
                            <tr class="border-b even:bg-gray-50 hover:bg-blue-50 transition-colors">
                                <td class="px-4 py-2">
                                    @if($empresa->logo)
                                        <img src="{{ Storage::url($empresa->logo) }}" class="h-8 rounded">
                                    @endif
                                </td>
                                <td class="px-4 py-2">{{ $empresa->razao_social }}</td>
                                <td class="px-4 py-2">{{ $empresa->cnpj }}</td>
                                <td class="px-4 py-2">{{ $empresa->telefone }}</td>
                                <td class="px-4 py-2">{{ $empresa->email }}</td>
                                <td class="px-4 py-2 capitalize">{{ $empresa->plano ?? 'basico' }}</td>
                                <td class="px-4 py-2 flex gap-2">
                                    <button wire:click="editarEmpresa({{ $empresa->id }})" class="text-blue-700 hover:underline font-semibold">Editar</button>
                                    <button wire:click="excluirEmpresa({{ $empresa->id }})" class="text-red-600 hover:underline font-semibold" onclick="return confirm('Tem certeza que deseja excluir?')">Excluir</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-gray-400 py-6">Nenhuma empresa cadastrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- Máscaras com Alpine.js -->
    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.plugin((Alpine) => {
            Alpine.directive('mask', (el, {expression}, {evaluate}) => {
                let mask = evaluate(expression);
                el.addEventListener('input', () => {
                    let v = el.value.replace(/\D/g, '');
                    let m = mask;
                    let i = 0;
                    el.value = m.replace(/9/g, _ => v[i++] || '');
                });
            });
        });
    });
    </script>
</div>
