<?php

namespace App\Livewire;

use App\Models\CertificadoDigital;
use App\Models\EmpresasOperadora;
use App\Rules\CnpjValido;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Auth;

class EmpresasOperadorasForm extends Component
{
    use WithFileUploads;

    public $empresas;
    public $empresa_id;
    public $razao_social;
    public $nome_fantasia;
    public $cnpj;
    public $inscricao_estadual;
    public $telefone;
    public $email;
    public $responsavel;
    public $logo;
    public $logo_atual;
    public $configuracoes;
    public $plano = 'basico';
    public $limite_empresas;
    public $limite_usuarios;
    public $subdominio;
    public $modoEdicao = false;

    /** Upload A1 do escritório (contador) — só em edição. */
    public $certificadoArquivo = null;
    public string $certificadoNome = '';
    public string $certificadoSenha = '';

    protected function rules()
    {
        return [
            'razao_social' => 'required|string|max:255',
            'nome_fantasia' => 'nullable|string|max:255',
            'cnpj' => [
                'required',
                'string',
                'max:18',
                new CnpjValido(),
                Rule::unique('empresas_operadoras', 'cnpj')->ignore($this->empresa_id),
            ],
            'inscricao_estadual' => 'nullable|string|max:255',
            'telefone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'responsavel' => 'nullable|string|max:255',
            'logo' => 'nullable|image|max:2048',
            'configuracoes' => 'nullable',
            'plano' => 'required|in:basico,profissional,enterprise',
            'limite_empresas' => 'nullable|integer|min:1',
            'limite_usuarios' => 'nullable|integer|min:1',
            'subdominio' => [
                'nullable',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('empresas_operadoras', 'subdominio')->ignore($this->empresa_id),
            ],
        ];
    }

    public function mount()
    {
        $user = Auth::user();
        if (!$user || !$user->isSuperAdmin()) {
            abort(403, 'Acesso não autorizado.');
        }
        $this->carregarEmpresas();
    }

    public function carregarEmpresas()
    {
        $this->empresas = EmpresasOperadora::orderBy('razao_social')->get();
    }

    public function resetarCampos()
    {
        $this->empresa_id = null;
        $this->razao_social = '';
        $this->nome_fantasia = '';
        $this->cnpj = '';
        $this->inscricao_estadual = '';
        $this->telefone = '';
        $this->email = '';
        $this->responsavel = '';
        $this->logo = null;
        $this->logo_atual = null;
        $this->configuracoes = null;
        $this->plano = 'basico';
        $this->limite_empresas = null;
        $this->limite_usuarios = null;
        $this->subdominio = null;
        $this->modoEdicao = false;
        $this->certificadoArquivo = null;
        $this->certificadoNome = '';
        $this->certificadoSenha = '';
        $this->resetValidation(['certificadoArquivo', 'certificadoNome', 'certificadoSenha']);
    }

    public function salvarEmpresa()
    {
        $dados = $this->validate();
        $dados['cnpj'] = CnpjValido::format($dados['cnpj']);
        if ($this->logo) {
            $dados['logo'] = $this->logo->store('logos', 'public');
        } elseif ($this->logo_atual) {
            $dados['logo'] = $this->logo_atual;
        }
        if ($this->empresa_id) {
            $empresa = EmpresasOperadora::find($this->empresa_id);
            $empresa->update($dados);
            session()->flash('message', 'Escritório atualizado.');
            $this->carregarEmpresas();
            // Mantém edição aberta para permitir upload do certificado A1.
            return;
        }

        EmpresasOperadora::create($dados);
        $this->resetarCampos();
        $this->carregarEmpresas();
        session()->flash('message', 'Escritório cadastrado. Edite-o para enviar o certificado A1 do contador.');
    }

    public function uploadCertificadoEscritorio(CertificadoDigitalService $service): void
    {
        if (!$this->modoEdicao || !$this->empresa_id) {
            $this->addError('certificadoArquivo', 'Salve e edite o escritório antes de enviar o certificado.');

            return;
        }

        $this->validate([
            'certificadoArquivo' => 'required|file|extensions:pfx,p12|max:5120',
            'certificadoNome' => 'required|string|min:3|max:255',
            'certificadoSenha' => 'required|string|max:255',
        ], [], [
            'certificadoArquivo' => 'arquivo do certificado',
            'certificadoNome' => 'nome do certificado',
            'certificadoSenha' => 'senha',
        ]);

        try {
            $service->armazenar(
                $this->certificadoArquivo,
                $this->certificadoSenha,
                $this->certificadoNome,
                (int) $this->empresa_id,
                null // escritório / contador
            );
        } catch (\Throwable $e) {
            $this->addError('certificadoArquivo', $e->getMessage());

            return;
        }

        $this->certificadoArquivo = null;
        $this->certificadoNome = '';
        $this->certificadoSenha = '';
        session()->flash('message', 'Certificado A1 do escritório armazenado. Nas integrações da empresa cliente, escolha-o para entrar como Empresa Contábil.');
    }

    public function desativarCertificadoEscritorio(int $id, CertificadoDigitalService $service): void
    {
        if (!$this->empresa_id) {
            return;
        }

        $cert = CertificadoDigital::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $this->empresa_id)
            ->whereNull('empresa_id')
            ->where('id', $id)
            ->firstOrFail();

        $service->desativar($cert);
        session()->flash('message', 'Certificado do escritório desativado.');
    }

    public function editarEmpresa($id)
    {
        $empresa = EmpresasOperadora::find($id);
        $this->empresa_id = $empresa->id;
        $this->razao_social = $empresa->razao_social;
        $this->nome_fantasia = $empresa->nome_fantasia;
        $this->cnpj = $empresa->cnpj;
        $this->inscricao_estadual = $empresa->inscricao_estadual;
        $this->telefone = $empresa->telefone;
        $this->email = $empresa->email;
        $this->responsavel = $empresa->responsavel;
        $this->logo_atual = $empresa->logo;
        $this->logo = null;
        $this->configuracoes = $empresa->configuracoes;
        $this->plano = $empresa->plano ?? 'basico';
        $this->limite_empresas = $empresa->limite_empresas;
        $this->limite_usuarios = $empresa->limite_usuarios;
        $this->subdominio = $empresa->subdominio;
        $this->modoEdicao = true;
    }

    public function excluirEmpresa($id)
    {
        $empresa = EmpresasOperadora::find($id);

        if (!$empresa) {
            return;
        }

        if ($empresa->users()->exists() || $empresa->empresas()->exists()) {
            session()->flash('error', 'Não é possível excluir escritório com usuários ou empresas vinculados.');
            return;
        }

        if ($empresa->logo) {
            Storage::disk('public')->delete($empresa->logo);
        }
        $empresa->delete();
        $this->resetarCampos();
        $this->carregarEmpresas();
        session()->flash('message', 'Escritório excluído com sucesso.');
    }

    public function render()
    {
        $certificadosEscritorio = collect();
        if ($this->modoEdicao && $this->empresa_id) {
            $certificadosEscritorio = CertificadoDigital::withoutGlobalScope('operadora')
                ->where('empresa_operadora_id', $this->empresa_id)
                ->whereNull('empresa_id')
                ->where('ativo', true)
                ->orderBy('nome')
                ->get();
        }

        return view('livewire.empresas-operadoras-form', [
            'certificadosEscritorio' => $certificadosEscritorio,
        ]);
    }
}
