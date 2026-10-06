<?php

namespace App\Infrastructure\Services\Persistence;

use App\Application\Services\Contracts\ServiceRepository;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentServiceRepository implements ServiceRepository
{
    public function paginate(): LengthAwarePaginator
    {
        return Service::query()->orderBy('name')->paginate(15);
    }

    public function create(array $attributes): Service
    {
        return Service::query()->create($attributes);
    }

    public function update(Service $service, array $attributes): Service
    {
        $service->update($attributes);

        return $service->refresh();
    }

    public function delete(Service $service): void
    {
        $service->delete();
    }
}
