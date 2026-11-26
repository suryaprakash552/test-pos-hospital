<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money as an integer count of minor units (paise/cents).
 *
 * Authoritative totals must not be built out of floats. `0.1 + 0.2` is not `0.3`,
 * and a bill assembled from a few dozen float operations can settle a paisa away
 * from what the customer is charged - which then fails a split-payment equality
 * check, or leaves a due of 0.004 that never reads as paid.
 *
 * Everything inside a calculation stays an integer. Floats appear only at the
 * edges: reading a price column, and writing the finished total back out.
 */
final class Money
{
    private function __construct(private readonly int $minorUnits)
    {
    }

    /**
     * Minor units per major unit, e.g. 100 for two decimal places.
     */
    public static function scale(): int
    {
        return 10 ** (int) config('pos.currency.decimal_places', 2);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromMinor(int $minorUnits): self
    {
        return new self($minorUnits);
    }

    /**
     * Convert a major-unit amount (a price column, a request field) to money.
     *
     * Rounds half-up at the currency's precision: the input is already a float
     * and may be 19.999999999999996 rather than 20.
     */
    public static function fromFloat(float|int|string $amount): self
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Amount must be numeric.');
        }

        $amount = (float) $amount;

        if (! is_finite($amount)) {
            throw new InvalidArgumentException('Amount must be a finite number.');
        }

        return new self((int) round($amount * self::scale()));
    }

    public function plus(self $other): self
    {
        return new self($this->minorUnits + $other->minorUnits);
    }

    public function minus(self $other): self
    {
        return new self($this->minorUnits - $other->minorUnits);
    }

    /**
     * Multiply by a quantity, which may legitimately be fractional (0.5 kg).
     */
    public function times(float|int $multiplier): self
    {
        return new self((int) round($this->minorUnits * $multiplier));
    }

    /**
     * A percentage of this amount, e.g. `percentage(18.0)` for 18% GST.
     */
    public function percentage(float $percent): self
    {
        return new self((int) round($this->minorUnits * $percent / 100));
    }

    /**
     * The tax contained within a tax-inclusive amount at the given rate.
     *
     * total = net * (1 + r), so the tax part is total - total / (1 + r).
     */
    public function taxIncludedAt(float $percent): self
    {
        if ($percent <= 0) {
            return self::zero();
        }

        $divisor = 1 + ($percent / 100);

        return new self($this->minorUnits - (int) round($this->minorUnits / $divisor));
    }

    /**
     * Never below zero - a discount larger than the line must not invert it.
     */
    public function clampToZero(): self
    {
        return $this->minorUnits < 0 ? self::zero() : $this;
    }

    /**
     * The smaller of the two, used to cap a discount at the amount it applies to.
     */
    public function cappedAt(self $ceiling): self
    {
        return $this->minorUnits > $ceiling->minorUnits ? $ceiling : $this;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->minorUnits > $other->minorUnits;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }

    /**
     * Absolute difference, for tolerance comparisons against a client figure.
     */
    public function differenceFrom(self $other): self
    {
        return new self(abs($this->minorUnits - $other->minorUnits));
    }

    public function toMinor(): int
    {
        return $this->minorUnits;
    }

    /**
     * Back to a major-unit float, for persisting to the existing money columns.
     */
    public function toFloat(): float
    {
        return $this->minorUnits / self::scale();
    }

    /**
     * Apply one of the configured rounding modes, returning the rounded amount.
     *
     * Mirrors `config('pos.rounding.options')`; an unknown mode rounds nothing,
     * so a bad value cannot quietly change what a customer pays.
     */
    public function rounded(string $option): self
    {
        $scale = self::scale();

        return match ($option) {
            'round_up' => new self((int) (ceil($this->minorUnits / $scale) * $scale)),
            'round_down' => new self((int) (floor($this->minorUnits / $scale) * $scale)),
            'nearest_whole_number' => new self((int) (round($this->minorUnits / $scale) * $scale)),
            'nearest_0.05' => $this->toNearestStep((int) round(0.05 * $scale)),
            'nearest_0.1' => $this->toNearestStep((int) round(0.1 * $scale)),
            'nearest_0.5' => $this->toNearestStep((int) round(0.5 * $scale)),
            default => $this,
        };
    }

    private function toNearestStep(int $step): self
    {
        if ($step <= 0) {
            return $this;
        }

        return new self((int) (round($this->minorUnits / $step) * $step));
    }
}
