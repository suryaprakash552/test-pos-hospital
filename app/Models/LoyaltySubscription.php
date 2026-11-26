<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's term on one loyalty package (§3).
 *
 * A row is never rewritten. Renewing or upgrading marks this one and inserts a new
 * one pointing back at it, so §11's requirement that the whole history survives is a
 * property of the table rather than something the application has to remember.
 *
 * The discount fields are a copy taken when the package was sold, not a view onto
 * [LoyaltyPackage] — see [LoyaltyPackage::discountSnapshot].
 */
class LoyaltySubscription extends Model
{
    use HasFactory;
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'customer_id',
        'loyalty_package_id',
        'subscription_date',
        'start_date',
        'end_date',
        'status',
        'package_cost',
        'amount_received',
        'refund_amount',
        'refunded_at',
        'refunded_by',
        'refund_reason',
        'payment_mode',
        'payment_reference',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'discount_mode',
        'previous_subscription_id',
        'origin',
        'adjustment_amount',
        'change_reason',
        'created_by',
        'branch_id',
    ];

    protected $casts = [
        'subscription_date'   => 'date',
        'start_date'          => 'date',
        'end_date'            => 'date',
        'package_cost'        => 'decimal:2',
        'amount_received'     => 'decimal:2',
        'refund_amount'       => 'decimal:2',
        'refunded_at'         => 'datetime',
        'adjustment_amount'   => 'decimal:2',
        'discount_value'      => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LoyaltyPackage::class, 'loyalty_package_id');
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_subscription_id');
    }

    /** Subscriptions still granting benefits on [$date]. */
    public function scopeCurrent(Builder $query, ?string $date = null): Builder
    {
        $day = \Illuminate\Support\Carbon::parse($date ?? now())->toDateString();

        return $query->where('status', LoyaltyPeriod::ACTIVE)
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day);
    }

    /**
     * Whether this subscription grants a discount right now.
     *
     * Status *and* dates: a cancelled subscription can still be inside its term and
     * must not pay out, and an active one past its end date must not either.
     */
    public function isCurrentlyValid(?string $onDate = null): bool
    {
        return LoyaltyPeriod::isCurrentlyValid(
            $this->status,
            $this->start_date->toDateString(),
            $this->end_date->toDateString(),
            $onDate,
        );
    }

    public function hasExpired(?string $onDate = null): bool
    {
        return LoyaltyPeriod::hasExpired($this->end_date->toDateString(), $onDate);
    }

    public function remainingDays(?string $onDate = null): int
    {
        return LoyaltyPeriod::remainingDays($this->end_date->toDateString(), $onDate);
    }

    /** Whole days the term runs for, used to pro-rata an upgrade (§4.2). */
    public function totalDays(): int
    {
        return $this->start_date->startOfDay()
            ->diffInDays($this->end_date->startOfDay()) + 1;
    }

    /**
     * The discount this subscription grants on an eligible amount.
     *
     * Returns 0 when the term is not current, so an expired customer is charged as
     * a non-loyalty customer (§7) without the caller having to remember to check.
     */
    public function discountFor(float $eligibleAmount, ?string $onDate = null): float
    {
        if (! $this->isCurrentlyValid($onDate)) {
            return 0.0;
        }

        return LoyaltyDiscount::calculate(
            $eligibleAmount,
            $this->discount_type,
            (float) $this->discount_value,
            $this->max_discount_amount === null ? null : (float) $this->max_discount_amount,
        );
    }
}
