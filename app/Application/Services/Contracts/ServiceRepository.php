<?php

namespace App\Application\Services\Contracts;

use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ServiceRepository
{
    public function paginate(): LengthAwarePaginator;

    public function create(array $attributes): Service;

    public function update(Service $service, array $attributes): Service;

    public function delete(Service $service): void;
}
