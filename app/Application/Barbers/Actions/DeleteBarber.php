<?php

namespace App\Application\Barbers\Actions;

use App\Application\Barbers\Contracts\BarberRepository;
use App\Application\Shared\Actions\Action;
use App\Models\Barber;

final class DeleteBarber extends Action
{
    public function __construct(private readonly BarberRepository $barbers) {}

    /**
     * @param  array{barber: Barber}  $input
     * @return array<string, mixed>
     */
    protected function handle(array $input): array
    {
        $this->barbers->delete($input['barber']);

        return [];
    }
}
