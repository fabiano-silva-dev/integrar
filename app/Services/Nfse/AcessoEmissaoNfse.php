<?php

namespace App\Services\Nfse;

use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\Nfse\AcessoEmissao;
use App\Models\EmpresasOperadora;
use App\Rules\CnpjOuCpfValido;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AcessoEmissaoNfse
{
    public const MODOS = ['certificado_proprio', 'certificado_representante', 'senha_propria', 'senha_representante'];

    public function __construct(private PreparacaoNfse $preparo) {}

    public function empresa(int $id): Empresa
    {
        abort_unless($this->preparo->podeConfigurar(), 403);
        $empresa = $this->preparo->empresa($id);
        abort_unless(EmpresasOperadora::query()->whereKey($empresa->empresa_operadora_id)->where('ativo', true)->exists(), 403);
        return $empresa;
    }

    public function certificados(Empresa $empresa, string $modo): Builder
    {
        // Não utiliza certificados de outro cliente nem troca automaticamente o titular.
        $empresa = $this->empresa($empresa->id);
        $q = CertificadoDigital::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)
            ->where('ativo', true)->where('tipo', 'A1')->where('valido_de', '<=', now())->where('valido_ate', '>', now());
        return match ($modo) {
            'certificado_proprio' => $q->where('empresa_id', $empresa->id),
            'certificado_representante' => $q->whereNull('empresa_id'),
            default => $q->whereRaw('1 = 0'),
        };
    }

    public function salvar(int $empresaId, #[\SensitiveParameter] array $dados): AcessoEmissao
    {
        $empresa = $this->empresa($empresaId);
        $v = Validator::make($dados, [
            'modo' => 'required|in:'.implode(',', self::MODOS),
            'canal' => 'required|in:api,portal',
            'certificado_digital_id' => 'nullable|integer|min:1',
            'login' => 'nullable|string|max:32',
            'senha' => 'nullable|string|max:256',
            'procuracao_ate' => 'nullable|date_format:Y-m-d|after_or_equal:today',
        ])->validate();
        $documento = preg_replace('/\D/', '', (string) $empresa->cnpj);
        Validator::make(['documento' => $documento], ['documento' => ['required', 'regex:/^(\d{11}|\d{14})$/D', new CnpjOuCpfValido]])->validate();
        $cert = str_starts_with($v['modo'], 'certificado_');
        $representante = str_ends_with($v['modo'], '_representante');
        if (! $cert && $v['canal'] !== 'portal') {
            $this->falhar('Usuário e senha são exclusivos do Emissor Web.');
        }
        return DB::transaction(function () use ($empresa, $v, $documento, $cert, $representante) {
            $empresa = Empresa::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)->where('ativo', true)->lockForUpdate()->findOrFail($empresa->id);
            if (preg_replace('/\D/', '', (string) $empresa->cnpj) !== $documento) {
                $this->falhar('O documento da empresa mudou. Atualize a tela.');
            }
            $r = AcessoEmissao::daEmpresa($empresa)->lockForUpdate()->first() ?? new AcessoEmissao;
            $mesmoModo = $r->exists && $r->modo === $v['modo'] && $r->documento_prestador === $documento;
            if ($cert) {
                $selecionado = $this->certificados($empresa, $v['modo'])->lockForUpdate()->find($v['certificado_digital_id'] ?? 0);
                if (! $selecionado) {
                    $this->falhar('Selecione um certificado A1 vigente do titular correto.');
                }
                if (! $representante && preg_replace('/\D/', '', (string) $selecionado->documento_titular) !== $documento) {
                    $this->falhar('O documento do certificado próprio deve coincidir com o prestador.');
                }
                $r->certificado_digital_id = $selecionado->id;
                $r->usuario_criptografado = null;
                $r->senha_criptografada = null;
            } else {
                $r->certificado_digital_id = null;
                $login = preg_replace('/[.\/\-\s]/', '', (string) ($v['login'] ?? ''));
                $senha = (string) ($v['senha'] ?? '');
                if ($login === '' && $senha === '' && $mesmoModo && $r->getRawOriginal('usuario_criptografado') && $r->getRawOriginal('senha_criptografada')) {
                    // Manter credenciais sem devolvê-las ao navegador.
                } else {
                    Validator::make(['login' => $login], ['login' => ['required', 'regex:/^(\d{11}|\d{14})$/D', new CnpjOuCpfValido]])->validate();
                    if ($senha === '' || (! $representante && $login !== $documento)) {
                        $this->falhar('Informe usuário e senha; no acesso próprio, o usuário deve ser o documento do prestador.');
                    }
                    $r->usuario_criptografado = $login;
                    $r->senha_criptografada = $senha;
                }
            }
            $r->fill(['modo' => $v['modo'], 'canal' => $v['canal'], 'documento_prestador' => $documento,
                'procuracao_ate' => $representante ? ($v['procuracao_ate'] ?? null) : null,
                'status_validacao' => 'pendente', 'usuario_id' => auth()->id()]);
            $r->empresa_id = $empresa->id;
            $r->empresa_operadora_id = $empresa->empresa_operadora_id;
            $r->save();
            return $r;
        });
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['acesso' => $mensagem]);
    }
}
