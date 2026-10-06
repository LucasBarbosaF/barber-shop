<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Troca da senha provisória definida pelo superadmin no primeiro acesso.
 */
class PasswordSetupController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->user($request)->mustChangePassword()) {
            return redirect()->route('app.dashboard');
        }

        return view('auth.password-setup');
    }

    public function store(Request $request, UpdateUserPassword $updateUserPassword): RedirectResponse
    {
        $user = $this->user($request);

        $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'A senha atual está incorreta.',
        ]);

        $updateUserPassword->update($user, $request->only([
            'current_password',
            'password',
            'password_confirmation',
        ]));

        $user->forceFill(['must_change_password' => false])->save();

        $request->session()->regenerate();

        return redirect()
            ->route('app.dashboard')
            ->with('status', 'Senha definida com sucesso.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
