<!DOCTYPE html>
<html lang="pt_BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Barber SaaS') }}</title>
    @vite(['resources/css/admin.css', 'resources/css/login.css', 'resources/js/admin.js'])
</head>
<body class="login-page" data-bs-theme="light">
    {{ $slot }}
</body>
</html>
