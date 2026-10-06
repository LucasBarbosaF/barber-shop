<?php

namespace App\Application\Services\Actions;

use App\Application\Services\Contracts\ServiceRepository;
use App\Application\Shared\Actions\Action;
use App\Models\Service;

final class DeleteService extends Action
{
    public function __construct(private readonly ServiceRepository $services) {}

    /**
     * @param  array{service: Service}  $input
     * @return array<string, mixed>
     */
    protected function handle(array $input): array
    {
        $this->services->delete($input['service']);

        return [];
    }
}
