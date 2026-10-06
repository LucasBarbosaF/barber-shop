<x-guest-layout>
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-xl font-semibold text-gray-900">Acesso negado</h1>

        <p class="mt-4 text-sm text-gray-600">
            {{ $message ?? 'Você não tem permissão para esta ação.' }}
        </p>

        {{--
            O botão só aparece para quem tem sessão: sem ele, um 403 para guest
            viraria um beco sem saída, porque a tela seguinte exigiria login.
        --}}
        @auth
            <div class="mt-6">
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('app.dashboard') }}"
                   class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">
                    Voltar
                </a>
            </div>
        @else
            <div class="mt-6">
                <a href="{{ route('login') }}"
                   class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">
                    Entrar
                </a>
            </div>
        @endauth
    </div>
</x-guest-layout>