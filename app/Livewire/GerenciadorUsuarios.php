<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\OperadoraContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;

class GerenciadorUsuarios extends Component
{
    public $usuarios;
    public $usuario_id;
    public $name;
    public $email;
    public $password;
    public $role = 'operador';
    public $modoEdicao = false;

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . ($this->usuario_id ?? 'NULL'),
            'password' => $this->usuario_id ? 'nullable|min:6' : 'required|min:6',
            'role' => ['required', Rule::in($this->niveisValidosNoFormulario())],
        ];
    }

    protected $messages = [
        'name.required' => 'O nome é obrigatório.',
        'name.max' => 'O nome não pode ter mais de 255 caracteres.',
        'email.required' => 'O e-mail é obrigatório.',
        'email.email' => 'Digite um e-mail válido.',
        'email.unique' => 'Este e-mail já está em uso.',
        'password.min' => 'A senha deve ter pelo menos 6 caracteres.',
        'role.required' => 'O nível de acesso é obrigatório.',
        'role.in' => 'Nível de acesso inválido.',
    ];

    public function mount()
    {
        $user = Auth::user();

        if (!$user || (!$user->isSuperAdmin() && !in_array($user->role, ['admin', 'gerente']))) {
            abort(403, 'Acesso não autorizado.');
        }

        $this->carregarUsuarios();
    }

    public function carregarUsuarios()
    {
        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            $this->usuarios = collect();

            return;
        }

        $this->usuarios = User::doEscritorio()
            ->where('role', '!=', 'super_admin')
            ->get();
    }

    public function resetarCampos()
    {
        $this->usuario_id = null;
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->role = 'operador';
        $this->modoEdicao = false;
    }

    public function selecionarNivel(string $role): void
    {
        if (! $this->podeSelecionarNivel($role)) {
            return;
        }

        $this->role = $role;
    }

    public function podeSelecionarNivel(string $role): bool
    {
        $ator = Auth::user();
        if (! $ator || ! $ator->podeAtribuirNivel($role)) {
            return false;
        }

        if ($this->usuario_id) {
            $alvo = User::doEscritorio()->find($this->usuario_id);
            if ($alvo && ! $ator->podeAlterarNivelDe($alvo)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    protected function niveisValidosNoFormulario(): array
    {
        $ator = Auth::user();
        if (! $ator) {
            return [];
        }

        if ($this->usuario_id) {
            $alvo = User::doEscritorio()->find($this->usuario_id);
            if ($alvo && ! $ator->podeAlterarNivelDe($alvo)) {
                return [$alvo->role];
            }
        }

        return $ator->niveisQuePodeAtribuir();
    }

    /**
     * Textos de acesso por nível para orientar o cadastro e a manutenção.
     *
     * @return array<string, array{rotulo: string, resumo: string, acessos: list<string>, restricoes: list<string>}>
     */
    public function niveisAcesso(): array
    {
        return [
            'operador' => [
                'rotulo' => 'Operador',
                'resumo' => 'Rotina operacional do dia a dia.',
                'acessos' => [
                    'Empresas e plano de contas',
                    'Importação e conversão de extratos',
                    'Lançamentos e ajustes em massa',
                    'Regras de amarração e terceiros',
                    'Painel e análises fiscais',
                    'Arquivos das empresas',
                    'Exportação contábil',
                ],
                'restricoes' => [
                    'Não cadastra usuários',
                    'Não altera configurações do escritório',
                    'Não dispara consultas fiscais',
                ],
            ],
            'gerente' => [
                'rotulo' => 'Gerente',
                'resumo' => 'Operação e gestão do escritório.',
                'acessos' => [
                    'Tudo o que o operador acessa',
                    'Cadastro e manutenção de usuários',
                    'Configurações da automação fiscal',
                    'WhatsApp e Google Drive',
                ],
                'restricoes' => [
                    'Não cadastra gerente nem administrador',
                    'Não altera o próprio nível',
                    'Não dispara consultas fiscais',
                    'Não gerencia históricos padrão por layout',
                ],
            ],
            'admin' => [
                'rotulo' => 'Administrador',
                'resumo' => 'Gestão completa do escritório.',
                'acessos' => [
                    'Tudo o que o gerente acessa',
                    'Executar consultas fiscais',
                    'Históricos padrão por layout',
                ],
                'restricoes' => [],
            ],
        ];
    }

    public function salvarUsuario()
    {
        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior para gerenciar usuários.');
            return;
        }

        $ator = Auth::user();
        if (! $ator) {
            abort(403, 'Acesso não autorizado.');
        }

        $dados = $this->validate();

        try {
            if ($this->usuario_id) {
                $usuario = User::doEscritorio()->findOrFail($this->usuario_id);

                if (! $ator->podeEditarUsuario($usuario)) {
                    session()->flash('error', 'Você não pode editar este usuário.');
                    return;
                }

                $usuario->name = $this->name;
                $usuario->email = $this->email;
                if ($ator->podeAlterarNivelDe($usuario)) {
                    if (! $ator->podeAtribuirNivel($this->role)) {
                        session()->flash('error', 'Você não pode atribuir este nível de acesso.');
                        return;
                    }
                    $usuario->role = $this->role;
                }
                if ($this->password) {
                    $usuario->password = Hash::make($this->password);
                }
                $usuario->save();
                session()->flash('message', 'Usuário atualizado com sucesso!');
            } else {
                if (! $ator->podeAtribuirNivel($this->role)) {
                    session()->flash('error', 'Você não pode cadastrar usuário com este nível.');
                    return;
                }

                $dados['password'] = Hash::make($this->password);
                User::create($dados);
                session()->flash('message', 'Usuário cadastrado com sucesso!');
            }

            $this->resetarCampos();
            $this->carregarUsuarios();
        } catch (\Exception $e) {
            session()->flash('error', 'Erro ao salvar usuário: ' . $e->getMessage());
        }
    }

    public function editarUsuario($id)
    {
        $ator = Auth::user();
        $usuario = User::doEscritorio()->findOrFail($id);

        if (! $ator || ! $ator->podeEditarUsuario($usuario)) {
            session()->flash('error', 'Você não pode editar este usuário.');
            return;
        }

        $this->usuario_id = $usuario->id;
        $this->name = $usuario->name;
        $this->email = $usuario->email;
        $this->role = $usuario->role;
        $this->password = '';
        $this->modoEdicao = true;
    }

    public function excluirUsuario($id)
    {
        try {
            $ator = Auth::user();
            $usuario = User::doEscritorio()->findOrFail($id);

            if (! $ator || ! $ator->podeExcluirUsuario($usuario)) {
                session()->flash('error', $usuario->id === Auth::id()
                    ? 'Você não pode excluir seu próprio usuário!'
                    : 'Você não pode excluir este usuário.');
                return;
            }

            $usuario->delete();
            session()->flash('message', 'Usuário excluído com sucesso!');
            $this->resetarCampos();
            $this->carregarUsuarios();
        } catch (\Exception $e) {
            session()->flash('error', 'Erro ao excluir usuário: ' . $e->getMessage());
        }
    }

    public function render()
    {
        $ator = Auth::user();
        $alvo = $this->usuario_id ? User::doEscritorio()->find($this->usuario_id) : null;
        $niveisAtribuiveis = $ator ? $ator->niveisQuePodeAtribuir() : [];
        $podeAlterarNivel = $alvo ? ($ator?->podeAlterarNivelDe($alvo) ?? false) : true;

        return view('livewire.gerenciador-usuarios', [
            'precisaSelecionarEscritorio' => OperadoraContext::superAdminPrecisaSelecionarEscritorio(),
            'niveisAcesso' => $this->niveisAcesso(),
            'niveisAtribuiveis' => $niveisAtribuiveis,
            'podeAlterarNivel' => $podeAlterarNivel,
        ]);
    }
}
