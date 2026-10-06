<?php

namespace App\Application\Shared\Actions;

use App\Application\Contracts\UseCase;

/**
 * Template base for Actions / Use Cases.
 *
 * Usage:
 *   final class CreateCustomer extends Action
 *   {
 *       protected function handle(array $input): array
 *       {
 *           // business logic here
 *       }
 *   }
 */
abstract class Action implements UseCase
{
    final public function execute(array $input): array
    {
        return $this->handle($input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    abstract protected function handle(array $input): array;
}
