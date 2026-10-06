<?php

namespace App\Application\Barbers\Actions;

use App\Application\Barbers\Contracts\BarberRepository;
use App\Application\Shared\Actions\Action;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListBarbers extends Action
{
    public function __construct(private readonly BarberRepository $barbers) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{barbers: LengthAwarePaginator}
     */
    protected function handle(array $input): array
    {
        return ['barbers' => $this->barbers->paginate()];
    }
}
