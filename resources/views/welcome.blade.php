<!DOCTYPE html>
<html lang="pt_BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Barber SaaS') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #f3f4f6; color: #111827; }
        .min-h-screen { min-height: 100vh; }
        .flex { display: flex; }
        .items-center { align-items: center; }
        .justify-center { justify-content: center; }
        .text-center { text-align: center; }
        .max-w-md { max-width: 28rem; }
        .mx-auto { margin-left: auto; margin-right: auto; }
        .px-6 { padding-left: 1.5rem; padding-right: 1.5rem; }
        .text-3xl { font-size: 1.875rem; line-height: 2.25rem; }
        .font-bold { font-weight: 700; }
        .text-gray-900 { color: #111827; }
        .text-gray-600 { color: #4b5563; }
        .text-white { color: #fff; }
        .text-sm { font-size: 0.875rem; }
        .font-medium { font-weight: 500; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-8 { margin-bottom: 2rem; }
        .gap-3 { gap: 0.75rem; }
        .inline-flex { display: inline-flex; }
        .rounded-md { border-radius: 0.375rem; }
        .bg-indigo-600 { background: #4f46e5; }
        .bg-white { background: #fff; }
        .hover\:bg-indigo-700:hover { background: #4338ca; }
        .hover\:bg-gray-50:hover { background: #f9fafb; }
        .border { border: 1px solid #d1d5db; }
        .border-gray-300 { border-color: #d1d5db; }
        .px-4 { padding-left: 1rem; padding-right: 1rem; }
        .py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
        a { text-decoration: none; }
    </style>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center font-sans">
    <div class="text-center max-w-md mx-auto px-6">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ config('app.name', 'Barber SaaS') }}</h1>
        <p class="text-gray-600 mb-8">Sistema multi-tenant de gestão para barbearias.</p>
        <div class="flex items-center justify-center gap-3">
            @auth
                <a href="/app" class="inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Ir para o painel
                </a>
            @else
                <a href="{{ route('login') }}" class="inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Entrar
                </a>
            @endauth
        </div>
    </div>
</body>
</html>
