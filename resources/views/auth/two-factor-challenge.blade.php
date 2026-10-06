<x-guest-layout>
    <div class="w-full bg-white rounded-lg shadow p-6">
        <h1 class="text-xl font-semibold text-gray-900 mb-1">Autenticação em duas etapas</h1>
        <p class="text-sm text-gray-500 mb-6">Informe o código do seu aplicativo autenticador</p>

        @if (session('status'))
            <div class="mb-4 rounded-md bg-green-50 border border-green-200 px-3 py-2 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-4">
            @csrf
            <div>
                <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Código</label>
                <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" required autofocus
                       class="w-full rounded-md border-gray-300 shadow-sm border px-3 py-2 text-sm">
            </div>

            @if (session('mfa_login'))
                <div class="text-sm text-gray-500">
                    <label for="recovery_code" class="block text-sm font-medium text-gray-700 mb-1">Ou código de recuperação</label>
                    <input id="recovery_code" name="recovery_code" type="text"
                           class="w-full rounded-md border-gray-300 shadow-sm border px-3 py-2 text-sm">
                </div>
            @endif

            <button type="submit"
                    class="w-full inline-flex justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                Confirmar
            </button>
        </form>
    </div>
</x-guest-layout>
