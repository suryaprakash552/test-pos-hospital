<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One recorded loyalty activity (§14).
 *
 * Written, never rewritten. [record] is the only way in, and there is deliberately
 * no update path: an audit somebody can edit answers nothing, so a correction is a
 * further row rather than a change to this one.
 */
class LoyaltyAudit extends Model
{
    // §14's list of auditable activities.
    public const PACKAGE_CREATED = 'package.created';
    public const PACKAGE_UPDATED = 'package.updated';
    public const PACKAGE_WITHDRAWN = 'package.withdrawn';

    public const SUBSCRIBED = 'subscription.created';
    public const RENEWED = 'subscription.renewed';
    public const UPGRADED = 'subscription.upgraded';
    public const CANCELLED = 'subscription.cancelled';
    public const REFUNDED = 'subscription.refunded';
    public const EXPIRED = 'subscription.expired';

    public const MANUAL_DISCOUNT = 'discount.manual';
    public const DISCOUNT_REVERSED = 'discount.reversed';

    protected $fillable = [
        'business_id',
        'event',
        'auditable_type',
        'auditable_id',
        'customer_id',
        'field',
        'old_value',
        'new_value',
        'reason',
        'changed_by',
        'branch_id',
        'ip_address',
    ];

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Records an activity.
     *
     * The actor, branch and IP are taken from the request rather than passed in, so
     * a caller cannot accidentally omit the part that makes the record useful.
     *
     * @param Model|null $subject what the event happened to
     */
    public static function record(
        string $event,
        ?Model $subject = null,
        array $attributes = [],
    ): self {
        $user = auth()->user();

        return self::create($attributes + [
            'business_id'    => $attributes['business_id']
                ?? $subject?->business_id
                ?? $user?->business_id,
            'event'          => $event,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id'   => $subject?->getKey(),
            'changed_by'     => $user?->id,
            'branch_id'      => $user?->branch_id,
            'ip_address'     => request()?->ip(),
        ]);
    }

    /** Casts a value to something a text column can hold and a human can read. */
    public static function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
