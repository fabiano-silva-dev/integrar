<?php

namespace App\Livewire\Documentos;

use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\EmpresaPastaDrive;
use App\Models\Empresa;
use App\Services\Documentos\CompactarDocumentosDriveService;
use App\Services\Documentos\MoverDocumentoDriveService;
use App\Services\OperadoraContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class ExploradorDocumentos extends Component
{
    protected $layout = 'components.layouts.app';

    public ?int $empresaId = null;

    public ?int $ano = null;

    public string $tipo = '';

    public string $busca = '';

    /** @var list<string> */
    public array $selecionados = [];

    public ?string $erro = null;

    public bool $modalMoverAberto = false;

    /** @var list<int> */
    public array $idsMover = [];

    public ?int $moverEmpresaId = null;

    public ?int $moverAno = null;

    public string $moverTipo = '';

    /** @var list<int> */
    public array $moverEmpresaIdsPermitidas = [];

    public bool $confirmandoExclusao = false;

    /** @var list<int> */
    public array $idsExcluir = [];

    protected $queryString = [
        'empresaId' => ['except' => null, 'as' => 'empresa'],
        'ano' => ['except' => null],
        'tipo' => ['except' => ''],
        'busca' => ['except' => ''],
    ];

    public function mount(): void
    {
        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        $sessionEmpresa = session('empresa_selecionada_id');
        if ($this->empresaId === null && is_numeric($sessionEmpresa)) {
            $empresa = Empresa::query()->find((int) $sessionEmpresa);
            if ($empresa !== null) {
                $this->empresaId = (int) $empresa->id;
            }
        }

        $this->garantirEmpresaDoEscritorio();
    }

    public function updatedEmpresaId($value = null): void
    {
        $this->empresaId = is_numeric($value) ? (int) $value : (is_numeric($this->empresaId) ? (int) $this->empresaId : null);
        $this->ano = null;
        $this->tipo = '';
        $this->busca = '';
        $this->selecionados = [];
        $this->erro = null;
        $this->garantirEmpresaDoEscritorio();
    }

    public function updatedAno(): void
    {
        $this->tipo = '';
        $this->selecionados = [];
        $this->erro = null;
    }

    public function updatedTipo(): void
    {
        $this->selecionados = [];
        $this->erro = null;
    }

    public function updatedBusca(): void
    {
        $this->selecionados = [];
    }

    public function abrirEmpresa(int $empresaId): void
    {
        $this->updatedEmpresaId($empresaId);
    }

    public function abrirAno(int $ano): void
    {
        $this->ano = $ano;
        $this->tipo = '';
        $this->selecionados = [];
        $this->erro = null;
    }

    public function abrirTipo(string $tipo): void
    {
        if (TipoDocumentoRecebido::tryFrom($tipo) === null) {
            return;
        }

        $this->tipo = $tipo;
        $this->selecionados = [];
        $this->erro = null;
    }

    public function irPara(string $nivel): void
    {
        $this->selecionados = [];
        $this->erro = null;

        if ($nivel === 'raiz') {
            $this->empresaId = null;
            $this->ano = null;
            $this->tipo = '';
            $this->busca = '';

            return;
        }

        if ($nivel === 'empresa') {
            $this->ano = null;
            $this->tipo = '';

            return;
        }

        if ($nivel === 'ano') {
            $this->tipo = '';
        }
    }

    public function toggleSelecao(string $chave): void
    {
        if (in_array($chave, $this->selecionados, true)) {
            $this->selecionados = array_values(array_filter(
                $this->selecionados,
                fn (string $item) => $item !== $chave,
            ));

            return;
        }

        $this->selecionados[] = $chave;
    }

    public function selecionarTodos(): void
    {
        $todas = $this->chavesDoNivelAtual();
        $this->selecionados = ($todas !== [] && count($this->selecionados) === count($todas))
            ? []
            : $todas;
    }

    public function baixarSelecionados(CompactarDocumentosDriveService $compactar)
    {
        return $this->baixarChaves($compactar, $this->selecionados);
    }

    public function baixarItem(string $chave, CompactarDocumentosDriveService $compactar)
    {
        return $this->baixarChaves($compactar, [$chave]);
    }

    public function abrirMoverItem(string $chave): void
    {
        $this->abrirMoverChaves([$chave]);
    }

    public function abrirMoverSelecionados(): void
    {
        $this->abrirMoverChaves($this->selecionados);
    }

    public function fecharMover(): void
    {
        $this->modalMoverAberto = false;
        $this->idsMover = [];
        $this->moverEmpresaId = null;
        $this->moverAno = null;
        $this->moverTipo = '';
        $this->moverEmpresaIdsPermitidas = [];
    }

    public function moverAbrirEmpresa(int $empresaId): void
    {
        if ($this->moverEmpresaIdsPermitidas !== [] && ! in_array($empresaId, $this->moverEmpresaIdsPermitidas, true)) {
            return;
        }

        $this->moverEmpresaId = $empresaId;
        $this->moverAno = null;
        $this->moverTipo = '';
    }

    public function moverAbrirAno(int $ano): void
    {
        $this->moverAno = $ano;
        $this->moverTipo = '';
    }

    public function moverAbrirTipo(string $tipo): void
    {
        if (TipoDocumentoRecebido::tryFrom($tipo) === null) {
            return;
        }

        $this->moverTipo = $tipo;
    }

    public function moverIrPara(string $nivel): void
    {
        if ($nivel === 'empresas') {
            $this->moverEmpresaId = null;
            $this->moverAno = null;
            $this->moverTipo = '';

            return;
        }

        if ($nivel === 'empresa') {
            $this->moverAno = null;
            $this->moverTipo = '';

            return;
        }

        if ($nivel === 'ano') {
            $this->moverTipo = '';
        }
    }

    public function confirmarMover(MoverDocumentoDriveService $mover): void
    {
        $this->erro = null;
        $this->garantirEmpresaDoEscritorio();

        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        if ($this->moverEmpresaId === null || $this->moverAno === null || $this->moverTipo === '') {
            $this->erro = 'Escolha a pasta de destino.';

            return;
        }

        if ($this->destinoMoverIgualOrigem()) {
            return;
        }

        $tipo = TipoDocumentoRecebido::tryFrom($this->moverTipo);

        if ($tipo === null) {
            $this->erro = 'Pasta de destino inválida.';

            return;
        }

        try {
            foreach ($this->idsMover as $id) {
                $documento = DocumentoRecebido::query()
                    ->where('status', StatusDocumentoRecebido::EnviadoDrive)
                    ->find($id);

                if ($documento === null) {
                    continue;
                }

                $mover->mover($documento, (int) $this->moverEmpresaId, $tipo, (int) $this->moverAno);
            }

            $this->fecharMover();
            $this->selecionados = [];
        } catch (\Throwable $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function pedirExcluirItem(string $chave): void
    {
        $this->pedirExcluirChaves([$chave]);
    }

    public function pedirExcluirSelecionados(): void
    {
        $this->pedirExcluirChaves($this->selecionados);
    }

    public function cancelarExclusao(): void
    {
        $this->confirmandoExclusao = false;
        $this->idsExcluir = [];
    }

    public function confirmarExclusao(MoverDocumentoDriveService $mover): void
    {
        $this->erro = null;
        $this->garantirEmpresaDoEscritorio();

        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        try {
            foreach ($this->idsExcluir as $id) {
                $documento = DocumentoRecebido::query()
                    ->where('status', StatusDocumentoRecebido::EnviadoDrive)
                    ->find($id);

                if ($documento === null) {
                    continue;
                }

                $mover->excluir($documento);
            }

            $this->cancelarExclusao();
            $this->selecionados = [];
        } catch (\Throwable $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function baixarPastaAtual(CompactarDocumentosDriveService $compactar)
    {
        if ($this->empresaId === null) {
            $this->erro = 'Selecione uma empresa para baixar.';

            return null;
        }

        if ($this->tipo !== '') {
            return $this->baixarChaves($compactar, ['tipo:'.$this->tipo]);
        }

        if ($this->ano !== null) {
            return $this->baixarChaves($compactar, ['ano:'.$this->ano]);
        }

        return $this->baixarChaves($compactar, ['empresa:'.$this->empresaId]);
    }

    /**
     * @param  list<string>  $chaves
     */
    private function baixarChaves(CompactarDocumentosDriveService $compactar, array $chaves)
    {
        $this->erro = null;
        $this->garantirEmpresaDoEscritorio();

        if ($this->precisaSelecionarEscritorio()) {
            return null;
        }

        $ids = $this->idsArquivosDasChaves($chaves);

        if ($ids === []) {
            $this->erro = 'Nenhum arquivo no Drive para baixar nesta seleção.';

            return null;
        }

        try {
            return $compactar->baixar($ids, $this->nomeArquivoZip($chaves));
        } catch (\Throwable $exception) {
            $this->erro = $exception->getMessage();

            return null;
        }
    }

    public function render()
    {
        $precisaSelecionarEscritorio = $this->precisaSelecionarEscritorio();
        $empresas = collect();
        $empresaAtual = null;
        $itens = collect();
        $breadcrumb = [];
        $pastaDriveUrl = null;
        $nivel = 'empresas';

        if (! $precisaSelecionarEscritorio && OperadoraContext::id()) {
            $empresas = $this->empresasDoEscritorio();
            $empresaAtual = $this->empresaId ? $empresas->firstWhere('id', $this->empresaId) : null;

            if ($empresaAtual === null && $this->empresaId !== null) {
                $this->empresaId = null;
                $this->ano = null;
                $this->tipo = '';
            }

            [$itens, $nivel, $breadcrumb, $pastaDriveUrl] = $this->montarConteudo($empresas, $empresaAtual);
        }

        $chavesVisiveis = $itens->pluck('chave')->filter()->values()->all();
        $moverItens = collect();
        $moverBreadcrumb = [];
        $moverNivel = 'empresas';
        $moverPodeConfirmar = false;
        $moverDestinoIgual = false;

        if ($this->modalMoverAberto && ! $precisaSelecionarEscritorio) {
            [$moverItens, $moverNivel, $moverBreadcrumb, $moverPodeConfirmar, $moverDestinoIgual] = $this->montarModalMover($empresas);
        }

        return view('livewire.documentos.explorador-documentos', [
            'precisaSelecionarEscritorio' => $precisaSelecionarEscritorio,
            'empresas' => $empresas,
            'empresaAtual' => $empresaAtual,
            'itens' => $itens,
            'nivel' => $nivel,
            'breadcrumb' => $breadcrumb,
            'breadcrumbIrmaos' => $this->irmaosDoNivel($empresas, $empresaAtual, $nivel),
            'pastaDriveUrl' => $pastaDriveUrl,
            'chavesVisiveis' => $chavesVisiveis,
            'todosSelecionados' => $chavesVisiveis !== [] && count($this->selecionados) === count($chavesVisiveis),
            'podeConfigurar' => $this->podeConfigurar(),
            'moverItens' => $moverItens,
            'moverBreadcrumb' => $moverBreadcrumb,
            'moverNivel' => $moverNivel,
            'moverPodeConfirmar' => $moverPodeConfirmar,
            'moverDestinoIgual' => $moverDestinoIgual,
        ]);
    }

    /**
     * @param  Collection<int, Empresa>  $empresas
     * @return array{0: Collection<int, array<string, mixed>>, 1: string, 2: list<array{label: string, nivel: string}>, 3: ?string}
     */
    private function montarConteudo(Collection $empresas, ?Empresa $empresaAtual): array
    {
        if ($empresaAtual === null) {
            $busca = mb_strtolower(trim($this->busca));
            $filtradas = $busca === ''
                ? $empresas
                : $empresas->filter(function (Empresa $empresa) use ($busca) {
                    $nome = mb_strtolower((string) ($empresa->nome_fantasia ?: $empresa->nome));

                    return str_contains($nome, $busca)
                        || str_contains(mb_strtolower((string) $empresa->cnpj), $busca)
                        || str_contains(mb_strtolower((string) $empresa->codigo_sistema), $busca);
                });

            $itens = $filtradas->map(function (Empresa $empresa) {
                $raiz = $empresa->pastasDrive->firstWhere('tipo', EmpresaPastaDrive::TIPO_RAIZ);

                return [
                    'chave' => 'empresa:'.$empresa->id,
                    'tipo' => 'empresa',
                    'nome' => $empresa->nome_fantasia ?: $empresa->nome,
                    'subtitulo' => $empresa->codigo_sistema ?: $empresa->cnpj,
                    'url_drive' => $raiz?->urlDrive(),
                    'abrir' => 'empresa',
                    'id' => $empresa->id,
                ];
            })->values();

            return [$itens, 'empresas', [['label' => 'Empresas', 'nivel' => 'raiz']], null];
        }

        $nomeEmpresa = $empresaAtual->nome_fantasia ?: $empresaAtual->nome;
        $raiz = $empresaAtual->pastasDrive->firstWhere('tipo', EmpresaPastaDrive::TIPO_RAIZ);
        $breadcrumb = [
            ['label' => 'Empresas', 'nivel' => 'raiz'],
            ['label' => $nomeEmpresa, 'nivel' => 'empresa'],
        ];

        if ($this->ano === null) {
            $itens = $this->itensAno($empresaAtual);

            return [$itens, 'anos', $breadcrumb, $raiz?->urlDrive()];
        }

        $breadcrumb[] = ['label' => (string) $this->ano, 'nivel' => 'ano'];
        $pastaAno = $empresaAtual->pastasDrive
            ->first(fn (EmpresaPastaDrive $pasta) => $pasta->ano === $this->ano && $pasta->ehAno());

        if ($this->tipo === '') {
            $itens = $this->itensTipo($empresaAtual, $this->ano);

            return [$itens, 'tipos', $breadcrumb, $pastaAno?->urlDrive() ?? $raiz?->urlDrive()];
        }

        $tipoEnum = TipoDocumentoRecebido::tryFrom($this->tipo);
        $breadcrumb[] = ['label' => $tipoEnum?->rotulo() ?? $this->tipo, 'nivel' => 'tipo'];
        $pastaTipo = EmpresaPastaDrive::pastaTipo($empresaAtual->id, $tipoEnum ?? TipoDocumentoRecebido::Outros, $this->ano);
        $itens = $this->itensArquivo($empresaAtual, $this->ano, $this->tipo);

        return [$itens, 'arquivos', $breadcrumb, $pastaTipo?->urlDrive() ?? $pastaAno?->urlDrive()];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function itensAno(Empresa $empresa): Collection
    {
        $anosPasta = $empresa->pastasDrive
            ->filter(fn (EmpresaPastaDrive $pasta) => $pasta->ehAno())
            ->keyBy('ano');

        $anosArquivo = DocumentoRecebido::query()
            ->where('empresa_id', $empresa->id)
            ->whereNotNull('drive_file_id')
            ->where('status', StatusDocumentoRecebido::EnviadoDrive)
            ->distinct()
            ->pluck('ano')
            ->filter();

        $anos = $anosPasta->keys()->merge($anosArquivo)->unique()->sortDesc()->values();

        return $anos->map(function ($ano) use ($anosPasta) {
            $ano = (int) $ano;
            $pasta = $anosPasta->get($ano);

            return [
                'chave' => 'ano:'.$ano,
                'tipo' => 'pasta',
                'nome' => (string) $ano,
                'subtitulo' => 'Ano',
                'url_drive' => $pasta?->urlDrive(),
                'abrir' => 'ano',
                'id' => $ano,
            ];
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function itensTipo(Empresa $empresa, int $ano): Collection
    {
        $pastas = $empresa->pastasDrive
            ->filter(fn (EmpresaPastaDrive $pasta) => (int) $pasta->ano === $ano && ! $pasta->ehAno() && ! $pasta->ehRaiz())
            ->keyBy('tipo');

        return collect(TipoDocumentoRecebido::pastasEstrutura())->map(function (TipoDocumentoRecebido $tipo) use ($pastas) {
            $pasta = $pastas->get($tipo->value);

            return [
                'chave' => 'tipo:'.$tipo->value,
                'tipo' => 'pasta',
                'nome' => $tipo->rotulo(),
                'subtitulo' => $tipo->pastaDrive(),
                'url_drive' => $pasta?->urlDrive(),
                'abrir' => 'tipo',
                'id' => $tipo->value,
            ];
        })->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function itensArquivo(Empresa $empresa, int $ano, string $tipo): Collection
    {
        $busca = trim($this->busca);
        $atencao = $tipo === TipoDocumentoRecebido::AtencaoIdentificarEmpresa->value;

        return DocumentoRecebido::query()
            ->where('ano', $ano)
            ->whereNotNull('drive_file_id')
            ->where('status', StatusDocumentoRecebido::EnviadoDrive)
            ->when(! $atencao, fn ($q) => $q->where('empresa_id', $empresa->id)->where('tipo_documento', $tipo))
            ->when($atencao, fn ($q) => $q->where('metadados->identificacao_pendente', true))
            ->when($busca !== '', fn ($q) => $q->where('nome_original', 'like', '%'.$busca.'%'))
            ->orderByDesc('data_documento')
            ->orderByDesc('id')
            ->get()
            ->filter(function (DocumentoRecebido $documento) use ($empresa, $atencao) {
                $pendente = (bool) ($documento->metadados['identificacao_pendente'] ?? false);

                if ($atencao) {
                    return $this->copiaDriveDaEmpresa($documento, (int) $empresa->id) !== null;
                }

                return ! $pendente;
            })
            ->map(function (DocumentoRecebido $documento) use ($empresa) {
                $copia = $this->copiaDriveDaEmpresa($documento, (int) $empresa->id);

                return [
                    'chave' => 'arquivo:'.$documento->id,
                    'tipo' => 'arquivo',
                    'nome' => $documento->nome_original,
                    'subtitulo' => $documento->data_documento?->format('d/m/Y') ?: $documento->created_at?->format('d/m/Y H:i'),
                    'tamanho' => $this->tamanhoFormatado($documento),
                    'url_drive' => is_array($copia) ? ($copia['drive_link'] ?? $documento->urlDrive()) : $documento->urlDrive(),
                    'abrir' => 'arquivo',
                    'id' => $documento->id,
                ];
            })
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function copiaDriveDaEmpresa(DocumentoRecebido $documento, int $empresaId): ?array
    {
        $copias = $documento->metadados['copias_drive'] ?? [];

        if (! is_array($copias)) {
            return null;
        }

        foreach ($copias as $copia) {
            if (is_array($copia) && (int) ($copia['empresa_id'] ?? 0) === $empresaId) {
                return $copia;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function chavesDoNivelAtual(): array
    {
        if ($this->precisaSelecionarEscritorio() || ! OperadoraContext::id()) {
            return [];
        }

        $empresas = $this->empresasDoEscritorio();
        $empresaAtual = $this->empresaId ? $empresas->firstWhere('id', $this->empresaId) : null;
        [$itens] = $this->montarConteudo($empresas, $empresaAtual);

        return $itens->pluck('chave')->filter()->values()->all();
    }
    private function idsArquivosDasChaves(array $chaves): array
    {
        $ids = [];

        foreach ($chaves as $chave) {
            if (str_starts_with($chave, 'arquivo:')) {
                $ids[] = (int) substr($chave, 8);

                continue;
            }

            $query = DocumentoRecebido::query()
                ->whereNotNull('drive_file_id')
                ->where('status', StatusDocumentoRecebido::EnviadoDrive);

            if (str_starts_with($chave, 'empresa:')) {
                $query->where('empresa_id', (int) substr($chave, 8));
            } elseif (str_starts_with($chave, 'ano:')) {
                if ($this->empresaId === null) {
                    continue;
                }
                $query->where('empresa_id', $this->empresaId)->where('ano', (int) substr($chave, 4));
            } elseif (str_starts_with($chave, 'tipo:')) {
                if ($this->empresaId === null || $this->ano === null) {
                    continue;
                }
                $tipo = substr($chave, 5);
                $query->where('ano', $this->ano);

                if ($tipo === TipoDocumentoRecebido::AtencaoIdentificarEmpresa->value) {
                    $empresaId = (int) $this->empresaId;
                    $idsPasta = $query->where('metadados->identificacao_pendente', true)->get()
                        ->filter(fn (DocumentoRecebido $doc) => $this->copiaDriveDaEmpresa($doc, $empresaId) !== null)
                        ->pluck('id')
                        ->all();
                    $ids = array_merge($ids, $idsPasta);

                    continue;
                }

                $query->where('empresa_id', $this->empresaId)->where('tipo_documento', $tipo);
            } else {
                continue;
            }

            $ids = array_merge($ids, $query->pluck('id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param  list<string>  $chaves
     * @return list<int>
     */
    private function idsArquivosDoEscritorio(array $chaves): array
    {
        $ids = $this->idsArquivosDasChaves($chaves);

        if ($ids === []) {
            return [];
        }

        return DocumentoRecebido::query()
            ->whereIn('id', $ids)
            ->where('status', StatusDocumentoRecebido::EnviadoDrive)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>  $chaves
     */
    private function nomeArquivoZip(array $chaves): string
    {
        if (count($chaves) === 1 && str_starts_with($chaves[0], 'ano:')) {
            return 'documentos-'.$chaves[0].'.zip';
        }

        if ($this->tipo !== '' && $this->ano !== null) {
            return 'documentos-'.$this->ano.'-'.$this->tipo.'.zip';
        }

        if ($this->ano !== null) {
            return 'documentos-'.$this->ano.'.zip';
        }

        return 'documentos.zip';
    }

    private function empresasDoEscritorio()
    {
        return Empresa::query()
            ->where('ativo', true)
            ->whereHas('pastasDrive', function ($query) {
                $query->where('tipo', EmpresaPastaDrive::TIPO_RAIZ);
            })
            ->with(['pastasDrive'])
            ->orderBy('nome')
            ->get();
    }

    private function garantirEmpresaDoEscritorio(): void
    {
        if ($this->empresaId === null) {
            return;
        }

        $empresa = Empresa::query()
            ->whereHas('pastasDrive', function ($query) {
                $query->where('tipo', EmpresaPastaDrive::TIPO_RAIZ);
            })
            ->find($this->empresaId);

        if ($empresa === null) {
            $this->empresaId = null;
            $this->ano = null;
            $this->tipo = '';
        }
    }

    /**
     * @param  Collection<int, Empresa>  $empresas
     * @return list<array{label: string, acao: string, id: int|string, atual: bool}>
     */
    private function irmaosDoNivel(Collection $empresas, ?Empresa $empresaAtual, string $nivel): array
    {
        if ($nivel === 'anos') {
            return $empresas->map(function (Empresa $empresa) {
                return [
                    'label' => (string) ($empresa->nome_fantasia ?: $empresa->nome),
                    'acao' => 'empresa',
                    'id' => $empresa->id,
                    'atual' => $this->empresaId === (int) $empresa->id,
                ];
            })->values()->all();
        }

        if ($nivel === 'tipos' && $empresaAtual !== null) {
            return $this->itensAno($empresaAtual)->map(function (array $item) {
                return [
                    'label' => $item['nome'],
                    'acao' => 'ano',
                    'id' => $item['id'],
                    'atual' => $this->ano === (int) $item['id'],
                ];
            })->all();
        }

        if ($nivel === 'arquivos') {
            return collect(TipoDocumentoRecebido::pastasEstrutura())->map(function (TipoDocumentoRecebido $tipo) {
                return [
                    'label' => $tipo->rotulo(),
                    'acao' => 'tipo',
                    'id' => $tipo->value,
                    'atual' => $this->tipo === $tipo->value,
                ];
            })->all();
        }

        return [];
    }

    private function precisaSelecionarEscritorio(): bool
    {
        return OperadoraContext::superAdminPrecisaSelecionarEscritorio();
    }

    private function podeConfigurar(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isSuperAdmin() || in_array($user->role, ['admin', 'gerente'], true));
    }

    /**
     * @param  list<string>  $chaves
     */
    private function abrirMoverChaves(array $chaves): void
    {
        $this->erro = null;
        $this->garantirEmpresaDoEscritorio();

        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        $ids = $this->idsArquivosDoEscritorio($chaves);

        if ($ids === []) {
            $this->erro = 'Selecione um arquivo para mover.';

            return;
        }

        $this->idsMover = $ids;
        $this->modalMoverAberto = true;
        $this->confirmandoExclusao = false;
        $candidatas = $this->idsEmpresasCandidatasMover($ids);
        $atencaoGrupo = $this->tipo === TipoDocumentoRecebido::AtencaoIdentificarEmpresa->value
            && count($candidatas) > 1;

        if ($atencaoGrupo) {
            $this->moverEmpresaIdsPermitidas = $candidatas;
            $this->moverEmpresaId = null;
            $this->moverAno = null;
            $this->moverTipo = '';

            return;
        }

        $this->moverEmpresaIdsPermitidas = [];
        $this->moverEmpresaId = $this->empresaId;
        $this->moverAno = $this->ano;
        $this->moverTipo = '';
    }

    /**
     * @param  list<string>  $chaves
     */
    private function pedirExcluirChaves(array $chaves): void
    {
        $this->erro = null;
        $this->garantirEmpresaDoEscritorio();

        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        $ids = $this->idsArquivosDoEscritorio($chaves);

        if ($ids === []) {
            $this->erro = 'Selecione um arquivo para excluir.';

            return;
        }

        $this->idsExcluir = $ids;
        $this->confirmandoExclusao = true;
        $this->modalMoverAberto = false;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function idsEmpresasCandidatasMover(array $ids): array
    {
        $comDrive = $this->empresasDoEscritorio()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $candidatas = [];

        $documentos = DocumentoRecebido::query()
            ->whereIn('id', $ids)
            ->where('status', StatusDocumentoRecebido::EnviadoDrive)
            ->with('grupo')
            ->get();

        foreach ($documentos as $documento) {
            $copias = $documento->metadados['copias_drive'] ?? [];

            if (is_array($copias)) {
                foreach ($copias as $copia) {
                    if (is_array($copia)) {
                        $empresaId = (int) ($copia['empresa_id'] ?? 0);

                        if ($empresaId > 0) {
                            $candidatas[] = $empresaId;
                        }
                    }
                }
            }

            if ($documento->grupo !== null) {
                $candidatas = array_merge($candidatas, $documento->grupo->idsEmpresas());
            }
        }

        return array_values(array_unique(array_intersect($candidatas, $comDrive)));
    }

    /**
     * @param  Collection<int, Empresa>  $empresas
     * @return array{0: Collection<int, array<string, mixed>>, 1: string, 2: list<array{label: string, nivel: string}>, 3: bool, 4: bool}
     */
    private function montarModalMover(Collection $empresas): array
    {
        $empresasModal = $this->moverEmpresaIdsPermitidas !== []
            ? $empresas->filter(fn (Empresa $empresa) => in_array((int) $empresa->id, $this->moverEmpresaIdsPermitidas, true))->values()
            : $empresas;

        $breadcrumb = [['label' => 'Empresas', 'nivel' => 'empresas']];
        $empresaAtual = $this->moverEmpresaId
            ? $empresas->firstWhere('id', $this->moverEmpresaId)
            : null;

        if ($empresaAtual === null) {
            $itens = $empresasModal->map(function (Empresa $empresa) {
                return [
                    'chave' => 'empresa:'.$empresa->id,
                    'tipo' => 'pasta',
                    'nome' => $empresa->nome_fantasia ?: $empresa->nome,
                    'subtitulo' => $empresa->codigo_sistema ?: $empresa->cnpj,
                    'abrir' => 'empresa',
                    'id' => $empresa->id,
                ];
            })->values();

            return [$itens, 'empresas', $breadcrumb, false, false];
        }

        $breadcrumb[] = [
            'label' => (string) ($empresaAtual->nome_fantasia ?: $empresaAtual->nome),
            'nivel' => 'empresa',
        ];

        if ($this->moverAno === null) {
            $itens = $this->itensAno($empresaAtual)->map(function (array $item) {
                $item['abrir'] = 'ano';

                return $item;
            });

            return [$itens, 'anos', $breadcrumb, false, false];
        }

        $breadcrumb[] = ['label' => (string) $this->moverAno, 'nivel' => 'ano'];

        if ($this->moverTipo === '') {
            $itens = $this->itensTipo($empresaAtual, $this->moverAno)->map(function (array $item) {
                $item['abrir'] = 'tipo';

                return $item;
            });

            return [$itens, 'tipos', $breadcrumb, false, false];
        }

        $tipoEnum = TipoDocumentoRecebido::tryFrom($this->moverTipo);
        $breadcrumb[] = ['label' => $tipoEnum?->rotulo() ?? $this->moverTipo, 'nivel' => 'tipo'];
        $itens = collect([
            [
                'chave' => 'destino:'.$this->moverTipo,
                'tipo' => 'pasta',
                'nome' => $tipoEnum?->rotulo() ?? $this->moverTipo,
                'subtitulo' => 'Pasta de destino',
                'abrir' => '',
                'id' => $this->moverTipo,
            ],
        ]);

        return [$itens, 'destino', $breadcrumb, true, $this->destinoMoverIgualOrigem()];
    }

    private function destinoMoverIgualOrigem(): bool
    {
        return $this->moverEmpresaId !== null
            && $this->moverAno !== null
            && $this->moverTipo !== ''
            && $this->empresaId === $this->moverEmpresaId
            && $this->ano === $this->moverAno
            && $this->tipo === $this->moverTipo;
    }

    private function tamanhoFormatado(DocumentoRecebido $documento): string
    {
        if ($documento->tamanho_bytes === null && is_string($documento->storage_path) && $documento->storage_path !== '') {
            try {
                if (Storage::exists($documento->storage_path)) {
                    $documento->update(['tamanho_bytes' => Storage::size($documento->storage_path)]);
                }
            } catch (\Throwable) {
            }
        }

        return DocumentoRecebido::formatarTamanho(
            $documento->tamanho_bytes !== null ? (int) $documento->tamanho_bytes : null,
        );
    }
}
