<?php

namespace App\Application\Customers\Actions;

use App\Application\Customers\Contracts\CustomerRepository;
use App\Application\Shared\Actions\Action;
use App\Models\Customer;

final class CreateCustomer extends Action
{
    public function __construct(private readonly CustomerRepository $customers) {}

    /**
     * @param  array{name: string, phone: string, notes?: string|null}  $input
     * @return array{customer: Customer}
     */
    protected function handle(array $input): array
    {
        return ['customer' => $this->customers->create($input)];
    }
}
