<x-guest-layout>
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-xl font-semibold text-gray-900">Você não entrou</h1>

        <p class="mt-4 text-sm text-gray-600">
            {{ $message ?? 'Esta tela exige uma sessão ativa.' }}
        </p>

        <div class="mt-6">
            <a href="{{ route('login') }}"
               class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">
                Entrar
            </a>
        </div>
    </div>
</x-guest-layout>