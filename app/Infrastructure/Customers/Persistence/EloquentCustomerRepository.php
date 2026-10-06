<?php

namespace App\Infrastructure\Customers\Persistence;

use App\Application\Customers\Contracts\CustomerRepository;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentCustomerRepository implements CustomerRepository
{
    public function paginate(): LengthAwarePaginator
    {
        return Customer::query()->orderBy('name')->paginate(15);
    }

    public function create(array $attributes): Customer
    {
        return Customer::query()->create($attributes);
    }

    public function update(Customer $customer, array $attributes): Customer
    {
        $customer->update($attributes);

        return $customer->refresh();
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }
}
