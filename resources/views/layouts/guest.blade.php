@props(['title' => 'IntegraExpert'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#11079c">

        <title>{{ $title }}</title>

        <x-favicons />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-slate-900 antialiased">
        <main class="relative min-h-screen overflow-hidden bg-slate-50 lg:grid lg:grid-cols-[minmax(0,1.05fr)_minmax(34rem,0.95fr)]">
            <section class="relative hidden min-h-screen overflow-hidden bg-slate-950 px-12 py-10 text-white lg:flex xl:px-20 xl:py-14">
                <div class="absolute inset-0 bg-gradient-to-br from-slate-950 via-indigo-950 to-[#11079c]"></div>
                <div class="absolute -left-32 top-24 h-80 w-80 rounded-full bg-cyan-400/20 blur-3xl"></div>
                <div class="absolute -right-24 bottom-0 h-96 w-96 rounded-full bg-indigo-500/30 blur-3xl"></div>
                <div class="absolute inset-0 opacity-[0.07]" style="background-image: linear-gradient(rgba(255,255,255,.8) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.8) 1px, transparent 1px); background-size: 48px 48px;"></div>

                <div class="relative z-10 mx-auto flex w-full max-w-2xl flex-col">
                    <div class="inline-flex w-fit items-center rounded-2xl bg-white px-4 py-3 shadow-2xl shadow-slate-950/20">
                        <x-application-logo class="h-9 w-auto max-w-[220px]" />
                    </div>

                    <div class="my-auto py-14">
                        <div class="mb-7 inline-flex items-center gap-2 rounded-full border border-cyan-300/25 bg-white/10 px-4 py-2 text-sm font-semibold text-cyan-50 backdrop-blur-sm">
                            <span class="h-2 w-2 rounded-full bg-cyan-300 shadow-[0_0_0_5px_rgba(103,232,249,0.12)]"></span>
                            Feito para escritórios de contabilidade
                        </div>

                        <h1 class="max-w-xl text-4xl font-extrabold leading-[1.08] tracking-tight xl:text-5xl">
                            Menos digitação.<br>
                            Mais controle no fechamento contábil.
                        </h1>

                        <p class="mt-6 max-w-xl text-lg leading-8 text-indigo-100/90">
                            Transforme extratos, planilhas de vendas por cartão e outros arquivos em lançamentos prontos para o Domínio — ou converta PDFs em OFX quando precisar.
                        </p>

                        <ol class="mt-10 grid grid-cols-4 gap-3" aria-label="Fluxo de trabalho do IntegraExpert">
                            @foreach (['Importar', 'Amarrar', 'Conferir', 'Exportar'] as $index => $step)
                                <li class="rounded-2xl border border-white/10 bg-white/[0.08] px-3 py-4 backdrop-blur-sm">
                                    <span class="block text-xs font-bold text-cyan-300">0{{ $index + 1 }}</span>
                                    <span class="mt-2 block text-sm font-semibold text-white">{{ $step }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    <div class="flex items-center gap-3 border-t border-white/10 pt-6 text-sm text-indigo-100/80">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-cyan-300/15 text-cyan-200">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.31a1 1 0 0 1-1.42 0l-3.25-3.277a1 1 0 0 1 1.42-1.408l2.54 2.56 6.54-6.593a1 1 0 0 1 1.414-.006Z" clip-rule="evenodd" />
                            </svg>
                        </span>
                        Regras reaproveitáveis e conferência profissional antes da exportação.
                    </div>
                </div>
            </section>

            <section class="relative flex min-h-screen items-center justify-center px-5 py-10 sm:px-8 lg:px-12">
                <div class="absolute -right-24 -top-24 h-72 w-72 rounded-full bg-cyan-100/60 blur-3xl lg:hidden"></div>
                <div class="absolute -bottom-24 -left-24 h-72 w-72 rounded-full bg-indigo-100/60 blur-3xl lg:hidden"></div>

                <div class="relative z-10 w-full max-w-md">
                    <div class="mb-8 flex justify-center lg:hidden">
                        <x-application-logo class="h-10 w-auto max-w-[230px]" />
                    </div>

                    <div class="rounded-3xl border border-slate-200/80 bg-white px-6 py-8 shadow-[0_24px_70px_-30px_rgba(15,23,42,0.35)] sm:px-9 sm:py-10">
                        {{ $slot }}
                    </div>

                    <div class="mt-7 text-center text-sm text-slate-500">
                        <p>
                            Ainda não utiliza o IntegraExpert?
                            <a
                                href="https://wa.me/5555999046063?text=Ol%C3%A1%2C%20gostaria%20de%20conhecer%20o%20IntegraExpert."
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-semibold text-indigo-700 underline decoration-indigo-200 underline-offset-4 transition hover:text-indigo-900 hover:decoration-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                Conheça a plataforma
                            </a>
                        </p>
                        <p class="mt-4 text-xs text-slate-400">© {{ date('Y') }} IntegraExpert</p>
                    </div>
                </div>
            </section>
        </main>
    </body>
</html>
