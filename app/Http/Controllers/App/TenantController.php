<?php

namespace App\Http\Controllers\App;

use App\Application\Shared\Tenancy\Exceptions\TenantAccessDenied;
use App\Application\Shared\Tenancy\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\SwitchTenantRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Troca a barbearia corrente da pessoa autenticada.
 *
 * Três coisas acontecem aqui, em ordem, e a ordem importa:
 *
 *  1. a sessão é regenerada — trocar de tenant é uma mudança de contexto de
 *     autorização; continuar na mesma sessão deixaria o id anterior vivo;
 *  2. o `TenantResolver` exige membership ativa no tenant pedido — a sessão
 *     regenerada ainda não autoriza nada sozinha;
 *  3. só então o contexto recebe o tenant novo.
 *
 * Superadmin não tem atalho: ele é global, não pertence a barbearia nenhuma, e
 * nas telas de barbearia age por membership como qualquer outra pessoa.
 *
 * A lista de barbearias que a pessoa pode escolher continua servida pelo
 * `TenantResolution` que o middleware deixa na request; a tela que a consome é
 * da Sprint 15.
 */
class TenantController extends Controller
{
    public function update(SwitchTenantRequest $request, TenantResolver $resolver): RedirectResponse
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 401);

        $request->session()->regenerate();

        try {
            $resolver->switchTo($user, $request->tenantId());
        } catch (TenantAccessDenied) {
            abort(403, 'Você não pertence a esta barbearia.');
        }

        return redirect()->route('app.dashboard');
    }
}
