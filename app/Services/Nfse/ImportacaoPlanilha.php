<?php

namespace App\Services\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\Importacao;
use App\Models\Nfse\LayoutImportacao;
use App\Models\Nfse\Nota;
use App\Models\Nfse\Servico;
use App\Models\Nfse\Tomador;
use App\Rules\CnpjOuCpfValido;
use App\Services\OperadoraStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportacaoPlanilha
{
    public const CAMPOS_OPCIONAIS = [
        'coluna_nome' => 'nome', 'coluna_endereco' => 'endereco_integral',
        'coluna_cep' => 'cep', 'coluna_logradouro' => 'logradouro', 'coluna_numero' => 'numero',
        'coluna_bairro' => 'bairro', 'coluna_complemento' => 'complemento',
        'coluna_municipio' => 'codigo_municipio_ibge', 'coluna_uf' => 'uf',
        'coluna_email' => 'email', 'coluna_telefone' => 'telefone',
    ];

    public function detectar(int $empresaId, UploadedFile $arquivo): array
    {
        $this->empresaHabilitada($empresaId);
        Validator::make(['arquivo' => $arquivo], ['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx'])->validate();
        return $this->leitor->detectar($arquivo->getRealPath(), strtolower($arquivo->getClientOriginalExtension()));
    }

    public function __construct(private PreparacaoNfse $preparo, private LeitorPlanilha $leitor) {}

    public function ler(int $empresaId, UploadedFile $arquivo, array $config): array
    {
        $this->empresaHabilitada($empresaId);
        $validos = Validator::make($config, [
            'cabecalho' => 'required|integer|min:1|max:20',
            'aba' => 'required|integer|min:0|max:19',
            'delimitador' => 'required|in:ponto_virgula,virgula,tab',
        ])->validate();
        Validator::make(['arquivo' => $arquivo], ['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx'])->validate();
        $tipo = strtolower($arquivo->getClientOriginalExtension());
        return $this->leitor->ler($arquivo->getRealPath(), $tipo, (int) $validos['cabecalho'], match ($validos['delimitador']) {
            'virgula' => ',', 'tab' => "\t", default => ';',
        }, (int) $validos['aba']);
    }

    public function configuracao(int $empresaId, ?int $layoutId, array $config): array
    {
        $empresa = $this->empresaHabilitada($empresaId);
        if ($layoutId !== null) {
            return LayoutImportacao::daEmpresa($empresa)->findOrFail($layoutId)->configuracao;
        }
        return Validator::make($config, [
            'cabecalho' => 'required|integer|min:1|max:20', 'aba' => 'required|integer|min:0|max:19',
            'delimitador' => 'required|in:ponto_virgula,virgula,tab',
            'identificacao' => 'required|in:documento,codigo_externo',
            'formato_valor' => 'required|in:br,decimal',
            'coluna_tomador' => 'required|integer|min:0|max:39', 'coluna_valor' => 'required|integer|min:0|max:39',
            'coluna_servico' => 'nullable|integer|min:0|max:39', 'coluna_descricao' => 'nullable|integer|min:0|max:39',
            'servico_fixo' => 'nullable|string|max:50',
            'cadastrar_tomadores' => 'sometimes|boolean',
            ...array_fill_keys(array_keys(self::CAMPOS_OPCIONAIS), 'nullable|integer|min:0|max:39'),
        ])->validate();
    }

    public function previa(int $empresaId, UploadedFile $arquivo, string $competencia, ?int $layoutId, array $config): array
    {
        $empresa = $this->empresaHabilitada($empresaId);
        Validator::make(['competencia' => $competencia], ['competencia' => 'required|date_format:Y-m-d'])->validate();
        $config = $this->configuracao($empresaId, $layoutId, $config);
        $planilha = $this->ler($empresaId, $arquivo, $config);
        $layout = $layoutId ? LayoutImportacao::daEmpresa($empresa)->findOrFail($layoutId) : null;
        if ($layout && ($layout->assinatura !== $planilha['assinatura'] || ($config['tipo'] ?? '') !== strtolower($arquivo->getClientOriginalExtension()))) {
            $this->falhar('O arquivo mudou em relação ao layout salvo. Configure e salve uma nova versão.');
        }
        $config['tipo'] = strtolower($arquivo->getClientOriginalExtension());
        $campos = ['coluna_tomador', 'coluna_valor', 'coluna_servico', 'coluna_descricao', ...array_keys(self::CAMPOS_OPCIONAIS)];
        foreach ($campos as $campo) {
            if (isset($config[$campo]) && ! array_key_exists($config[$campo], $planilha['colunas'])) {
                $this->falhar('Uma coluna mapeada não existe no cabeçalho.');
            }
        }
        $usadas = array_values(array_filter(array_map(fn ($campo) => $config[$campo] ?? null, $campos), fn ($v) => $v !== null));
        if (count($usadas) !== count(array_unique($usadas)) || (isset($config['coluna_servico']) === filled($config['servico_fixo'] ?? null))) {
            $this->falhar('Mapeie colunas distintas e escolha uma coluna de serviço ou um serviço fixo, não ambos.');
        }
        $inicio = CarbonImmutable::parse($competencia)->startOfMonth()->toDateString();
        $fim = CarbonImmutable::parse($competencia)->endOfMonth()->toDateString();
        $hash = hash_file('sha256', $arquivo->getRealPath());
        if (Importacao::daEmpresa($empresa)->where('competencia', $inicio)->where('hash_arquivo', $hash)->exists()) {
            $this->falhar('Este arquivo já foi importado nesta competência. Consulte o histórico.');
        }
        $linhas = [];
        $vistos = [];
        $novosTomadores = [];
        foreach ($planilha['linhas'] as $numero => $celulas) {
            $linha = ['linha' => $numero, 'tomador' => $celulas[$config['coluna_tomador']], 'servico' => '', 'valor' => $celulas[$config['coluna_valor']], 'descricao' => '', 'dados' => null, 'erro' => null];
            $linha['cadastro'] = [];
            foreach (self::CAMPOS_OPCIONAIS as $campo => $atributo) {
                if (isset($config[$campo])) {
                    $linha['cadastro'][$atributo] = $celulas[$config[$campo]];
                }
            }
            $linha['formulas'] = count(array_intersect_key($planilha['formulas'][$numero] ?? [], array_flip($usadas)));
            if (array_intersect_key($planilha['invalidas'][$numero] ?? [], array_flip($usadas))) {
                $linha['erro'] = 'Célula mapeada com erro ou fórmula sem valor salvo. Recalcule e salve essa célula no Excel.';
            }
            $ident = $linha['tomador'];
            if ($config['identificacao'] === 'documento') {
                $ident = preg_replace('/[.\/\-\s]/', '', $ident);
                if (Validator::make(['documento' => $ident], ['documento' => ['required', 'regex:/^(\d{11}|\d{14})$/', new CnpjOuCpfValido]])->fails()) {
                    $linha['erro'] = 'CPF/CNPJ inválido. Não são completados zeros automaticamente.';
                }
            }
            $tomador = $ident !== '' && ! $linha['erro'] ? Tomador::daEmpresa($empresa)->where($config['identificacao'], $ident)->first() : null;
            $novo = null;
            if ($tomador && ! $tomador->ativo) {
                $linha['erro'] ??= 'Tomador cadastrado está inativo. Revise o cadastro.';
            }
            if (! $tomador && ! $linha['erro'] && ($config['cadastrar_tomadores'] ?? false) && $config['identificacao'] === 'documento') {
                $novo = $this->novoTomador($ident, $linha['cadastro']);
                if ($novo === null) {
                    $linha['erro'] = 'Dados insuficientes ou inválidos para cadastrar tomador. Confira nome e campos de endereço separados.';
                } elseif (isset($novosTomadores[$ident]) && $novosTomadores[$ident] !== $novo) {
                    $linha['erro'] = 'Há dados cadastrais diferentes para o mesmo documento nesta planilha.';
                }
            }
            $linha['novo_tomador'] = $novo;
            $codigoServico = isset($config['coluna_servico']) ? $celulas[$config['coluna_servico']] : $config['servico_fixo'];
            $servico = Servico::daEmpresa($empresa)->where('ativo', true)->where('codigo', $codigoServico)->first();
            $valor = $this->normalizarValor($linha['valor'], isset($planilha['numericas'][$numero][$config['coluna_valor']]) ? 'decimal' : $config['formato_valor']);
            $descricao = isset($config['coluna_descricao']) ? $celulas[$config['coluna_descricao']] : '';
            $descricao = $descricao === '' ? ($servico?->descricao ?? '') : $descricao;
            $linha['tomador'] = $tomador ? $tomador->nome.' · '.$tomador->documento : (($linha['cadastro']['nome'] ?? '') !== '' ? $linha['cadastro']['nome'].' · '.$linha['tomador'] : $linha['tomador']);
            $linha['servico'] = $servico?->nome ?? $codigoServico;
            $linha['descricao'] = $descricao;
            $linha['valor'] = $valor ?? $linha['valor'];
            $linha['erro'] ??= match (true) {
                ! $tomador && ! $novo => 'Tomador ativo não encontrado. Mapeie o nome e habilite o cadastro ou corrija o identificador.',
                ! $servico => 'Serviço ativo não encontrado pelo código interno.',
                $valor === null => 'Valor inválido para o formato escolhido.',
                $descricao === '' || mb_strlen($descricao) > 2000 => 'Descrição ausente ou acima de 2.000 caracteres.',
                default => null,
            };
            if (! $linha['erro']) {
                $dados = ['tomador_id' => $tomador?->id, 'servico_id' => $servico->id, 'competencia' => $competencia, 'descricao' => $descricao, 'valor' => $valor];
                $chave = hash('sha256', json_encode([$ident, $dados], JSON_THROW_ON_ERROR));
                if (isset($vistos[$chave]) || ($tomador && Nota::daEmpresa($empresa)->whereBetween('competencia', [$inicio, $fim])->where('tomador_id', $tomador->id)->where('servico_id', $servico->id)->where('valor', $valor)->where('descricao', $descricao)->where('status', '!=', 'descartada')->exists())) {
                    $linha['erro'] = 'Possível duplicidade na competência; confira a central antes de preparar outra nota.';
                } else {
                    $vistos[$chave] = true;
                    $linha['dados'] = $dados;
                    if ($novo) {
                        $novosTomadores[$ident] = $novo;
                    }
                }
            }
            $linhas[] = $linha;
        }
        $aptas = count(array_filter($linhas, fn ($l) => $l['dados'] !== null));
        $totalCentavos = 0;
        foreach ($linhas as $linha) {
            if ($linha['dados']) {
                $totalCentavos += (int) str_replace('.', '', $linha['dados']['valor']);
            }
        }
        $total = intdiv($totalCentavos, 100).'.'.str_pad((string) ($totalCentavos % 100), 2, '0', STR_PAD_LEFT);
        $resultado = ['competencia' => $competencia, 'hash' => $hash, 'assinatura' => $planilha['assinatura'], 'config' => $config, 'linhas' => $linhas, 'aptas' => $aptas, 'pendentes' => count($linhas) - $aptas, 'total' => $total, 'ignoradas' => $planilha['ignoradas'], 'novos_tomadores' => count($novosTomadores)];
        $resultado['conferencia'] = hash('sha256', json_encode($resultado, JSON_THROW_ON_ERROR));
        return $resultado;
    }

    public function importar(int $empresaId, UploadedFile $arquivo, string $competencia, ?int $layoutId, array $config, string $nome, string $conferencia): Importacao
    {
        $empresa = $this->empresaHabilitada($empresaId);
        $path = null;
        try {
            return DB::transaction(function () use ($empresa, $arquivo, $competencia, $layoutId, $config, $nome, $conferencia, &$path) {
                Empresa::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)->where('ativo', true)->lockForUpdate()->findOrFail($empresa->id);
                $previa = $this->previa($empresa->id, $arquivo, $competencia, $layoutId, $config);
                if (! hash_equals($previa['conferencia'], $conferencia) || $previa['aptas'] === 0) {
                    $this->falhar('A conferência mudou ou não há linhas aptas. Revise novamente antes de confirmar.');
                }
                if ($layoutId) {
                    $layout = LayoutImportacao::daEmpresa($empresa)->findOrFail($layoutId);
                } else {
                    $nome = trim($nome);
                    Validator::make(['nomeLayout' => $nome], ['nomeLayout' => 'required|string|max:120'])->validate();
                    $versao = (int) LayoutImportacao::daEmpresa($empresa)->where('nome', $nome)->max('versao') + 1;
                    $layout = new LayoutImportacao(['nome' => $nome, 'versao' => $versao, 'assinatura' => $previa['assinatura'], 'configuracao' => $previa['config']]);
                    $layout->empresa_id = $empresa->id;
                    $layout->empresa_operadora_id = $empresa->empresa_operadora_id;
                    $layout->save();
                }
                $path = OperadoraStorage::diskPath('nfse/importacoes/'.$empresa->id, $empresa->empresa_operadora_id).'/'.Str::uuid().'.'.$previa['config']['tipo'];
                $conteudo = file_get_contents($arquivo->getRealPath());
                if ($conteudo === false || hash('sha256', $conteudo) !== $previa['hash'] || ! Storage::disk('local')->put($path, $conteudo, ['visibility' => 'private'])) {
                    $this->falhar('Não foi possível preservar o arquivo de origem.');
                }
                $importacao = new Importacao([
                    'layout_id' => $layout->id, 'usuario_id' => auth()->id(), 'competencia' => CarbonImmutable::parse($competencia)->startOfMonth()->toDateString(),
                    'hash_arquivo' => $previa['hash'], 'arquivo_original' => mb_substr(basename($arquivo->getClientOriginalName()), 0, 255),
                    'storage_path' => $path, 'relatorio' => $previa['linhas'], 'quantidade_gerada' => $previa['aptas'], 'quantidade_pendente' => $previa['pendentes'],
                ]);
                $importacao->empresa_id = $empresa->id;
                $importacao->empresa_operadora_id = $empresa->empresa_operadora_id;
                $importacao->save();
                $criados = [];
                foreach ($previa['linhas'] as $linha) {
                    if (! $linha['dados']) {
                        continue;
                    }
                    $dados = $linha['dados'];
                    if ($linha['novo_tomador']) {
                        $novo = $linha['novo_tomador'];
                        $criados[$novo['documento']] ??= $this->preparo->salvarTomador($empresa->id, null, $novo)->id;
                        $dados['tomador_id'] = $criados[$novo['documento']];
                    }
                    $nota = $this->preparo->salvarRascunho($empresa->id, (string) Str::uuid(), $dados);
                    $nota->importacao_id = $importacao->id;
                    $nota->linha_importacao = $linha['linha'];
                    $nota->save();
                }
                return $importacao;
            });
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    private function novoTomador(string $documento, array $cadastro): ?array
    {
        // Endereço integral permanece no relatório. Não inventar município, CEP, número ou bairro.
        unset($cadastro['endereco_integral']);
        $cadastro = array_filter($cadastro, fn ($v) => $v !== '');
        if (isset($cadastro['cep'])) {
            $cadastro['cep'] = preg_replace('/[-\s]/', '', $cadastro['cep']);
        }
        if (isset($cadastro['uf'])) {
            $cadastro['uf'] = strtoupper($cadastro['uf']);
        }
        // O gerador exige endereço nacional completo quando qualquer campo fiscal de endereço existe.
        $endereco = array_intersect_key($cadastro, array_flip(['cep', 'logradouro', 'numero', 'bairro', 'codigo_municipio_ibge', 'complemento']));
        if ($endereco && count(array_intersect_key($endereco, array_flip(['cep', 'logradouro', 'numero', 'bairro', 'codigo_municipio_ibge']))) !== 5) {
            return null;
        }
        $v = Validator::make($cadastro, [
            'nome' => 'required|string|max:150', 'email' => 'nullable|email|max:255', 'telefone' => 'nullable|string|max:30',
            'cep' => 'nullable|digits:8', 'logradouro' => 'nullable|string|max:255', 'numero' => 'nullable|string|max:30',
            'bairro' => 'nullable|string|max:60', 'complemento' => 'nullable|string|max:156', 'codigo_municipio_ibge' => 'nullable|regex:/^[1-9]\d{6}$/D',
            'uf' => 'nullable|in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO',
        ]);
        return $v->fails() ? null : $v->validated() + ['documento' => $documento, 'ativo' => true];
    }

    public function normalizarValor(string $valor, string $formato): ?string
    {
        $valor = trim($valor);
        if ($formato === 'br') {
            if (! preg_match('/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?$/D', $valor)) {
                return null;
            }
            $valor = str_replace(',', '.', str_replace('.', '', $valor));
        } elseif ($formato !== 'decimal') {
            return null;
        }
        if (! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $valor)) {
            return null;
        }
        [$inteiro, $decimal] = array_pad(explode('.', $valor, 2), 2, '');
        $inteiro = ltrim($inteiro, '0') ?: '0';
        $decimal = str_pad($decimal, 2, '0');
        return $inteiro === '0' && $decimal === '00' ? null : $inteiro.'.'.$decimal;
    }

    private function empresaHabilitada(int $empresaId): Empresa
    {
        $empresa = $this->preparo->empresa($empresaId);
        if (! Configuracao::daEmpresa($empresa)->where('habilitado_preparo', true)->exists()) {
            $this->falhar('Habilite a preparação de NFS-e para esta empresa.');
        }
        return $empresa;
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['arquivo' => $mensagem]);
    }
}
