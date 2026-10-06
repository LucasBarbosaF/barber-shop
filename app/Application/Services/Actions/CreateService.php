<?php

namespace App\Application\Services\Actions;

use App\Application\Services\Contracts\ServiceRepository;
use App\Application\Shared\Actions\Action;
use App\Models\Service;

final class CreateService extends Action
{
    public function __construct(private readonly ServiceRepository $services) {}

    /**
     * @param  array{name: string, description?: string|null, duration_minutes: int, price: string, is_active?: bool}  $input
     * @return array{service: Service}
     */
    protected function handle(array $input): array
    {
        return ['service' => $this->services->create($input)];
    }
}
