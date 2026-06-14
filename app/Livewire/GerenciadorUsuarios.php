<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\OperadoraContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
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
            'role' => 'required|in:admin,gerente,operador',
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

    public function salvarUsuario()
    {
        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior para gerenciar usuários.');
            return;
        }

        $dados = $this->validate();
        
        try {
            if ($this->usuario_id) {
                $usuario = User::doEscritorio()->findOrFail($this->usuario_id);
                $usuario->name = $this->name;
                $usuario->email = $this->email;
                $usuario->role = $this->role;
                if ($this->password) {
                    $usuario->password = Hash::make($this->password);
                }
                $usuario->save();
                session()->flash('message', 'Usuário atualizado com sucesso!');
            } else {
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
        $usuario = User::doEscritorio()->findOrFail($id);
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
            $usuario = User::doEscritorio()->findOrFail($id);
            
            if ($usuario->id === Auth::id()) {
                session()->flash('error', 'Você não pode excluir seu próprio usuário!');
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
        return view('livewire.gerenciador-usuarios', [
            'precisaSelecionarEscritorio' => OperadoraContext::superAdminPrecisaSelecionarEscritorio(),
        ]);
    }
}
