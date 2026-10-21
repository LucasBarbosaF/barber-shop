<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Recurrence\Contracts\RecurrencePlanRepository;
use App\Application\Shared\Actions\Action;
use App\Models\RecurrencePlan;

final class CreateRecurrencePlan extends Action
{
    public function __construct(private readonly RecurrencePlanRepository $plans) {}

    /**
     * @param  array{name: string, description?: string|null, service_id: int, duration_months: int, sessions_per_month: int, max_sessions_per_week?: int, price: string, is_active?: bool}  $input
     * @return array{plan: RecurrencePlan}
     */
    protected function handle(array $input): array
    {
        $input['max_sessions_per_week'] ??= 2;

        return ['plan' => $this->plans->create($input)];
    }
}
