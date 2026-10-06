<?php

namespace App\Http\Controllers\App;

use App\Application\Payments\Actions\RecordPayment;
use App\Application\Payments\Actions\RefundPayment;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $this->authorize('cash.view');

        $payments = Payment::query()
            ->with(['attendance.appointment.customer', 'attendance.appointment.barber', 'refunds'])
            ->latest()
            ->limit(50)
            ->get();

        if ($request->expectsJson()) {
            return response()->json(['data' => $payments]);
        }

        return view('app.payments.index', compact('payments'));
    }

    public function store(
        Request $request,
        Attendance $attendance,
        RecordPayment $recordPayment,
        TenantContext $tenantContext,
    ): JsonResponse|RedirectResponse {
        $this->authorize('cash.manage');
        $tenantId = $tenantContext->id();
        abort_if($tenantId === null, 403);
        $tenant = Tenant::query()->findOrFail($tenantId);
        $request->merge([
            'idempotency_key' => $request->input('idempotency_key') ?? $request->header('Idempotency-Key'),
        ]);
        $attributes = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'method' => ['required', Rule::in($tenant->enabledPaymentMethodValues())],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $result = $recordPayment->execute([
            'attendance' => $attendance,
            'amount' => (string) $attributes['amount'],
            'method' => PaymentMethod::from($attributes['method']),
            'idempotency_key' => $attributes['idempotency_key'],
            'actor_id' => $request->user()?->getKey(),
        ]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.attendances.show', $attendance)
                ->with('status', 'Pagamento registrado.');
        }

        return response()->json(['data' => $result['payment']], 201);
    }

    public function refund(
        Request $request,
        Payment $payment,
        RefundPayment $refundPayment,
    ): JsonResponse {
        $this->authorize('cash.manage');
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        $attributes = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $result = $refundPayment->execute([
            'payment' => $payment,
            'amount' => (string) $attributes['amount'],
            'reason' => $attributes['reason'],
            'idempotency_key' => $attributes['idempotency_key'],
            'actor_id' => $request->user()?->getKey(),
        ]);

        return response()->json(['data' => $result['refund']], 201);
    }
}
