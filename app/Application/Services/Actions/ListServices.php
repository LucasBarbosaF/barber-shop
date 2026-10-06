<?php

namespace App\Application\Services\Actions;

use App\Application\Services\Contracts\ServiceRepository;
use App\Application\Shared\Actions\Action;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListServices extends Action
{
    public function __construct(private readonly ServiceRepository $services) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{services: LengthAwarePaginator}
     */
    protected function handle(array $input): array
    {
        return ['services' => $this->services->paginate()];
    }
}
