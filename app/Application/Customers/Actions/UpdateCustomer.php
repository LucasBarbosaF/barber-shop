<?php

namespace App\Application\Customers\Actions;

use App\Application\Customers\Contracts\CustomerRepository;
use App\Application\Shared\Actions\Action;
use App\Models\Customer;

final class UpdateCustomer extends Action
{
    public function __construct(private readonly CustomerRepository $customers) {}

    /**
     * @param  array{customer: Customer, attributes: array{name?: string, phone?: string, notes?: string|null}}  $input
     * @return array{customer: Customer}
     */
    protected function handle(array $input): array
    {
        return [
            'customer' => $this->customers->update($input['customer'], $input['attributes']),
        ];
    }
}
