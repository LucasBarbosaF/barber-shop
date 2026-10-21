<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Recurrence\Contracts\RecurrenceSubscriptionRepository;
use App\Application\Shared\Actions\Action;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListSubscriptions extends Action
{
    public function __construct(private readonly RecurrenceSubscriptionRepository $subscriptions) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{subscriptions: LengthAwarePaginator}
     */
    protected function handle(array $input): array
    {
        return ['subscriptions' => $this->subscriptions->paginate($input)];
    }
}
