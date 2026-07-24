<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\DocumentoFiscal;
use App\Models\DocumentoFiscalColeta;
use App\Models\Empresa;
use App\Models\PortalIntegracao;
use App\Models\PortalRecurso;
use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportadorExtratoNfseService
{
    public function __construct(private readonly ExtratoNfseParser $parser)
    {
    }

    /**
     * @return array{coleta: DocumentoFiscalColeta, resumo: array<string, mixed>, avisos: list<string>}
     */
    public function importarArquivo(
        Empresa $empresa,
        string $caminhoLocal,
        string $nomeArquivo,
        ?int $execucaoId = null,
        ?string $recursoCodigo = null
    ): array {
        $parsed = $this->parser->parseArquivo($caminhoLocal);
        $hashArquivo = hash_file('sha256', $caminhoLocal);

        $conteudo = file_get_contents($caminhoLocal);
        $relative = OperadoraStorage::put(
            'automacao-fiscal/artefatos/extratos',
            Str::uuid().'-'.basename($nomeArquivo),
            $conteudo === false ? '' : $conteudo,
            $empresa->empresa_operadora_id
        );

        return $this->persistir(
            $empresa,
            $parsed,
            $nomeArquivo,
            $relative,
            $hashArquivo,
            $execucaoId,
            $recursoCodigo
        );
    }

    /**
     * @param  array{documentos: list<array<string, mixed>>, resumo: array<string, mixed>, avisos: list<string>}  $parsed
     * @return array{coleta: DocumentoFiscalColeta, resumo: array<string, mixed>, avisos: list<string>}
     */
    public function persistir(
        Empresa $empresa,
        array $parsed,
        string $nomeArquivo,
        ?string $storagePath = null,
        ?string $hashArquivo = null,
        ?int $execucaoId = null,
        ?string $recursoCodigo = null
    ): array {
        $portal = PortalIntegracao::query()->where('codigo', 'nfse_nacional')->first();
        $codigoRecurso = $recursoCodigo ?: $this->inferirRecurso($parsed['documentos']);
        $recurso = $portal
            ? PortalRecurso::query()
                ->where('portal_integracao_id', $portal->id)
                ->where('codigo', $codigoRecurso)
                ->first()
            : null;

        return DB::transaction(function () use (
            $empresa,
            $parsed,
            $nomeArquivo,
            $storagePath,
            $hashArquivo,
            $execucaoId,
            $portal,
            $recurso
        ) {
            $novos = 0;
            $atualizados = 0;
            $ignorados = 0;
            $documentos = [];

            foreach ($parsed['documentos'] as $doc) {
                $doc = ExtratoNfseParser::completarComEmpresa($doc, $empresa->cnpj, $empresa->nome);
                // Identidade do documento: chave_acesso (tamanho variável por tipo/portal).
                $chave = AnaliseFiscalService::normalizarChaveAcesso($doc['chave_acesso'] ?? null);
                $doc['chave_acesso'] = $chave;
                $documentos[] = $doc;

                if ($chave === null) {
                    $ignorados++;
                    continue;
                }

                $hash = hash('sha256', json_encode([
                    $chave,
                    $doc['valor_total'],
                    $doc['situacao'],
                    $doc['entrada_saida'],
                ], JSON_THROW_ON_ERROR));

                $existente = DocumentoFiscal::query()
                    ->where('empresa_id', $empresa->id)
                    ->where('chave_acesso', $chave)
                    ->first();

                $payload = [
                    'empresa_operadora_id' => $empresa->empresa_operadora_id,
                    'empresa_id' => $empresa->id,
                    'portal_integracao_id' => $portal?->id,
                    'portal_recurso_id' => $recurso?->id,
                    'automacao_execucao_id' => $execucaoId,
                    'tipo_documento' => 'nfse',
                    'chave_acesso' => $chave,
                    'identificador_externo' => $doc['identificador_externo'] ?? $chave,
                    'numero' => $doc['numero'],
                    'serie' => $doc['serie'],
                    'modelo' => $doc['modelo'],
                    'data_emissao' => $doc['data_emissao'],
                    'data_entrada_saida' => $doc['data_entrada_saida'],
                    'competencia' => $doc['competencia'],
                    'cnpj_emitente' => $doc['cnpj_emitente'],
                    'nome_emitente' => $doc['nome_emitente'],
                    'ie_emitente' => $doc['ie_emitente'],
                    'uf_emitente' => $doc['uf_emitente'],
                    'cnpj_destinatario' => $doc['cnpj_destinatario'],
                    'nome_destinatario' => $doc['nome_destinatario'],
                    'ie_destinatario' => $doc['ie_destinatario'],
                    'uf_destinatario' => $doc['uf_destinatario'],
                    'valor_total' => $doc['valor_total'] ?? 0,
                    'valor_bc_icms' => $doc['valor_bc_icms'] ?? 0,
                    'valor_icms' => $doc['valor_icms'] ?? 0,
                    'valor_bc_icms_st' => $doc['valor_bc_icms_st'] ?? 0,
                    'valor_icms_st' => $doc['valor_icms_st'] ?? 0,
                    'cfop' => $doc['cfop'],
                    'situacao' => $doc['situacao'],
                    'entrada_saida' => $doc['entrada_saida'],
                    'dados_complementares' => $doc['dados_complementares'] ?? [],
                    'hash_registro' => $hash,
                    'origem' => 'nfse_nacional_extrato_txt',
                ];

                if (! $existente) {
                    DocumentoFiscal::create($payload);
                    $novos++;
                } elseif ($existente->hash_registro !== $hash) {
                    $existente->update($payload);
                    $atualizados++;
                } else {
                    // Mesmo conteúdo: ainda vincula à execução/coleta atual para a análise fiscal.
                    if ($execucaoId && (int) $existente->automacao_execucao_id !== (int) $execucaoId) {
                        $existente->update([
                            'automacao_execucao_id' => $execucaoId,
                            'portal_integracao_id' => $portal?->id ?? $existente->portal_integracao_id,
                            'portal_recurso_id' => $recurso?->id ?? $existente->portal_recurso_id,
                        ]);
                    }
                    $ignorados++;
                }
            }

            $resumo = $this->parser->montarResumo($documentos);
            $resumo['importacao'] = [
                'novos' => $novos,
                'atualizados' => $atualizados,
                'ignorados' => $ignorados,
            ];

            $coleta = DocumentoFiscalColeta::create([
                'empresa_operadora_id' => $empresa->empresa_operadora_id,
                'empresa_id' => $empresa->id,
                'automacao_execucao_id' => $execucaoId,
                'origem' => 'nfse_nacional_extrato_txt',
                'nome_arquivo' => $nomeArquivo,
                'storage_path' => $storagePath,
                'hash_arquivo' => $hashArquivo,
                'quantidade_documentos' => count($documentos),
                'quantidade_novos' => $novos,
                'quantidade_atualizados' => $atualizados,
                'quantidade_ignorados' => $ignorados,
                'periodo_inicio' => $resumo['periodo_inicio'] ?? null,
                'periodo_fim' => $resumo['periodo_fim'] ?? null,
                'resumo' => $resumo,
            ]);

            return [
                'coleta' => $coleta,
                'resumo' => $resumo,
                'avisos' => $parsed['avisos'],
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $documentos
     */
    private function inferirRecurso(array $documentos): string
    {
        $tipo = $documentos[0]['dados_complementares']['tipo_listagem'] ?? 'emitidas';

        return $tipo === 'recebidas' ? 'nfse_recebidas' : 'nfse_emitidas';
    }
}
