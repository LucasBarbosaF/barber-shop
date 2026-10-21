<?php

namespace App\Infrastructure\Recurrence\Persistence;

use App\Application\Recurrence\Contracts\RecurrencePlanRepository;
use App\Models\RecurrencePlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentRecurrencePlanRepository implements RecurrencePlanRepository
{
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return RecurrencePlan::query()
            ->with('service:id,name')
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'ilike', "%{$search}%")
                        ->orWhereHas('service', fn ($service) => $service->where('name', 'ilike', "%{$search}%"));
                });
            })
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();
    }

    public function create(array $attributes): RecurrencePlan
    {
        return RecurrencePlan::query()->create($attributes);
    }

    public function update(RecurrencePlan $plan, array $attributes): RecurrencePlan
    {
        $plan->update($attributes);

        return $plan->refresh();
    }

    public function delete(RecurrencePlan $plan): void
    {
        $plan->delete();
    }
}
