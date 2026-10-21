<?php

namespace App\Application\Recurrence\Contracts;

use App\Models\RecurrenceSubscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RecurrenceSubscriptionRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): RecurrenceSubscription;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(RecurrenceSubscription $subscription, array $attributes): RecurrenceSubscription;
}
