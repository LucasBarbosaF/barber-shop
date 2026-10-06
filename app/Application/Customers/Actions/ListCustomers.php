<?php

namespace App\Application\Customers\Actions;

use App\Application\Customers\Contracts\CustomerRepository;
use App\Application\Shared\Actions\Action;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListCustomers extends Action
{
    public function __construct(private readonly CustomerRepository $customers) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{customers: LengthAwarePaginator}
     */
    protected function handle(array $input): array
    {
        return ['customers' => $this->customers->paginate()];
    }
}
