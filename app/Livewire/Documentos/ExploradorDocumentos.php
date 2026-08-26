<?php

namespace App\Livewire\Documentos;

use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\EmpresaPastaDrive;
use App\Models\Empresa;
use App\Services\Documentos\CompactarDocumentosDriveService;
use App\Services\OperadoraContext;
use Illuminate\Support\Collection;
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

        return DocumentoRecebido::query()
            ->where('empresa_id', $empresa->id)
            ->where('ano', $ano)
            ->where('tipo_documento', $tipo)
            ->whereNotNull('drive_file_id')
            ->where('status', StatusDocumentoRecebido::EnviadoDrive)
            ->when($busca !== '', fn ($q) => $q->where('nome_original', 'like', '%'.$busca.'%'))
            ->orderByDesc('data_documento')
            ->orderByDesc('id')
            ->get()
            ->map(function (DocumentoRecebido $documento) {
                return [
                    'chave' => 'arquivo:'.$documento->id,
                    'tipo' => 'arquivo',
                    'nome' => $documento->nome_original,
                    'subtitulo' => $documento->data_documento?->format('d/m/Y') ?: $documento->created_at?->format('d/m/Y H:i'),
                    'url_drive' => $documento->urlDrive(),
                    'abrir' => 'arquivo',
                    'id' => $documento->id,
                ];
            })
            ->values();
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
                $query->where('empresa_id', $this->empresaId)
                    ->where('ano', $this->ano)
                    ->where('tipo_documento', substr($chave, 5));
            } else {
                continue;
            }

            $ids = array_merge($ids, $query->pluck('id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
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
}
