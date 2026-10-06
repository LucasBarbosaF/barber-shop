<?php

use App\Domain\Shared\ValueObjects\Money;

it('creates valid money from string amount', function () {
    $money = new Money('10.50');

    expect($money->amount)->toBe('10.50');
    expect($money->currency)->toBe('BRL');
});

it('rejects invalid monetary amount', function () {
    new Money('10.5');
})->throws(InvalidArgumentException::class);

it('adds money values with precision', function () {
    $a = new Money('10.10');
    $b = new Money('2.05');

    expect($a->add($b)->amount)->toBe('12.15');
});

it('subtracts money values with precision', function () {
    $a = new Money('10.00');
    $b = new Money('3.33');

    expect($a->subtract($b)->amount)->toBe('6.67');
});

it('calculates percentage commission', function () {
    $base = new Money('100.00');

    expect($base->percentage('30')->amount)->toBe('30.00');
});

it('compares money values', function () {
    expect((new Money('10.00'))->equals(new Money('10.00')))->toBeTrue();
    expect((new Money('10.00'))->equals(new Money('10.01')))->toBeFalse();
});
