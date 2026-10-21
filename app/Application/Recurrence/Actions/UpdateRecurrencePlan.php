<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Recurrence\Contracts\RecurrencePlanRepository;
use App\Application\Shared\Actions\Action;
use App\Models\RecurrencePlan;

final class UpdateRecurrencePlan extends Action
{
    public function __construct(private readonly RecurrencePlanRepository $plans) {}

    /**
     * @param  array{plan: RecurrencePlan, attributes: array<string, mixed>}  $input
     * @return array{plan: RecurrencePlan}
     */
    protected function handle(array $input): array
    {
        return [
            'plan' => $this->plans->update($input['plan'], $input['attributes']),
        ];
    }
}
