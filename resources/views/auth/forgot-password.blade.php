<x-guest-layout>
    <div class="w-full bg-white rounded-lg shadow p-6">
        <h1 class="text-xl font-semibold text-gray-900 mb-1">Recuperar senha</h1>
        <p class="text-sm text-gray-500 mb-6">Receba um link de redefinição por e-mail</p>

        @if (session('status'))
            <div class="mb-4 rounded-md bg-green-50 border border-green-200 px-3 py-2 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       class="w-full rounded-md border-gray-300 shadow-sm border px-3 py-2 text-sm">
            </div>
            <button type="submit"
                    class="w-full inline-flex justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                Enviar link
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500">
            <a href="{{ route('login') }}" class="text-indigo-600 hover:text-indigo-500 font-medium">Voltar ao login</a>
        </p>
    </div>
</x-guest-layout>
