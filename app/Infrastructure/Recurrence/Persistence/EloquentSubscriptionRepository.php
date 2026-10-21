<?php

namespace App\Infrastructure\Recurrence\Persistence;

use App\Application\Recurrence\Contracts\RecurrenceSubscriptionRepository;
use App\Models\RecurrenceSubscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentSubscriptionRepository implements RecurrenceSubscriptionRepository
{
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return RecurrenceSubscription::query()
            ->with(['customer:id,name,phone', 'barber:id,name', 'service:id,name'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->whereHas('customer', fn ($customer) => $customer
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))
                        ->orWhere('plan_name', 'ilike', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();
    }

    public function create(array $attributes): RecurrenceSubscription
    {
        return RecurrenceSubscription::query()->create($attributes);
    }

    public function update(RecurrenceSubscription $subscription, array $attributes): RecurrenceSubscription
    {
        $subscription->update($attributes);

        return $subscription->refresh();
    }
}
