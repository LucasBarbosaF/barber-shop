<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Recurrence\Contracts\RecurrencePlanRepository;
use App\Application\Shared\Actions\Action;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListRecurrencePlans extends Action
{
    public function __construct(private readonly RecurrencePlanRepository $plans) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{plans: LengthAwarePaginator}
     */
    protected function handle(array $input): array
    {
        return ['plans' => $this->plans->paginate($input)];
    }
}
