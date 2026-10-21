<?php

namespace App\Application\Recurrence\Contracts;

use App\Models\RecurrencePlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RecurrencePlanRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator;

    public function create(array $attributes): RecurrencePlan;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(RecurrencePlan $plan, array $attributes): RecurrencePlan;

    public function delete(RecurrencePlan $plan): void;
}
