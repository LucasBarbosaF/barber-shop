<?php

namespace App\Infrastructure\Barbers\Persistence;

use App\Application\Barbers\Contracts\BarberRepository;
use App\Models\Barber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentBarberRepository implements BarberRepository
{
    public function paginate(): LengthAwarePaginator
    {
        return Barber::query()
            ->with(['user:id,name,email', 'services:id,name'])
            ->orderBy('name')
            ->paginate(15);
    }

    public function create(array $attributes): Barber
    {
        return Barber::query()->create($attributes);
    }

    public function update(Barber $barber, array $attributes): Barber
    {
        $barber->update($attributes);

        return $barber->refresh();
    }

    public function delete(Barber $barber): void
    {
        $barber->delete();
    }
}
