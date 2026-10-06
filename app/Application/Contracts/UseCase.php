<?php

namespace App\Application\Contracts;

interface UseCase
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array;
}
