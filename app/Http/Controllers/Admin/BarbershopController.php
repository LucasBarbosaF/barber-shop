<?php

namespace App\Http\Controllers\Admin;

use App\Application\Admin\Actions\RegisterBarbershop;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBarbershopRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class BarbershopController extends Controller
{
    public function index(): View
    {
        $tenants = Tenant::query()
            ->with('admins')
            ->latest()
            ->paginate(15);

        return view('admin.barbershops.index', [
            'tenants' => $tenants,
        ]);
    }

    public function create(): View
    {
        return view('admin.barbershops.create');
    }

    /**
     * Cadastra a barbearia junto com o usuário responsável.
     */
    public function store(StoreBarbershopRequest $request, RegisterBarbershop $registerBarbershop): RedirectResponse
    {
        /** @var array{tenant: Tenant, password: string} $result */
        $result = $registerBarbershop->execute($request->validatedData());

        return redirect()
            ->route('admin.barbershops.index')
            ->with('created_barbershop', $result['tenant']->name)
            ->with('created_email', $request->validated('owner_email'))
            ->with('created_password', $result['password']);
    }
}
