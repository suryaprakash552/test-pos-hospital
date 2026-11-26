<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The primitive every total in the system is built from.
 *
 * `Money` holds integer minor units so a bill cannot drift the way a float
 * does, and it had no test of its own — every assertion about rounding,
 * capping or tax reached it through an HTTP round-trip in a feature test. The
 * defects this system has actually shipped were rounding and apportionment
 * defects, so this is the layer that most needs pinning directly.
 *
 * Extends the framework's TestCase rather than PHPUnit's bare one because
 * `Money::scale()` reads the currency precision from config, so the container
 * has to be booted.
 */
class MoneyTest extends TestCase
{
    public function test_a_float_becomes_exact_minor_units(): void
    {
        $this->assertSame(1234, Money::fromFloat(12.34)->toMinor());
    }

    /**
     * The case the class exists for: 19.999999999999996 is what a float
     * arrives as after arithmetic, and truncating it bills ₹19.99 for ₹20.
     */
    public function test_a_float_that_is_almost_a_round_number_rounds_up(): void
    {
        $this->assertSame(2000, Money::fromFloat(19.999999999999996)->toMinor());
    }

    public function test_half_a_paisa_rounds_half_up(): void
    {
        $this->assertSame(1235, Money::fromFloat(12.345)->toMinor());
    }

    public function test_a_numeric_string_is_accepted(): void
    {
        $this->assertSame(999, Money::fromFloat('9.99')->toMinor());
    }

    public function test_a_non_numeric_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromFloat('not money');
    }

    public function test_an_infinite_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromFloat(INF);
    }

    public function test_nan_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromFloat(NAN);
    }

    public function test_addition_is_exact(): void
    {
        $a = Money::fromFloat(0.1);
        $b = Money::fromFloat(0.2);

        // The canonical float trap: 0.1 + 0.2 != 0.3 in binary floating point.
        $this->assertSame(30, $a->plus($b)->toMinor());
        $this->assertSame(0.3, $a->plus($b)->toFloat());
    }

    public function test_many_small_amounts_sum_without_drift(): void
    {
        $total = Money::zero();
        for ($i = 0; $i < 1000; $i++) {
            $total = $total->plus(Money::fromFloat(0.01));
        }

        $this->assertSame(1000, $total->toMinor(), '1000 x 1 paisa is exactly ₹10');
    }

    public function test_a_fractional_quantity_multiplies_exactly(): void
    {
        $this->assertSame(618, Money::fromFloat(12.35)->times(0.5)->toMinor());
    }

    public function test_subtraction_can_go_negative(): void
    {
        $result = Money::fromFloat(5)->minus(Money::fromFloat(8));

        $this->assertTrue($result->isNegative());
        $this->assertSame(-300, $result->toMinor());
    }

    public function test_a_percentage_is_taken_at_full_precision(): void
    {
        $this->assertSame(1800, Money::fromFloat(100)->percentage(18)->toMinor());
    }

    /**
     * ₹118 including 18% contains ₹18 of tax, leaving ₹100 net — the identity
     * every inclusive line depends on.
     */
    public function test_inclusive_tax_is_carved_out_correctly(): void
    {
        $gross = Money::fromFloat(118);
        $tax = $gross->taxIncludedAt(18);

        $this->assertSame(1800, $tax->toMinor());
        $this->assertSame(10000, $gross->minus($tax)->toMinor());
    }

    public function test_inclusive_tax_at_zero_percent_is_nothing(): void
    {
        $this->assertTrue(Money::fromFloat(118)->taxIncludedAt(0)->isZero());
    }

    public function test_clamping_stops_a_discount_inverting_a_line(): void
    {
        $this->assertTrue(
            Money::fromFloat(10)->minus(Money::fromFloat(25))->clampToZero()->isZero(),
        );
    }

    public function test_clamping_leaves_a_positive_amount_alone(): void
    {
        $this->assertSame(1000, Money::fromFloat(10)->clampToZero()->toMinor());
    }

    public function test_a_cap_limits_an_amount_to_its_ceiling(): void
    {
        $this->assertSame(
            7000,
            Money::fromFloat(500)->cappedAt(Money::fromFloat(70))->toMinor(),
        );
    }

    public function test_a_cap_leaves_an_amount_below_it_alone(): void
    {
        $this->assertSame(
            5000,
            Money::fromFloat(50)->cappedAt(Money::fromFloat(70))->toMinor(),
        );
    }

    /** @dataProvider provideRounding */
    public function test_rounding_modes(string $option, float $amount, int $expectedMinor): void
    {
        $this->assertSame(
            $expectedMinor,
            Money::fromFloat($amount)->rounded($option)->toMinor(),
        );
    }

    /** @return array<string, array{0: string, 1: float, 2: int}> */
    public static function provideRounding(): array
    {
        return [
            'none leaves it alone' => ['none', 72.74, 7274],
            'round_up ceilings' => ['round_up', 72.01, 7300],
            'round_up leaves a whole amount' => ['round_up', 72.00, 7200],
            'round_down floors' => ['round_down', 72.99, 7200],
            'round_down leaves a whole amount' => ['round_down', 72.00, 7200],
            'nearest goes down below half' => ['nearest_whole_number', 72.40, 7200],
            'nearest goes up above half' => ['nearest_whole_number', 72.74, 7300],
            'nearest 0.05' => ['nearest_0.05', 72.73, 7275],
            'nearest 0.10' => ['nearest_0.1', 72.74, 7270],
            'nearest 0.50' => ['nearest_0.5', 72.74, 7250],
            'an unknown option rounds nothing' => ['whatever', 72.74, 7274],
        ];
    }

    /** ₹32.81 refunds ₹32 — the 19 paise was never paid by the customer. */
    public function test_a_refund_sized_amount_floors_to_the_rupee(): void
    {
        $this->assertSame(3200, Money::fromFloat(32.81)->rounded('round_down')->toMinor());
    }

    public function test_equality_compares_value_not_identity(): void
    {
        $this->assertTrue(Money::fromFloat(10)->equals(Money::fromFloat(10.00)));
        $this->assertFalse(Money::fromFloat(10)->equals(Money::fromFloat(10.01)));
    }

    public function test_greater_than_is_strict(): void
    {
        $this->assertTrue(Money::fromFloat(10.01)->greaterThan(Money::fromFloat(10)));
        $this->assertFalse(Money::fromFloat(10)->greaterThan(Money::fromFloat(10)));
    }

    public function test_a_difference_is_always_the_gap_between_two_amounts(): void
    {
        $this->assertSame(
            300,
            Money::fromFloat(5)->differenceFrom(Money::fromFloat(8))->toMinor(),
        );
    }
}
