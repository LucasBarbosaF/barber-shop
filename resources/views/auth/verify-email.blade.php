<x-guest-layout>
    <div class="w-full bg-white rounded-lg shadow p-6">
        <h1 class="text-xl font-semibold text-gray-900 mb-1">Verifique seu e-mail</h1>
        <p class="text-sm text-gray-500 mb-6">Enviamos um link de verificação para o seu e-mail.</p>

        @if (session('status'))
            <div class="mb-4 rounded-md bg-green-50 border border-green-200 px-3 py-2 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit"
                    class="w-full inline-flex justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                Reenviar e-mail de verificação
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-4">
            @csrf
            <button type="submit" class="w-full text-sm text-gray-500 hover:text-gray-700">Sair</button>
        </form>
    </div>
</x-guest-layout>
