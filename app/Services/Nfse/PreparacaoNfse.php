<?php

namespace App\Services\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\Nota;
use App\Models\Nfse\Servico;
use App\Models\Nfse\Tomador;
use App\Rules\CnpjOuCpfValido;
use App\Rules\EmpresaDoEscritorio;
use App\Services\OperadoraContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PreparacaoNfse
{
    public function empresa(int $id): Empresa
    {
        abort_unless(auth()->user()?->podeAcessarSistema(), 403);
        $operadora = OperadoraContext::requireId();
        Validator::make(['empresa_id' => $id], ['empresa_id' => ['required', new EmpresaDoEscritorio()]])->validate();
        return Empresa::query()->where('empresa_operadora_id', $operadora)->where('ativo', true)->findOrFail($id);
    }

    public function podeConfigurar(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->podeAcessarSistema() && ($user->isEscritorioAdmin() || $user->isGerente());
    }

    public function salvarConfiguracao(int $empresaId, array $dados): Configuracao
    {
        abort_unless($this->podeConfigurar(), 403);
        $empresa = $this->empresa($empresaId);
        $validos = Validator::make($dados, [
            'habilitado_preparo' => 'required|boolean',
            'regime_tributario' => 'nullable|in:mei,simples_nacional,normal',
            'serie_dps' => 'nullable|regex:/^[0-9]{1,5}$/',
        ])->validate();
        return DB::transaction(function () use ($empresa, $validos) {
            $this->bloquearEmpresa($empresa);
            $registro = Configuracao::daEmpresa($empresa)->first() ?? new Configuracao;
            return $this->persistir($registro, $empresa, $validos);
        });
    }

    public function salvarTomador(int $empresaId, ?int $id, array $dados): Tomador
    {
        $empresa = $this->empresa($empresaId);
        $dados['documento'] = preg_replace('/[.\/\-\s]/', '', (string) ($dados['documento'] ?? ''));
        $codigoExterno = trim((string) ($dados['codigo_externo'] ?? ''));
        $dados['codigo_externo'] = $codigoExterno === '' ? null : $codigoExterno;
        $dados['cep'] = preg_replace('/[-\s]/', '', (string) ($dados['cep'] ?? '')) ?: null;
        $dados['uf'] = strtoupper(trim((string) ($dados['uf'] ?? ''))) ?: null;
        return DB::transaction(function () use ($empresa, $id, $dados) {
            $this->bloquearEmpresa($empresa);
            $registro = $id ? Tomador::daEmpresa($empresa)->findOrFail($id) : new Tomador;
            $validos = Validator::make($dados, [
                'documento' => ['required', 'regex:/^(\d{11}|\d{14})$/', new CnpjOuCpfValido(), $this->unico('nfse_tomadores', 'documento', $empresa, $id)],
                'nome' => 'required|string|max:255', 'email' => 'nullable|email|max:255', 'telefone' => 'nullable|string|max:30',
                'codigo_externo' => ['nullable', 'string', 'max:100', $this->unico('nfse_tomadores', 'codigo_externo', $empresa, $id)],
                'cep' => 'nullable|digits:8', 'logradouro' => 'nullable|string|max:255', 'numero' => 'nullable|string|max:30',
                'complemento' => 'nullable|string|max:255', 'bairro' => 'nullable|string|max:255',
                'codigo_municipio_ibge' => 'nullable|digits:7', 'uf' => 'nullable|in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO',
                'inscricao_municipal' => 'nullable|string|max:50', 'ativo' => 'required|boolean',
            ])->validate();
            return $this->persistir($registro, $empresa, $validos);
        });
    }

    public function salvarServico(int $empresaId, ?int $id, array $dados): Servico
    {
        $empresa = $this->empresa($empresaId);
        $dados['codigo'] = trim((string) ($dados['codigo'] ?? ''));
        return DB::transaction(function () use ($empresa, $id, $dados) {
            $this->bloquearEmpresa($empresa);
            $registro = $id ? Servico::daEmpresa($empresa)->findOrFail($id) : new Servico;
            $validos = Validator::make($dados, [
                'codigo' => ['required', 'string', 'max:50', $this->unico('nfse_servicos', 'codigo', $empresa, $id)],
                'nome' => 'required|string|max:255', 'descricao' => 'required|string|max:2000',
                'codigo_tributacao_nacional' => 'required|digits:6', 'codigo_tributacao_municipal' => 'nullable|string|max:30',
                'nbs' => 'nullable|digits:9', 'valor_padrao' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'], 'ativo' => 'required|boolean',
            ])->validate();
            return $this->persistir($registro, $empresa, $validos);
        });
    }

    public function salvarRascunho(int $empresaId, string $intencao, array $dados): Nota
    {
        $empresa = $this->empresa($empresaId);
        $validos = Validator::make(array_merge($dados, ['intencao' => $intencao]), [
            'intencao' => 'required|uuid', 'tomador_id' => 'required|integer', 'servico_id' => 'required|integer',
            'competencia' => 'required|date_format:Y-m-d', 'descricao' => 'required|string|max:2000',
            'valor' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'numeric', 'gt:0'],
        ])->validate();
        return DB::transaction(function () use ($empresa, $intencao, $validos) {
            $this->bloquearEmpresa($empresa);
            $empresa->refresh();
            if (! Configuracao::daEmpresa($empresa)->where('habilitado_preparo', true)->exists()) {
                throw ValidationException::withMessages(['nota.valor' => 'Habilite a preparação de NFS-e nas configurações da empresa.']);
            }
            $existente = Nota::daEmpresa($empresa)->where('intencao', $intencao)->first();
            if ($existente) {
                return $existente; // Uma intenção não pode criar duas notas.
            }
            $tomador = Tomador::daEmpresa($empresa)->where('ativo', true)->findOrFail($validos['tomador_id']);
            $servico = Servico::daEmpresa($empresa)->where('ativo', true)->findOrFail($validos['servico_id']);
            $registro = new Nota;
            return $this->persistir($registro, $empresa, array_merge($validos, [
                'intencao' => $intencao, 'usuario_id' => auth()->id(), 'status' => 'rascunho',
                'snapshot' => $this->snapshot($empresa, $tomador, $servico),
            ]));
        });
    }

    public function snapshot(Empresa $empresa, Tomador $tomador, Servico $servico): array
    {
        return [
            'prestador' => $empresa->only(['id', 'cnpj', 'razao_social', 'nome', 'inscricao_municipal', 'codigo_municipio_ibge']),
            'tomador' => $tomador->only($tomador->getFillable()),
            'servico' => $servico->only($servico->getFillable()),
        ];
    }

    private function bloquearEmpresa(Empresa $empresa): void
    {
        Empresa::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)
            ->where('ativo', true)->lockForUpdate()->findOrFail($empresa->id);
    }

    private function unico(string $tabela, string $campo, Empresa $empresa, ?int $id): mixed
    {
        $rule = Rule::unique($tabela, $campo)->where('empresa_operadora_id', $empresa->empresa_operadora_id)->where('empresa_id', $empresa->id);
        return $id ? $rule->ignore($id) : $rule;
    }

    private function persistir(Model $registro, Empresa $empresa, array $dados): Model
    {
        $registro->fill($dados);
        $registro->empresa_id = $empresa->id;
        $registro->empresa_operadora_id = $empresa->empresa_operadora_id;
        $registro->save();
        return $registro;
    }
}
