<?php

namespace App\Application\Cash\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Cash\Enums\CashRegisterStatus;
use App\Models\CashRegister;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpenCashRegister extends Action
{
    /**
     * @param  array{opening_amount: string, actor_id: int|null}  $input
     * @return array{cash_register: CashRegister}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            if (CashRegister::query()->where('status', CashRegisterStatus::Open)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'cash_register' => 'Já existe um caixa aberto nesta barbearia.',
                ]);
            }

            return ['cash_register' => CashRegister::query()->create([
                'status' => CashRegisterStatus::Open,
                'opening_amount' => $input['opening_amount'],
                'opened_at' => now(),
                'opened_by' => $input['actor_id'],
            ])];
        });
    }
}
