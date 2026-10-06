<?php

namespace App\Http\Controllers\App;

use App\Application\Customers\Actions\CreateCustomer;
use App\Application\Customers\Actions\DeleteCustomer;
use App\Application\Customers\Actions\ListCustomers;
use App\Application\Customers\Actions\UpdateCustomer;
use App\Application\Shared\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request, ListCustomers $listCustomers): JsonResponse|View
    {
        $this->authorize('viewAny', Customer::class);

        $customers = $listCustomers->execute([])['customers'];

        if ($request->expectsJson()) {
            return response()->json($customers);
        }

        return view('app.customers.index', ['customers' => $customers]);
    }

    public function create(): View
    {
        $this->authorize('create', Customer::class);

        return view('app.customers.create');
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('app.customers.edit', ['customer' => $customer]);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return response()->json(['data' => $customer]);
    }

    public function store(
        Request $request,
        CreateCustomer $createCustomer,
        TenantContext $tenantContext,
    ): JsonResponse|RedirectResponse {
        $this->authorize('create', Customer::class);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => [
                'required',
                'string',
                'max:32',
                Rule::unique('customers', 'phone')->where('tenant_id', $tenantContext->id()),
            ],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $customer = $createCustomer->execute($attributes)['customer'];

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.customers.index')
                ->with('status', 'Cliente cadastrado com sucesso.');
        }

        return response()->json(['data' => $customer], 201);
    }

    public function update(
        Request $request,
        Customer $customer,
        UpdateCustomer $updateCustomer,
        TenantContext $tenantContext,
    ): JsonResponse|RedirectResponse {
        $this->authorize('update', $customer);

        $attributes = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('customers', 'phone')
                    ->where('tenant_id', $tenantContext->id())
                    ->ignore($customer->getKey()),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);

        $updated = $updateCustomer->execute([
            'customer' => $customer,
            'attributes' => $attributes,
        ])['customer'];

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.customers.index')
                ->with('status', 'Cliente atualizado com sucesso.');
        }

        return response()->json(['data' => $updated]);
    }

    public function destroy(
        Request $request,
        Customer $customer,
        DeleteCustomer $deleteCustomer,
    ): JsonResponse|RedirectResponse {
        $this->authorize('delete', $customer);
        $deleteCustomer->execute(['customer' => $customer]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.customers.index')
                ->with('status', 'Cliente excluído com sucesso.');
        }

        return response()->json(status: 204);
    }
}
