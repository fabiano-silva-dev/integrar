<?php

namespace App\Services\Documentos;

use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\GrupoWhatsapp;
use App\Models\Empresa;
use App\Rules\CnpjValido;
use Illuminate\Support\Collection;

class ResolverEmpresaDocumentoService
{
    /**
     * @param  Collection<int, Empresa>|iterable<Empresa>  $candidatas
     */
    public function resolver(DocumentoRecebido $documento, string $conteudo, iterable $candidatas): ?Empresa
    {
        $candidatas = collect($candidatas)->filter();

        if ($candidatas->count() === 1) {
            return $candidatas->first();
        }

        if ($candidatas->isEmpty()) {
            return null;
        }

        $cnpjsDocumento = $this->cnpjsDoDocumento($documento, $conteudo);

        $encontradas = $candidatas->filter(function (Empresa $empresa) use ($cnpjsDocumento) {
            $digits = CnpjValido::digits($empresa->cnpj);

            return $digits !== '' && in_array($digits, $cnpjsDocumento, true);
        });

        if ($encontradas->count() === 1) {
            return $encontradas->first();
        }

        $peloId = $this->escolherPorId($documento, $candidatas);

        if ($peloId !== null) {
            return $peloId;
        }

        return $this->escolherPorNome($documento, $candidatas);
    }

    /**
     * @return Collection<int, Empresa>
     */
    public function candidatasDoGrupo(?GrupoWhatsapp $grupo): Collection
    {
        if ($grupo === null) {
            return collect();
        }

        $ids = $grupo->idsEmpresas();

        if ($ids === []) {
            return collect();
        }

        return Empresa::withoutGlobalScope('operadora')
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * @return list<string>
     */
    public function cnpjsDoDocumento(DocumentoRecebido $documento, string $conteudo): array
    {
        $brutos = [];
        $meta = is_array($documento->metadados) ? $documento->metadados : [];

        foreach (['empresa_cnpj', 'cnpj_emitente', 'cnpj_destinatario', 'terceiro_cnpj'] as $chave) {
            if (! empty($meta[$chave]) && is_string($meta[$chave])) {
                $brutos[] = $meta[$chave];
            }
        }

        $chaveAcesso = preg_replace('/\D/', '', (string) ($meta['chave_acesso'] ?? '')) ?? '';

        if (strlen($chaveAcesso) === 44) {
            $brutos[] = substr($chaveAcesso, 6, 14);
        }

        $brutos = array_merge($brutos, $this->cnpjsDoXml($conteudo));

        $digits = [];
        foreach ($brutos as $valor) {
            $limpo = CnpjValido::digits((string) $valor);
            if (strlen($limpo) === 14) {
                $digits[] = $limpo;
            }
        }

        return array_values(array_unique($digits));
    }

    /**
     * @return list<string>
     */
    private function cnpjsDoXml(string $conteudo): array
    {
        $inicio = ltrim($conteudo);

        if ($inicio === '' || ($inicio[0] !== '<' && ! str_starts_with($inicio, '<?xml'))) {
            return [];
        }

        if (preg_match_all('/<cnpj>\s*(\d{14})\s*<\/cnpj>/i', $conteudo, $encontrados) === 0) {
            return [];
        }

        return array_values(array_unique($encontrados[1]));
    }

    /**
     * @param  Collection<int, Empresa>  $candidatas
     */
    private function escolherPorId(DocumentoRecebido $documento, Collection $candidatas): ?Empresa
    {
        $meta = is_array($documento->metadados) ? $documento->metadados : [];
        $id = $meta['empresa_id'] ?? null;

        if (! is_numeric($id)) {
            return null;
        }

        return $candidatas->firstWhere('id', (int) $id);
    }

    /**
     * @param  Collection<int, Empresa>  $candidatas
     */
    private function escolherPorNome(DocumentoRecebido $documento, Collection $candidatas): ?Empresa
    {
        $meta = is_array($documento->metadados) ? $documento->metadados : [];
        $nomesDocumento = [];

        foreach (['empresa_razao_social', 'empresa_nome', 'razao_social'] as $chave) {
            if (! empty($meta[$chave]) && is_string($meta[$chave])) {
                $nomesDocumento[] = $meta[$chave];
            }
        }

        if ($nomesDocumento === []) {
            return null;
        }

        $nomer = new NomePastaDriveEmpresa;
        $vencedoras = [];

        foreach ($nomesDocumento as $nomeDoc) {
            $alvo = $this->normalizarNome($nomer->limparRazao($nomeDoc));

            if ($alvo === '' || strlen($alvo) < 6) {
                continue;
            }

            $melhor = null;
            $melhorPct = 0.0;
            $empates = 0;

            foreach ($candidatas as $empresa) {
                $pctEmpresa = 0.0;

                foreach ([$empresa->razao_social, $empresa->nome_fantasia, $empresa->nome, $nomer->sugerir($empresa)] as $nomeEmp) {
                    $norm = $this->normalizarNome($nomer->limparRazao((string) $nomeEmp));

                    if ($norm === '') {
                        continue;
                    }

                    if ($norm === $alvo) {
                        $pctEmpresa = 100.0;
                        break;
                    }

                    similar_text($alvo, $norm, $pct);
                    $pctEmpresa = max($pctEmpresa, (float) $pct);
                }

                if ($pctEmpresa < 82.0) {
                    continue;
                }

                if ($pctEmpresa > $melhorPct + 0.5) {
                    $melhor = $empresa;
                    $melhorPct = $pctEmpresa;
                    $empates = 1;
                } elseif (abs($pctEmpresa - $melhorPct) <= 0.5) {
                    $empates++;
                }
            }

            if ($melhor !== null && $empates === 1) {
                $vencedoras[$melhor->id] = $melhor;
            }
        }

        return count($vencedoras) === 1 ? reset($vencedoras) : null;
    }

    private function normalizarNome(string $nome): string
    {
        $nome = mb_strtolower(trim($nome));
        $semAcento = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
        $nome = is_string($semAcento) ? $semAcento : $nome;

        return preg_replace('/[^a-z0-9]+/', '', $nome) ?? $nome;
    }
}
