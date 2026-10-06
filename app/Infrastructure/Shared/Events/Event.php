<?php

namespace App\Infrastructure\Shared\Events;

/**
 * Base class for application events.
 *
 * Convention:
 * - Domain events live in app/Domain/{Module}/Events/
 * - Application events live in app/Application/{Module}/Events/
 * - Listeners live in app/Infrastructure/{Module}/Listeners/
 */
abstract class Event {}
