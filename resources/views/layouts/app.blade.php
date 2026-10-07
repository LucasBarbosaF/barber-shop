<!DOCTYPE html>
<html lang="pt_BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@if (! empty($title)){{ $title }} — @endif{{ filled($currentBarbershop?->name) ? $currentBarbershop->name : config('app.name', 'Barber SaaS') }}</title>
    @if (filled($currentBarbershop?->logo_path))
        <link rel="icon" href="{{ asset('storage/'.$currentBarbershop->logo_path) }}">
    @endif
    {{-- $assets: entradas extras por página (ex.: agenda.css/js), sempre após o CSS/JS base do painel. --}}
    @vite(array_merge(['resources/css/admin.css', 'resources/js/admin.js'], $assets ?? []))
</head>
{{--
    `sidebar-mini` + `sidebar-collapse` (alternado pelo pushmenu) é o que deixa a
    sidebar recolhível em desktop; `sidebar-expand-lg` é a fronteira em que ela
    vira off-canvas no mobile. `layout-fixed` mantém header/sidebar fixos e o
    conteúdo como única área de rolagem.
--}}
<body class="layout-fixed sidebar-mini sidebar-expand-lg bg-body-tertiary">
    <div class="app-wrapper">
        @include('layouts.partials.header')

        @include('layouts.partials.sidebar')

        <main class="app-main">
            <div class="app-content">
                <div class="container-fluid">
                    @if (session('status'))
                        <div class="alert alert-success alert-dismissible fade show" role="status">
                            {{ session('status') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                        </div>
                    @endif

                    {{ $slot }}
                </div>
            </div>
        </main>

        @include('layouts.partials.footer')
    </div>
</body>
</html>
