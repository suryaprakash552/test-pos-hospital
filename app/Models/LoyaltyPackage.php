<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A loyalty package a customer can buy (§2).
 *
 * Unlike [CustomerTier], which is only a label, this changes what a customer is
 * charged — so it is bought, dated, and versioned onto each subscription it sells.
 */
class LoyaltyPackage extends Model
{
    use HasFactory;
    use BelongsToBusiness;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'description',
        'cost',
        'validity_type',
        'validity_period',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'discount_mode',
        'renewal_allowed',
        'upgrade_allowed',
        'priority',
        'effective_start_date',
        'effective_end_date',
        'status',
        'created_by',
        'modified_by',
    ];

    /**
     * Defaults mirrored from the migration.
     *
     * A column default is applied by the database, so a freshly created model has
     * null for anything the caller omitted until it is re-read. Reading
     * `upgrade_allowed` straight after `create()` then returns null, which casts to
     * false and refuses an upgrade the package actually permits.
     */
    protected $attributes = [
        'cost'            => 0,
        'validity_type'   => 'days',
        'validity_period' => 0,
        'discount_type'   => 'percentage',
        'discount_value'  => 0,
        'discount_mode'   => 'automatic',
        'renewal_allowed' => true,
        'upgrade_allowed' => true,
        'priority'        => 0,
        'status'          => true,
    ];

    protected $casts = [
        'cost'                 => 'decimal:2',
        'discount_value'       => 'decimal:2',
        // Deliberately nullable: null is "no ceiling" (§6.4), which is not zero.
        'max_discount_amount'  => 'decimal:2',
        'validity_period'      => 'integer',
        'priority'             => 'integer',
        'renewal_allowed'      => 'boolean',
        'upgrade_allowed'      => 'boolean',
        'status'               => 'boolean',
        'effective_start_date' => 'date',
        'effective_end_date'   => 'date',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(LoyaltySubscription::class);
    }

    /**
     * Whether this package can be sold to someone today (§2.2).
     *
     * Distinct from `status`: a package can be Active yet outside its effective
     * window, in which case it exists but is not on sale.
     */
    public function isAssignableOn(?string $date = null): bool
    {
        if (! $this->status) {
            return false;
        }

        $day = \Illuminate\Support\Carbon::parse($date ?? now())->startOfDay();

        if ($this->effective_start_date && $day->lessThan($this->effective_start_date)) {
            return false;
        }

        if ($this->effective_end_date && $day->greaterThan($this->effective_end_date)) {
            return false;
        }

        return true;
    }

    /**
     * The discount configuration, copied onto a subscription at the point of sale.
     *
     * §11: a later edit to this package must not change what an existing customer
     * gets, so the values travel with the subscription rather than being read back.
     *
     * @return array<string, mixed>
     */
    public function discountSnapshot(): array
    {
        return [
            'discount_type'       => $this->discount_type,
            'discount_value'      => (float) $this->discount_value,
            'max_discount_amount' => $this->max_discount_amount === null
                ? null
                : (float) $this->max_discount_amount,
            'discount_mode'       => $this->discount_mode,
        ];
    }

    /** Reasons this package cannot be saved, keyed by field. Empty means valid. */
    public static function validationErrors(array $attributes): array
    {
        $errors = [];

        if (($attributes['cost'] ?? 0) < 0) {
            $errors['cost'] = 'Package cost cannot be negative.';
        }

        if (($attributes['discount_value'] ?? 0) < 0) {
            $errors['discount_value'] = 'Discount value cannot be negative.';
        }

        if (($attributes['discount_type'] ?? null) === LoyaltyDiscount::PERCENTAGE
            && ($attributes['discount_value'] ?? 0) > 100
        ) {
            $errors['discount_value'] = 'A percentage discount cannot exceed 100%.';
        }

        if (! LoyaltyDiscount::isValidType($attributes['discount_type'] ?? null)) {
            $errors['discount_type'] = 'Discount type must be percentage or fixed.';
        }

        if (! LoyaltyDiscount::isValidMode($attributes['discount_mode'] ?? null)) {
            $errors['discount_mode'] = 'Discount mode must be automatic or manual.';
        }

        if (! LoyaltyPeriod::isValidValidityType($attributes['validity_type'] ?? null)) {
            $errors['validity_type'] = 'Validity type must be days, months or years.';
        }

        $start = $attributes['effective_start_date'] ?? null;
        $end   = $attributes['effective_end_date'] ?? null;

        if ($start && $end && $end < $start) {
            $errors['effective_end_date'] =
                'Effective end date cannot be earlier than the effective start date.';
        }

        return $errors;
    }
}
