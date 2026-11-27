<?php

namespace App\Livewire\Nfse;

use App\Models\Nfse\Importacao;
use App\Models\Nfse\LayoutImportacao;
use App\Models\Nfse\Servico;
use App\Models\Nfse\Tomador;
use App\Services\Nfse\EnderecoImportacaoNfse;
use App\Services\Nfse\ImportacaoPlanilha;
use App\Services\Nfse\LeitorPlanilha;
use App\Services\Nfse\PreparacaoNfse;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class ImportarPlanilha extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $empresaId;

    public $arquivo;
    public string $competencia = '';
    public ?int $layoutId = null;
    public string $nomeLayout = '';
    public array $config = [
        'cabecalho' => 1, 'aba' => 0, 'delimitador' => 'ponto_virgula', 'identificacao' => 'documento',
        'formato_valor' => 'br', 'coluna_tomador' => null, 'coluna_valor' => null,
        'coluna_servico' => null, 'coluna_descricao' => null, 'servico_fixo' => null,
        'cadastrar_tomadores' => false, 'coluna_nome' => null, 'coluna_endereco' => null,
        'coluna_cep' => null, 'coluna_logradouro' => null, 'coluna_numero' => null, 'coluna_bairro' => null,
        'coluna_complemento' => null, 'coluna_municipio' => null, 'coluna_cidade' => null, 'coluna_uf' => null, 'coluna_email' => null, 'coluna_telefone' => null,
    ];

    #[Locked]
    public array $colunas = [];

    #[Locked]
    public array $amostra = [];

    #[Locked]
    public string $leitura = '';

    #[Locked]
    public array $ignoradas = [];

    #[Locked]
    public array $abas = [];

    #[Locked]
    public string $tipoArquivo = '';

    #[Locked]
    public array $ajustes = [];

    #[Locked]
    public ?int $linhaEdicao = null;

    public array $edicao = [];

    public array $edicaoEndereco = [];

    public bool $confirmarEndereco = false;

    #[Locked]
    public array $enderecos = [];

    #[Locked]
    public array $enderecosProcessados = [];

    #[Locked]
    public int $uploadVersao = 0;

    #[Locked]
    public array $previa = [];

    #[Locked]
    public ?int $historicoId = null;

    public function mount(int $empresaId, ?string $mesInicial = null): void
    {
        $this->empresaId = app(PreparacaoNfse::class)->empresa($empresaId)->id;
        $this->competencia = $mesInicial && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $mesInicial) && $mesInicial !== now()->format('Y-m')
            ? $mesInicial.'-01' : now()->toDateString();
    }

    public function updated(string $nome): void
    {
        if (in_array($nome, ['nomeLayout', 'edicao', 'edicaoEndereco', 'confirmarEndereco'], true) || str_starts_with($nome, 'edicao.') || str_starts_with($nome, 'edicaoEndereco.')) {
            return;
        }
        $this->previa = [];
        if (in_array($nome, ['arquivo', 'layoutId'], true)) {
            $this->limparLeitura();
            if ($this->arquivo) {
                $this->carregarArquivo();
            }
            return;
        }
        if (in_array($nome, ['config.cabecalho', 'config.aba', 'config.delimitador'], true)) {
            $this->limparLeitura(false);
            if ($nome === 'config.aba' && $this->arquivo && ! $this->layoutId) {
                $this->detectarColunas((int) $this->config['aba']);
            }
        }
        if ($nome === 'config.coluna_servico' && filled($this->config['coluna_servico'])) {
            $this->config['servico_fixo'] = null;
        }
        if ($nome === 'config.servico_fixo' && filled($this->config['servico_fixo'])) {
            $this->config['coluna_servico'] = null;
        }
        $camposEndereco = ['coluna_endereco', 'coluna_cep', 'coluna_logradouro', 'coluna_numero', 'coluna_bairro', 'coluna_complemento', 'coluna_municipio', 'coluna_cidade', 'coluna_uf'];
        if (! $this->layoutId && $this->colunas && in_array($nome, array_map(fn ($c) => 'config.'.$c, $camposEndereco), true)) {
            $r = $this->planilhaOriginal();
            app(ImportacaoPlanilha::class)->aplicarAjustes($r, $this->ajustes);
            $this->reconhecerEnderecos($r);
        }
    }

    private function limparLeitura(bool $metadados = true): void
    {
        $this->colunas = $this->amostra = $this->ignoradas = $this->ajustes = $this->previa = [];
        $this->leitura = '';
        $this->enderecos = $this->enderecosProcessados = [];
        $this->fecharEdicao();
        if ($metadados) {
            $this->abas = [];
            $this->tipoArquivo = '';
        }
    }

    private function carregarArquivo(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        $this->resetValidation();
        $this->tipoArquivo = strtolower($this->arquivo->getClientOriginalExtension());
        $svc = app(ImportacaoPlanilha::class);
        $this->abas = $svc->abas($this->empresaId, $this->arquivo);
        if (! $this->layoutId) {
            $this->detectarColunas();
            return;
        }
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $layout = LayoutImportacao::daEmpresa($empresa)->findOrFail($this->layoutId);
        $this->reset('config');
        $this->config = array_replace($this->config, $layout->configuracao);
        $r = $svc->ler($this->empresaId, $this->arquivo, $this->configNormalizada());
        if ($layout->assinatura !== $r['assinatura'] || ($this->config['tipo'] ?? '') !== $this->tipoArquivo) {
            $this->addError('arquivo', 'O arquivo não corresponde ao layout salvo. Escolha Novo layout para configurar.');
            return;
        }
        $this->exibirLeitura($r, false);
    }

    public function cancelar(): void
    {
        $this->reset('arquivo', 'layoutId', 'nomeLayout', 'config');
        $this->limparLeitura();
        $this->uploadVersao++;
        $this->resetValidation();
    }

    public function ajustarLayout(): void
    {
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        abort_unless($this->layoutId && $this->colunas, 422);
        $layout = LayoutImportacao::daEmpresa($empresa)->findOrFail($this->layoutId);
        $this->layoutId = null;
        $this->nomeLayout = $layout->nome;
        $this->previa = [];
        $this->resetValidation();
    }

    private function configNormalizada(): array
    {
        return array_map(fn ($v) => $v === '' ? null : $v, $this->config);
    }

    public function detectarColunas(?int $aba = null): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        $this->resetValidation();
        abort_if($this->layoutId, 422);
        $this->limparLeitura(false);
        $this->tipoArquivo = strtolower($this->arquivo->getClientOriginalExtension());
        try {
            $svc = app(ImportacaoPlanilha::class);
            $this->abas = $svc->abas($this->empresaId, $this->arquivo);
            $r = $svc->detectar($this->empresaId, $this->arquivo, $aba);
            $this->config['cabecalho'] = $r['cabecalho'];
            $this->config['aba'] = $r['aba'];
            $this->config['delimitador'] = match ($r['delimitador']) { ',' => 'virgula', "\t" => 'tab', default => 'ponto_virgula' };
            $this->exibirLeitura($r);
        } catch (ValidationException $e) {
            // A detecção automática é uma sugestão; a leitura manual continua disponível.
            $this->addError('arquivo', collect($e->errors())->flatten()->first() ?? 'Não foi possível detectar as colunas.');
        }
    }

    private function exibirLeitura(array $r, bool $sugerir = true): void
    {
        $this->colunas = $r['colunas'];
        $this->atualizarGrade($r);
        $this->ignoradas = $r['ignoradas'];
        $this->leitura = $r['nome_aba'].' — '.count($r['linhas']).' linhas de dados';
        if (! $sugerir) {
            $this->reconhecerEnderecos($r);
            return;
        }
        foreach (['coluna_tomador', 'coluna_valor', 'coluna_servico', 'coluna_descricao', ...array_keys(ImportacaoPlanilha::CAMPOS_OPCIONAIS)] as $campo) {
            $this->config[$campo] = null;
        }
        foreach (app(LeitorPlanilha::class)->sugerir($this->colunas) as $campo => $indice) {
            $this->config[$campo] = $indice;
        }
        $this->config['servico_fixo'] = null;
        if (isset($this->config['coluna_tomador'])) {
            $this->config['identificacao'] = 'documento';
        }
        $this->config['cadastrar_tomadores'] = false;
        if (! isset($this->config['coluna_servico'])) {
            $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
            $servicos = Servico::daEmpresa($empresa)->where('ativo', true)->limit(2)->pluck('codigo');
            if ($servicos->count() === 1) {
                $this->config['servico_fixo'] = $servicos->first();
            }
        }
        if ($this->nomeLayout === '') {
            $this->nomeLayout = 'Planilha de serviços';
        }
        $this->reconhecerEnderecos($r);
    }

    private function entradaEndereco(array $linha): array
    {
        $mapeado = [];
        foreach (ImportacaoPlanilha::CAMPOS_OPCIONAIS as $coluna => $campo) {
            if (in_array($campo, EnderecoImportacaoNfse::CAMPOS, true) && filled($this->config[$coluna] ?? null)) {
                $mapeado[$campo] = $linha[(int) $this->config[$coluna]] ?? '';
            }
        }
        $texto = filled($this->config['coluna_endereco'] ?? null) ? ($linha[(int) $this->config['coluna_endereco']] ?? '') : '';
        $campos = app(EnderecoImportacaoNfse::class)->local($texto, $mapeado);
        return ['texto' => $texto, 'campos' => $campos];
    }

    private function reconhecerEnderecos(array $r): void
    {
        $this->enderecos = $this->enderecosProcessados = [];
        foreach ($r['linhas'] as $numero => $linha) {
            $entrada = $this->entradaEndereco($linha);
            if (filled($entrada['texto']) || array_filter(array_intersect_key($entrada['campos'], array_flip(EnderecoImportacaoNfse::CAMPOS)), fn ($v) => filled($v))) {
                $this->enderecos[$numero] = $entrada['campos'];
            }
        }
        if ($this->enderecos) {
            $this->completarLoteEnderecos($r);
        }
    }

    public function completarEnderecos(): void
    {
        if ($this->linhaEdicao !== null || ! $this->colunas || ! $this->arquivo) {
            return;
        }
        $r = $this->planilhaOriginal();
        app(ImportacaoPlanilha::class)->aplicarAjustes($r, $this->ajustes);
        $this->completarLoteEnderecos($r);
    }

    public function tentarNovamenteEnderecos(): void
    {
        abort_unless($this->linhaEdicao === null, 422);
        foreach ($this->enderecos as $linha => $e) {
            if ($e['origem'] !== 'ajuste manual' && (filled($e['aviso']) || array_filter(EnderecoImportacaoNfse::OBRIGATORIOS, fn ($c) => blank($e[$c])))) {
                unset($this->enderecosProcessados[$linha]);
            }
        }
        $this->completarEnderecos();
    }

    private function completarLoteEnderecos(array $r): void
    {
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $lote = [];
        foreach ($this->enderecos as $numero => $e) {
            if (isset($this->enderecosProcessados[$numero]) || ! isset($r['linhas'][$numero])) {
                continue;
            }
            $entrada = $this->entradaEndereco($r['linhas'][$numero]);
            $entrada['campos'] = $e;
            $lote[$numero] = $entrada;
            if (count($lote) >= EnderecoImportacaoNfse::LOTE) {
                break;
            }
        }
        if (! $lote) {
            return;
        }
        $resultados = app(EnderecoImportacaoNfse::class)->completar((int) $empresa->empresa_operadora_id, $lote);
        foreach ($resultados as $numero => $e) {
            $this->enderecos[$numero] = $e;
            $this->enderecosProcessados[$numero] = true;
        }
        $this->previa = [];
    }

    private function atualizarGrade(array $planilha): void
    {
        // Todas as linhas ficam acessíveis. Textos longos têm versão integral no editor.
        $this->amostra = array_map(fn ($linha) => array_map(fn ($v) => \Illuminate\Support\Str::limit($v, 250), $linha), $planilha['linhas']);
    }

    private function planilhaOriginal(): array
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        abort_unless($this->colunas, 422);
        $svc = app(ImportacaoPlanilha::class);
        $config = $this->layoutId ? $svc->configuracao($this->empresaId, $this->layoutId, []) : $this->configNormalizada();
        $r = $svc->ler($this->empresaId, $this->arquivo, $config);
        abort_unless($r['colunas'] === $this->colunas, 422, 'A estrutura da planilha mudou. Leia novamente.');
        return $r;
    }

    public function editarLinha(int $linha): void
    {
        $r = $this->planilhaOriginal();
        app(ImportacaoPlanilha::class)->aplicarAjustes($r, $this->ajustes);
        abort_unless(isset($r['linhas'][$linha]), 404);
        $this->linhaEdicao = $linha;
        $this->confirmarEndereco = false;
        $this->edicao = $r['linhas'][$linha];
        $this->edicaoEndereco = array_intersect_key($this->enderecos[$linha] ?? $this->entradaEndereco($r['linhas'][$linha])['campos'], array_flip(EnderecoImportacaoNfse::CAMPOS));
    }

    public function consultarCepEdicao(): void
    {
        abort_unless($this->linhaEdicao !== null, 422);
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $cep = preg_replace('/[-\s]/', '', (string) ($this->edicaoEndereco['cep'] ?? ''));
        if (! preg_match('/^\d{8}$/D', $cep)) {
            $this->addError('endereco.cep', 'Informe um CEP com oito dígitos.');
            return;
        }
        $svc = app(EnderecoImportacaoNfse::class);
        $entrada = $svc->local('', ['cep' => $cep]);
        $r = $svc->completar((int) $empresa->empresa_operadora_id, [$this->linhaEdicao => ['texto' => '', 'campos' => $entrada]])[$this->linhaEdicao];
        if (blank($r['codigo_municipio_ibge'])) {
            $this->addError('endereco.cep', 'CEP não confirmado. Confira o número ou informe os dados manualmente.');
            return;
        }
        foreach (['cep', 'municipio', 'codigo_municipio_ibge', 'uf', 'logradouro', 'bairro'] as $campo) {
            if (filled($r[$campo])) {
                $this->edicaoEndereco[$campo] = $r[$campo];
            }
        }
        $this->confirmarEndereco = true;
        $this->resetValidation('endereco.cep');
    }

    public function salvarEdicao(): void
    {
        $r = $this->planilhaOriginal();
        $linha = $this->linhaEdicao;
        abort_unless($linha !== null && isset($r['linhas'][$linha]), 404);
        if (array_keys($this->edicao) !== array_keys($r['linhas'][$linha])) {
            $this->addError('edicao', 'As colunas da edição não correspondem à planilha.');
            return;
        }
        $antes = $r;
        app(ImportacaoPlanilha::class)->aplicarAjustes($antes, $this->ajustes);
        $anterior = $this->enderecos[$linha] ?? $this->entradaEndereco($antes['linhas'][$linha])['campos'];
        $campos = EnderecoImportacaoNfse::CAMPOS;
        $validos = validator(['endereco' => $this->edicaoEndereco], [
            'endereco' => 'required|array:'.implode(',', $campos),
            'endereco.*' => 'nullable|string|max:255',
            'endereco.cep' => ['nullable', 'regex:/^\d{5}-?\d{3}$/D'],
            'endereco.codigo_municipio_ibge' => 'nullable|regex:/^[1-9]\d{6}$/D',
            'endereco.uf' => 'nullable|in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO',
            'endereco.numero' => 'nullable|string|max:30',
            'endereco.complemento' => 'nullable|string|max:156',
            'endereco.bairro' => 'nullable|string|max:60',
        ])->validate()['endereco'];
        $manual = [];
        foreach ($validos as $campo => $valor) {
            $valor = trim((string) $valor);
            if ($campo === 'cep') {
                $valor = str_replace('-', '', $valor);
            }
            if ($valor !== ($anterior[$campo] ?? '')) {
                $manual[$campo] = $valor;
            }
        }
        $ajustes = $this->ajustes;
        $ajustes[$linha] = array_map(fn ($v) => $v === null ? '' : $v, $this->edicao);
        $historico = app(ImportacaoPlanilha::class)->aplicarAjustes($r, $ajustes);
        $this->ajustes = array_map(fn ($celulas) => array_map(fn ($c) => $c['ajustado'], $celulas), $historico);
        $this->atualizarGrade($r);
        $entradaAntiga = $this->entradaEndereco($antes['linhas'][$linha]);
        $entradaNova = $this->entradaEndereco($r['linhas'][$linha]);
        if ($entradaAntiga !== $entradaNova || $manual || $this->confirmarEndereco) {
            $e = $entradaAntiga !== $entradaNova ? $entradaNova['campos'] : $anterior;
            $e = array_replace($e, $manual);
            if ($manual || $this->confirmarEndereco) {
                $e['origem'] = 'ajuste manual';
                if ($this->confirmarEndereco) {
                    $e['aviso'] = '';
                } elseif (array_intersect_key($manual, array_flip(['cep', 'municipio', 'uf', 'codigo_municipio_ibge']))) {
                    $e['aviso'] = 'CEP ou município alterado. Consulte o CEP ou confirme os dados revisados.';
                }
                $this->enderecosProcessados[$linha] = true;
            } else {
                unset($this->enderecosProcessados[$linha]);
            }
            if (filled($entradaNova['texto']) || array_filter(array_intersect_key($e, array_flip($campos)), fn ($v) => filled($v))) {
                $this->enderecos[$linha] = $e;
            } else {
                unset($this->enderecos[$linha], $this->enderecosProcessados[$linha]);
            }
        }
        $this->previa = [];
        $this->fecharEdicao();
        $this->resetValidation();
    }

    public function desfazerLinha(int $linha): void
    {
        $r = $this->planilhaOriginal();
        abort_unless(isset($r['linhas'][$linha]), 404);
        unset($this->ajustes[$linha], $this->enderecos[$linha], $this->enderecosProcessados[$linha]);
        app(ImportacaoPlanilha::class)->aplicarAjustes($r, $this->ajustes);
        $entrada = $this->entradaEndereco($r['linhas'][$linha]);
        if (filled($entrada['texto']) || array_filter(array_intersect_key($entrada['campos'], array_flip(EnderecoImportacaoNfse::CAMPOS)), fn ($v) => filled($v))) {
            $this->enderecos[$linha] = $entrada['campos'];
        }
        $this->atualizarGrade($r);
        $this->previa = [];
        $this->fecharEdicao();
        $this->resetValidation();
    }

    public function fecharEdicao(): void
    {
        $this->linhaEdicao = null;
        $this->edicao = $this->edicaoEndereco = [];
        $this->confirmarEndereco = false;
    }

    public function lerCabecalho(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        abort_if($this->layoutId, 422);
        $this->limparLeitura(false);
        $this->resetValidation();
        $this->exibirLeitura(app(ImportacaoPlanilha::class)->ler($this->empresaId, $this->arquivo, $this->configNormalizada()));
    }

    public function conferir(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        abort_unless($this->colunas && $this->linhaEdicao === null, 422, 'Conclua a edição e leia a planilha.');
        if (count($this->enderecosProcessados) < count($this->enderecos)) {
            $this->addError('arquivo', 'Aguarde a conclusão do reconhecimento dos endereços.');
            return;
        }
        $this->previa = [];
        $resultado = app(ImportacaoPlanilha::class)->previa($this->empresaId, $this->arquivo, $this->competencia, $this->layoutId, $this->configNormalizada(), $this->ajustes, $this->enderecos);
        $this->previa = array_intersect_key($resultado, array_flip(['conferencia', 'aptas', 'pendentes', 'total', 'ignoradas', 'novos_tomadores']));
        $this->previa['linhas'] = array_map(function ($linha) {
            $visivel = array_intersect_key($linha, array_flip(['linha', 'tomador', 'servico', 'valor', 'descricao', 'erro', 'formulas']));
            foreach (['tomador', 'servico', 'valor', 'descricao'] as $campo) {
                $visivel[$campo] = \Illuminate\Support\Str::limit($visivel[$campo], $campo === 'descricao' ? 4000 : 300);
            }
            $visivel['ajustada'] = ! empty($linha['ajustes']) || ($linha['endereco_revisao']['origem'] ?? '') === 'ajuste manual';
            $visivel['cadastro_efetivo'] = $linha['cadastro_efetivo'];
            $visivel['novo_tomador'] = $linha['novo_tomador'] !== null && $linha['dados'] !== null;
            $visivel['endereco_integral'] = \Illuminate\Support\Str::limit($linha['cadastro']['endereco_integral'] ?? '', 500);
            return $visivel;
        }, $resultado['linhas']);
    }

    public function importar(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        if ($this->linhaEdicao !== null || ! isset($this->previa['conferencia'])) {
            $this->addError('arquivo', 'Faça a pré-conferência primeiro.');
            return;
        }
        $registro = app(ImportacaoPlanilha::class)->importar($this->empresaId, $this->arquivo, $this->competencia, $this->layoutId, $this->configNormalizada(), $this->nomeLayout, $this->previa['conferencia'], $this->ajustes, $this->enderecos);
        $this->reset('arquivo', 'nomeLayout');
        $this->limparLeitura();
        $this->uploadVersao++;
        $this->layoutId = $registro->layout_id;
        $this->historicoId = $registro->id;
        session()->flash('nfse_importacao_mensagem', "{$registro->quantidade_gerada} rascunhos gerados. Nenhuma nota transmitida.");
    }

    public function abrirHistorico(int $id): void
    {
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $this->historicoId = Importacao::daEmpresa($empresa)->findOrFail($id)->id;
    }

    public function fecharHistorico(): void
    {
        $this->historicoId = null;
    }

    private function gradeFiscal($empresa, $servicos): array
    {
        $grade = [];
        $identificacao = $this->config['identificacao'] ?? 'documento';
        $identificacao = $identificacao === 'codigo_externo' ? 'codigo_externo' : 'documento';
        $valor = fn ($linha, $campo) => filled($this->config[$campo] ?? null) ? ($linha[(int) $this->config[$campo]] ?? '') : '';
        $ids = [];
        foreach ($this->amostra as $numero => $linha) {
            $id = $valor($linha, 'coluna_tomador');
            $ids[$numero] = $identificacao === 'documento' ? preg_replace('/[.\/\-\s]/', '', $id) : $id;
        }
        $tomadores = Tomador::daEmpresa($empresa)->whereIn($identificacao, array_filter($ids))->get()->keyBy($identificacao);
        $servicos = $servicos->keyBy('codigo');
        foreach ($this->amostra as $numero => $linha) {
            $tomador = $tomadores->get($ids[$numero]);
            $codigo = $valor($linha, 'coluna_servico') ?: ($this->config['servico_fixo'] ?? '');
            $servico = $servicos->get($codigo);
            $endereco = $this->enderecos[$numero] ?? [];
            $cadastro = [];
            foreach (ImportacaoPlanilha::CAMPOS_OPCIONAIS as $campo => $atributo) {
                $cadastro[$atributo] = $valor($linha, $campo);
            }
            $cadastro = array_replace($cadastro, $endereco);
            if ($tomador) {
                $efetivo = $tomador->only(['nome', 'email', 'telefone', ...array_diff(EnderecoImportacaoNfse::CAMPOS, ['municipio'])]);
                // Município por extenso só pode aproveitar a consulta se o IBGE coincidir.
                $efetivo['municipio'] = ($endereco['codigo_municipio_ibge'] ?? '') === $tomador->codigo_municipio_ibge ? ($endereco['municipio'] ?? '') : '';
                $cadastro = array_replace($cadastro, $efetivo);
            }
            $grade[$numero] = $cadastro + [
                'documento' => $tomador?->documento ?? $valor($linha, 'coluna_tomador'),
                'existente' => $tomador !== null, 'ativo' => $tomador?->ativo,
                'servico' => $servico?->nome ?? $codigo,
                'descricao' => $valor($linha, 'coluna_descricao') ?: ($servico?->descricao ?? ''),
                'valor' => $valor($linha, 'coluna_valor'),
            ];
        }
        return $grade;
    }

    public function render()
    {
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $layouts = LayoutImportacao::daEmpresa($empresa)->orderBy('nome')->orderByDesc('versao')->get();
        $servicos = Servico::daEmpresa($empresa)->where('ativo', true)->orderBy('nome')->get();
        $historico = Importacao::daEmpresa($empresa)->latest('id')->limit(20)->get();
        $detalhe = $this->historicoId ? Importacao::daEmpresa($empresa)->findOrFail($this->historicoId) : null;
        $gradeFiscal = $this->gradeFiscal($empresa, $servicos);
        $ia = ['gemini' => false, 'groq' => false];
        if ($this->enderecos) {
            try {
                $ia = app(\App\Services\Documentos\CredenciaisIaDocumentoService::class)->status((int) $empresa->empresa_operadora_id);
            } catch (\Throwable) {
                // A consulta local não depende de credenciais de IA.
            }
        }
        return view('livewire.nfse.importar-planilha', compact('layouts', 'servicos', 'historico', 'detalhe', 'gradeFiscal', 'ia'));
    }
}
