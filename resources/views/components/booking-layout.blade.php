<!DOCTYPE html>
<html lang="pt_BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Agendar horário — {{ filled($currentBarbershop?->name) ? $currentBarbershop->name : config('app.name', 'Barber SaaS') }}</title>
    @if (filled($currentBarbershop?->logo_path))
        <link rel="icon" href="{{ asset('storage/'.$currentBarbershop->logo_path) }}">
    @endif
    @vite(['resources/css/admin.css', 'resources/css/booking.css', 'resources/js/booking.js'])
</head>
<body class="bg-body-tertiary">
    {{ $slot }}
</body>
</html>
