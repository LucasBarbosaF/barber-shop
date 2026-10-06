<?php

namespace App\Application\Customers\Actions;

use App\Application\Customers\Contracts\CustomerRepository;
use App\Application\Shared\Actions\Action;
use App\Models\Customer;

final class DeleteCustomer extends Action
{
    public function __construct(private readonly CustomerRepository $customers) {}

    /**
     * @param  array{customer: Customer}  $input
     * @return array<string, mixed>
     */
    protected function handle(array $input): array
    {
        $this->customers->delete($input['customer']);

        return [];
    }
}
