<?php

namespace App\Http\Controllers\App;

use App\Application\Services\Actions\CreateService;
use App\Application\Services\Actions\DeleteService;
use App\Application\Services\Actions\ListServices;
use App\Application\Services\Actions\UpdateService;
use App\Application\Shared\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(Request $request, ListServices $listServices): JsonResponse|View
    {
        $this->authorize('viewAny', Service::class);

        $services = $listServices->execute([])['services'];

        if ($request->expectsJson()) {
            return response()->json($services);
        }

        return view('app.services.index', ['services' => $services]);
    }

    public function create(): View
    {
        $this->authorize('create', Service::class);

        return view('app.services.create');
    }

    public function edit(Service $service): View
    {
        $this->authorize('update', $service);

        return view('app.services.edit', ['service' => $service]);
    }

    public function show(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        return response()->json(['data' => $service]);
    }

    public function store(
        Request $request,
        CreateService $createService,
        TenantContext $tenantContext,
    ): JsonResponse|RedirectResponse {
        $this->authorize('create', Service::class);

        $attributes = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('services', 'name')->where('tenant_id', $tenantContext->id()),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'duration_minutes' => ['required', 'integer', 'between:1,1440'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $service = $createService->execute($attributes)['service'];

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.services.index')
                ->with('status', 'Serviço cadastrado com sucesso.');
        }

        return response()->json(['data' => $service], 201);
    }

    public function update(
        Request $request,
        Service $service,
        UpdateService $updateService,
        TenantContext $tenantContext,
    ): JsonResponse|RedirectResponse {
        $this->authorize('update', $service);

        $attributes = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique('services', 'name')
                    ->where('tenant_id', $tenantContext->id())
                    ->ignore($service->getKey()),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'between:1,1440'],
            'price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $updated = $updateService->execute([
            'service' => $service,
            'attributes' => $attributes,
        ])['service'];

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.services.index')
                ->with('status', 'Serviço atualizado com sucesso.');
        }

        return response()->json(['data' => $updated]);
    }

    public function destroy(
        Request $request,
        Service $service,
        DeleteService $deleteService,
    ): JsonResponse|RedirectResponse {
        $this->authorize('delete', $service);
        $deleteService->execute(['service' => $service]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.services.index')
                ->with('status', 'Serviço excluído com sucesso.');
        }

        return response()->json(status: 204);
    }
}
