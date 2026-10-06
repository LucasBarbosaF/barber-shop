<?php

namespace App\Application\Barbers\Actions;

use App\Application\Barbers\Contracts\BarberRepository;
use App\Application\Shared\Actions\Action;
use App\Models\Barber;

final class CreateBarber extends Action
{
    public function __construct(private readonly BarberRepository $barbers) {}

    /**
     * @param  array{name: string, phone: string, email?: string|null, bio?: string|null, is_active?: bool, user_id?: int|null}  $input
     * @return array{barber: Barber}
     */
    protected function handle(array $input): array
    {
        return ['barber' => $this->barbers->create($input)];
    }
}
