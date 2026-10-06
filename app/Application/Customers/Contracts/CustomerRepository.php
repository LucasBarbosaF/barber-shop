<?php

namespace App\Application\Customers\Contracts;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerRepository
{
    public function paginate(): LengthAwarePaginator;

    public function create(array $attributes): Customer;

    public function update(Customer $customer, array $attributes): Customer;

    public function delete(Customer $customer): void;
}
