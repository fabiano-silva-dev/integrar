<div class="py-12">
    <div class="max-w-full mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-8 text-gray-900">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">👥 Gerenciador de Usuários</h2>
                    <div class="text-sm text-gray-500">
                        Total: {{ $usuarios->count() }} usuário(s)
                    </div>
                </div>

                <!-- Mensagens de Feedback -->
                @if (session()->has('message'))
                    <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded-md">
                        ✅ {{ session('message') }}
                    </div>
                @endif

                @if (session()->has('error'))
                    <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-md">
                        ❌ {{ session('error') }}
                    </div>
                @endif

                @if ($precisaSelecionarEscritorio)
                    <div class="mb-4 p-4 bg-amber-100 border border-amber-400 text-amber-800 rounded-md">
                        Selecione um escritório no menu superior para visualizar e gerenciar usuários.
                    </div>
                @endif

                <!-- Form -->
                <div class="bg-gray-50 p-8 rounded-lg mb-6 mx-4" @if($precisaSelecionarEscritorio) style="opacity:0.5;pointer-events:none" @endif>
                    <form wire:submit.prevent="salvarUsuario" autocomplete="off">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nome</label>
                                <input type="text" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                       placeholder="Nome completo" 
                                       wire:model="name" 
                                       autocomplete="off"
                                       required>
                                @error('name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
                                <input type="email" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                       placeholder="email@exemplo.com" 
                                       wire:model="email" 
                                       autocomplete="off"
                                       required>
                                @error('email') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Senha 
                                    @if($modoEdicao)
                                        <span class="text-xs text-gray-500">(deixe em branco para não alterar)</span>
                                    @endif
                                </label>
                                <input type="password" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                       placeholder="••••••••" 
                                       wire:model="password" 
                                       autocomplete="new-password"
                                       @if(!$modoEdicao) required @endif>
                                @error('password') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nível</label>
                                <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                        wire:model="role" 
                                        autocomplete="off"
                                        required>
                                    <option value="operador">👤 Operador</option>
                                    <option value="gerente">👨‍💼 Gerente</option>
                                    <option value="admin">👑 Administrador</option>
                                </select>
                                @error('role') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                            
                            <div class="flex items-end space-x-3">
                                <button type="submit" 
                                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-md transition duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    @if($modoEdicao)
                                        🔄 Atualizar
                                    @else
                                        ➕ Cadastrar
                                    @endif
                                </button>
                            </div>
                        </div>
                        
                        @if($modoEdicao)
                            <div class="mt-4 flex justify-end">
                                <button type="button" 
                                        class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-6 rounded-md transition duration-200 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" 
                                        wire:click="resetarCampos">
                                    ❌ Cancelar edição
                                </button>
                            </div>
                        @endif
                    </form>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white border border-gray-200 rounded-lg overflow-hidden">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">E-mail</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nível</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($usuarios as $usuario)
                                <tr class="hover:bg-gray-50 transition duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        #{{ $usuario->id }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $usuario->name }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $usuario->email }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($usuario->role === 'admin')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                👑 Admin
                                            </span>
                                        @elseif($usuario->role === 'gerente')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                👨‍💼 Gerente
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                👤 Operador
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button class="text-indigo-600 hover:text-indigo-900 bg-indigo-50 hover:bg-indigo-100 px-3 py-1 rounded-md transition duration-200" 
                                                    wire:click="editarUsuario({{ $usuario->id }})">
                                                ✏️ Editar
                                            </button>
                                            <button class="text-red-600 hover:text-red-900 bg-red-50 hover:bg-red-100 px-3 py-1 rounded-md transition duration-200" 
                                                    wire:click="excluirUsuario({{ $usuario->id }})" 
                                                    onclick="return confirm('Tem certeza que deseja excluir este usuário? Esta ação não pode ser desfeita.')">
                                                🗑️ Excluir
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($usuarios->isEmpty())
                    <div class="text-center py-8">
                        <div class="text-gray-400 text-6xl mb-4">👥</div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Nenhum usuário encontrado</h3>
                        <p class="text-gray-500">Comece cadastrando o primeiro usuário usando o formulário acima.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
