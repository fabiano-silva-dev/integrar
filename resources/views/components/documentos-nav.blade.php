@php
    $itemAtivo = $ativo ?? '';
@endphp
<div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 mb-6">
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('documentos.whatsapp') }}"
           class="px-3 py-2 text-sm rounded-t-lg {{ $itemAtivo === 'whatsapp' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
            WhatsApp
        </a>
        <a href="{{ route('documentos.grupos') }}"
           class="px-3 py-2 text-sm rounded-t-lg {{ $itemAtivo === 'grupos' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
            Grupos
        </a>
        <a href="{{ route('documentos.drive') }}"
           class="px-3 py-2 text-sm rounded-t-lg {{ $itemAtivo === 'drive' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
            Google Drive
        </a>
        <a href="{{ route('documentos.ia') }}"
           class="px-3 py-2 text-sm rounded-t-lg {{ $itemAtivo === 'ia' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
            IA
        </a>
        <a href="{{ route('documentos.recebidos') }}"
           class="px-3 py-2 text-sm rounded-t-lg {{ $itemAtivo === 'recebidos' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
            Recebidos
        </a>
        @if (auth()->user()?->podeVerLogDocumentos())
            <a href="{{ route('documentos.log') }}"
               class="px-3 py-2 text-sm rounded-t-lg {{ $itemAtivo === 'log' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
                Log
            </a>
        @endif
    </div>
    <a href="{{ route('documentos') }}" class="text-sm font-semibold text-indigo-600 pb-2">
        Ver arquivos
    </a>
</div>
