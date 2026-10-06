<?php

namespace App\Http\Controllers\App;

use App\Application\Cash\Actions\AddCashMovement;
use App\Application\Cash\Actions\CloseCashRegister;
use App\Application\Cash\Actions\OpenCashRegister;
use App\Application\Cash\CashRegisterBalance;
use App\Application\Payments\PaymentBalance;
use App\Domain\Cash\Enums\CashTransactionType;
use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CashRegisterController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $this->authorize('cash.view');

        $cashRegisters = CashRegister::query()
            ->with(['transactions' => fn ($query) => $query->latest()->limit(20)])
            ->orderByDesc('opened_at')
            ->limit(20)
            ->get();

        if ($request->expectsJson()) {
            return response()->json(['data' => $cashRegisters]);
        }

        $openRegister = $cashRegisters->first(fn (CashRegister $register): bool => $register->status->value === 'open');
        $expectedOpenAmount = $openRegister
            ? PaymentBalance::fromMinorUnits(CashRegisterBalance::expectedMinorUnits($openRegister))
            : null;

        return view('app.cash.index', compact('cashRegisters', 'expectedOpenAmount'));
    }

    public function open(Request $request, OpenCashRegister $openCashRegister): JsonResponse|RedirectResponse
    {
        $this->authorize('cash.manage');
        $attributes = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $result = $openCashRegister->execute([
            'opening_amount' => (string) $attributes['opening_amount'],
            'actor_id' => $request->user()?->getKey(),
        ]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.cash.index')
                ->with('status', 'Caixa aberto com sucesso.');
        }

        return response()->json(['data' => $result['cash_register']], 201);
    }

    public function movement(
        Request $request,
        CashRegister $cashRegister,
        AddCashMovement $addCashMovement,
    ): JsonResponse|RedirectResponse {
        $this->authorize('cash.manage');
        $attributes = $request->validate([
            'type' => ['required', Rule::in([CashTransactionType::CashIn->value, CashTransactionType::CashOut->value])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'description' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $result = $addCashMovement->execute([
            'cash_register' => $cashRegister,
            'type' => CashTransactionType::from($attributes['type']),
            'amount' => (string) $attributes['amount'],
            'description' => $attributes['description'],
            'actor_id' => $request->user()?->getKey(),
        ]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.cash.index')
                ->with('status', 'Movimentação registrada com sucesso.');
        }

        return response()->json(['data' => $result['cash_transaction']], 201);
    }

    public function close(
        Request $request,
        CashRegister $cashRegister,
        CloseCashRegister $closeCashRegister,
    ): JsonResponse|RedirectResponse {
        $this->authorize('cash.manage');
        $attributes = $request->validate([
            'closing_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $result = $closeCashRegister->execute([
            'cash_register' => $cashRegister,
            'closing_amount' => (string) $attributes['closing_amount'],
            'actor_id' => $request->user()?->getKey(),
        ]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.cash.index')
                ->with('status', 'Caixa fechado com sucesso.');
        }

        return response()->json(['data' => $result['cash_register']]);
    }
}
