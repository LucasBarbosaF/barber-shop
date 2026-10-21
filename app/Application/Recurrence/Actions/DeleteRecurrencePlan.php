<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Recurrence\Contracts\RecurrencePlanRepository;
use App\Application\Shared\Actions\Action;
use App\Models\RecurrencePlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteRecurrencePlan extends Action
{
    public function __construct(private readonly RecurrencePlanRepository $plans) {}

    /**
     * @param  array{plan: RecurrencePlan}  $input
     * @return array<string, mixed>
     */
    protected function handle(array $input): array
    {
        /*
         | `restrictOnDelete` recusa excluir plano que já tem assinatura, e a
         | violação de FK aborta a transação da requisição. O `DB::transaction`
         | aninhado vira savepoint: o rollback é da exclusão, não da página, e a
         | recusa vira mensagem amigável em vez de `25P02` no redirect seguinte.
         */
        try {
            DB::transaction(fn () => $this->plans->delete($input['plan']));
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23503') {
                throw ValidationException::withMessages([
                    'plan' => 'Este plano possui assinaturas e não pode ser excluído. Inative-o em vez de excluir.',
                ]);
            }

            throw $exception;
        }

        return [];
    }
}
