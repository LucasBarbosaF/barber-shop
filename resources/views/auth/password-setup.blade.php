<x-guest-layout>
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-xl font-semibold text-gray-900 mb-1">Definir sua senha</h1>
        <p class="text-sm text-gray-500 mb-6">
            Esta é a senha definitiva da sua conta. Depois de salvar, você será levado ao painel.
        </p>

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2 mb-4">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.setup.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="current_password" class="text-sm font-medium text-gray-700">Senha provisória</label>
                <input id="current_password" type="password" name="current_password" required autofocus autocomplete="current-password">
                @error('current_password')
                    <p class="text-sm text-red-700 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="text-sm font-medium text-gray-700">Nova senha</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
                <p class="text-xs text-gray-500 mt-1">Mínimo de 8 caracteres, com letras e números.</p>
                @error('password')
                    <p class="text-sm text-red-700 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="text-sm font-medium text-gray-700">Confirme a nova senha</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit" class="inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                Salvar e entrar
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-6 pt-4 text-center">
            @csrf
            <button type="submit" class="text-sm text-gray-500">Sair</button>
        </form>
    </div>
</x-guest-layout>