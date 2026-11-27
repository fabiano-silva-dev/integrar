@if($log || $codigo)
    <details class="mt-2">
        <summary class="cursor-pointer text-blue-700 font-medium">Detalhes técnicos</summary>
        <dl class="mt-2 text-gray-700 space-y-1 break-words">
            @if($etapa = data_get($log,'etapa'))<div><dt class="inline font-medium">Etapa:</dt> <dd class="inline">{{ str_replace('_',' ',$etapa) }}</dd></div>@endif
            @if($cod = data_get($log,'codigo',$codigo))<div><dt class="inline font-medium">Código:</dt> <dd class="inline">{{ $cod }}</dd></div>@endif
            @if(isset($log['http']))<div><dt class="inline font-medium">HTTP:</dt> <dd class="inline">{{ $log['http'] }}</dd></div>@endif
            @if(isset($log['curl']))<div><dt class="inline font-medium">cURL:</dt> <dd class="inline">{{ $log['curl'] }}</dd></div>@endif
            @if(isset($log['exit_code']))<div><dt class="inline font-medium">Código de saída:</dt> <dd class="inline">{{ $log['exit_code'] }}</dd></div>@endif
            @if(isset($log['duracao_ms']))<div><dt class="inline font-medium">Duração:</dt> <dd class="inline">{{ $log['duracao_ms'] }} ms</dd></div>@endif
        </dl>
        @if($orientacao = \App\Services\Nfse\LogTesteAcessoNfse::orientacao($log ?? []))<p class="mt-2 text-gray-600">{{ $orientacao }}</p>@endif
        @if(!$log)<p class="mt-1 text-gray-600">Teste antigo: não foram registrados detalhes adicionais.</p>@endif
    </details>
@else
    <p class="mt-1 text-xs text-gray-600">Sem detalhes técnicos adicionais registrados.</p>
@endif
