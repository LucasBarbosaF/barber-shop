<x-guest-layout>
    <div class="w-full bg-white rounded-lg shadow p-6">
        <h1 class="text-xl font-semibold text-gray-900 mb-1">Redefinir senha</h1>
        <p class="text-sm text-gray-500 mb-6">Escolha uma nova senha para a sua conta</p>

        @if (session('status'))
            <div class="mb-4 rounded-md bg-green-50 border border-green-200 px-3 py-2 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">
            <input type="hidden" name="email" value="{{ $request->email }}">

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Nova senha</label>
                <input id="password" name="password" type="password" required
                       class="w-full rounded-md border-gray-300 shadow-sm border px-3 py-2 text-sm">
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirmar senha</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required
                       class="w-full rounded-md border-gray-300 shadow-sm border px-3 py-2 text-sm">
            </div>

            <button type="submit"
                    class="w-full inline-flex justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                Redefinir senha
            </button>
        </form>
    </div>
</x-guest-layout>
