<?php

namespace App\Domain\Shared\ValueObjects;

final readonly class Money
{
    public function __construct(
        public string $amount,
        public string $currency = 'BRL',
    ) {
        if (! preg_match('/^-?\d+\.\d{2}$/', $amount)) {
            throw new \InvalidArgumentException("Invalid monetary amount: {$amount}");
        }
    }

    public function add(self $other): self
    {
        return new self(
            bcadd($this->amount, $other->amount, 2),
            $this->currency,
        );
    }

    public function subtract(self $other): self
    {
        return new self(
            bcsub($this->amount, $other->amount, 2),
            $this->currency,
        );
    }

    public function multiply(string $factor): self
    {
        return new self(
            bcmul($this->amount, $factor, 2),
            $this->currency,
        );
    }

    public function percentage(string $percent): self
    {
        return $this->multiply(bcdiv($percent, '100', 4));
    }

    public function equals(self $other): bool
    {
        return bccomp($this->amount, $other->amount, 2) === 0
            && $this->currency === $other->currency;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0.00', 2) < 0;
    }

    public function format(): string
    {
        $isNegative = str_starts_with($this->amount, '-')
            && preg_match('/[1-9]/', $this->amount) === 1;
        $absoluteAmount = str_starts_with($this->amount, '-')
            ? substr($this->amount, 1)
            : $this->amount;
        [$whole, $fraction] = explode('.', $absoluteAmount, 2);
        $groupedWhole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);

        if ($groupedWhole === null) {
            throw new \RuntimeException('Unable to format money amount.');
        }

        return ($isNegative ? '-' : '').$groupedWhole.','.$fraction;
    }

    public function __toString(): string
    {
        return $this->amount;
    }
}
