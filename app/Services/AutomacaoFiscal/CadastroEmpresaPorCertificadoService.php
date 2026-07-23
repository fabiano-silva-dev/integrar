<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\Empresa;
use App\Rules\CnpjValido;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CadastroEmpresaPorCertificadoService
{
    private CertificadoDigitalService $certificadoService;

    public function __construct(CertificadoDigitalService $certificadoService)
    {
        $this->certificadoService = $certificadoService;
    }

    /**
     * Cadastra/atualiza a empresa a partir do PFX e grava o certificado
     * com a mesma rotina de Configurações → Certificados (`armazenar`).
     *
     * @return array{
     *     empresa: Empresa,
     *     criado: bool,
     *     mensagem: string
     * }
     */
    public function cadastrar(
        UploadedFile $arquivo,
        int $operadoraId,
        string $senha,
        ?string $nome = null
    ): array {
        $nomeCertificado = trim((string) ($nome ?: pathinfo($arquivo->getClientOriginalName(), PATHINFO_FILENAME)));
        if ($nomeCertificado === '') {
            $nomeCertificado = 'Certificado A1';
        }

        return DB::transaction(function () use ($arquivo, $operadoraId, $senha, $nomeCertificado) {
            $certificado = $this->certificadoService->armazenar(
                $arquivo,
                $senha,
                $nomeCertificado,
                $operadoraId,
                null
            );

            $documento = preg_replace('/\D/', '', (string) ($certificado->documento_titular ?? ''));

            if (strlen($documento) !== 14 || ! CnpjValido::isValid($documento)) {
                throw new RuntimeException(
                    'Não foi possível obter um CNPJ válido do certificado. Use um certificado A1 de pessoa jurídica.'
                );
            }

            $cnpj = CnpjValido::format($documento);
            $titular = trim((string) ($certificado->titular ?? ''));
            $nomeEmpresa = $titular !== '' ? $titular : $nomeCertificado;

            $empresa = Empresa::query()
                ->where('empresa_operadora_id', $operadoraId)
                ->where('cnpj', $cnpj)
                ->first();

            $criado = false;

            if ($empresa) {
                $empresa->update([
                    'nome' => $empresa->nome ?: $nomeEmpresa,
                    'razao_social' => $empresa->razao_social ?: $nomeEmpresa,
                    'nome_fantasia' => $empresa->nome_fantasia ?: $nomeEmpresa,
                    'ativo' => true,
                ]);
            } else {
                $empresa = Empresa::create([
                    'empresa_operadora_id' => $operadoraId,
                    'nome' => $nomeEmpresa,
                    'razao_social' => $nomeEmpresa,
                    'nome_fantasia' => $nomeEmpresa,
                    'cnpj' => $cnpj,
                    'ativo' => true,
                ]);
                $criado = true;
            }

            $certificado->update([
                'empresa_id' => $empresa->id,
            ]);

            return [
                'empresa' => $empresa->fresh(),
                'criado' => $criado,
                'mensagem' => $criado
                    ? 'Empresa criada e certificado vinculado.'
                    : 'Empresa atualizada e certificado vinculado.',
            ];
        });
    }
}
