<?php

namespace App\Services\Nfse;

use App\Models\Empresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogoMunicipalNfse
{
    public function __construct(private PreparacaoNfse $preparo, private CatalogoServicosNfse $catalogo) {}

    public function consultar(int $empresaId, string $nacional): array
    {
        $empresa = $this->preparo->empresa($empresaId);
        if (! preg_match('/^\d{7}$/D', (string) $empresa->codigo_municipio_ibge) || ! preg_match('/^\d{6}$/D', $nacional)) {
            return [];
        }
        return $this->query($empresa)->where('nacional', $nacional)->orderBy('municipal')->get(['municipal', 'descricao', 'created_at'])->map(fn ($r) => (array) $r)->all();
    }

    public function importar(int $empresaId, string $csv): int
    {
        abort_unless($this->preparo->podeConfigurar(), 403);
        $empresa = $this->preparo->empresa($empresaId);
        if (! preg_match('/^\d{7}$/D', (string) $empresa->codigo_municipio_ibge)) {
            $this->falhar('Informe o município IBGE no cadastro da empresa.');
        }
        if ($csv === '' || strlen($csv) > 2097152 || ! mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            $this->falhar('Use a lista simples de serviços municipal em CSV UTF-8, até 2 MB.');
        }
        $dados = [];
        $lista = $this->catalogo->catalogos()['nacional'];
        foreach (preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $csv)) as $i => $linha) {
            if (trim($linha) === '') {
                continue;
            }
            // Exportação Lista Simples: XX.XX.XX.XXX|Descrição|Código original (quando informado).
            $partes = explode('|', $linha);
            $codigo = trim($partes[0]);
            if ($i === 0 && Str::contains(Str::lower(Str::ascii($codigo)), 'codigo')) {
                continue;
            }
            if (count($partes) < 2 || count($partes) > 3 || ! preg_match('/^(\d{2})\.(\d{2})\.(\d{2})\.(\d{3})$/D', $codigo, $m)) {
                $this->falhar('Formato de código municipal inválido na linha '.($i + 1).'.');
            }
            $nacional = $m[1].$m[2].$m[3];
            $municipal = $m[4];
            if ($municipal === '000') {
                continue; // Serviço nacional sem desdobramento municipal; não fabricar complemento.
            }
            $descricao = trim($partes[1]);
            if (! isset($lista[$nacional]) || $descricao === '' || mb_strlen($descricao) > 2000 || isset($dados[$nacional.$municipal])) {
                $this->falhar('Código não reconhecido, descrição inválida ou duplicidade na linha '.($i + 1).'.');
            }
            $dados[$nacional.$municipal] = ['nacional' => $nacional, 'municipal' => $municipal, 'descricao' => $descricao];
        }
        if (! $dados || count($dados) > 10000) {
            $this->falhar('A lista não contém desdobramentos municipais válidos.');
        }
        return DB::transaction(function () use ($empresa, $dados, $csv) {
            $atual = Empresa::where('empresa_operadora_id', $empresa->empresa_operadora_id)->lockForUpdate()->findOrFail($empresa->id);
            abort_unless($atual->codigo_municipio_ibge === $empresa->codigo_municipio_ibge, 422);
            // Serializa substituições do mesmo escritório, inclusive feitas a partir de empresas distintas.
            DB::table('empresas_operadoras')->where('id', $empresa->empresa_operadora_id)->lockForUpdate()->first();
            $this->query($empresa)->delete();
            foreach (array_chunk(array_values($dados), 200) as $lote) {
                DB::table('nfse_catalogo_municipal')->insert(array_map(fn ($d) => $d + [
                    'empresa_operadora_id' => $empresa->empresa_operadora_id, 'municipio_ibge' => $empresa->codigo_municipio_ibge,
                    'usuario_id' => auth()->id(), 'arquivo_sha256' => hash('sha256', $csv), 'created_at' => now(), 'updated_at' => now(),
                ], $lote));
            }
            return count($dados);
        });
    }

    private function query(Empresa $empresa)
    {
        return DB::table('nfse_catalogo_municipal')->where('empresa_operadora_id', $empresa->empresa_operadora_id)
            ->where('municipio_ibge', $empresa->codigo_municipio_ibge);
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['tabelaMunicipal' => $mensagem]);
    }
}
