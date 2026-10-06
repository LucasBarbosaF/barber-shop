<?php

namespace App\Application\Barbers\Contracts;

use App\Models\Barber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface BarberRepository
{
    public function paginate(): LengthAwarePaginator;

    public function create(array $attributes): Barber;

    public function update(Barber $barber, array $attributes): Barber;

    public function delete(Barber $barber): void;
}
