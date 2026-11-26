<?php

namespace App\Services;

use App\Models\Business;
use App\Models\GstConfigurationHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves which GST pricing method applies, and validates a branch's GST identity.
 *
 * ## Resolution order
 *
 * Most specific wins:
 *   1. the item's own override, where one is allowed
 *   2. the branch's configuration
 *   3. the shop type's default
 *   4. `GstPricingMethod::FALLBACK`
 *
 * Step 4 is deliberately a value rather than an error. The specification says an
 * unresolved configuration should stop the transaction, and for a *registered* shop
 * that is right — but applying it to every shop would break every existing business
 * overnight, since none of them have configured anything. [resolveStrict] is the
 * version that refuses; [resolve] is the one safe to call from existing code paths.
 *
 * ## Scope
 *
 * Configuration only. Nothing here splits a tax total into CGST/SGST/IGST — that
 * changes what is charged and printed, and belongs in its own pass with its own
 * verification. [GstPricingMethod::split] gives the taxable/tax pair those components
 * would be derived from.
 */
class GstConfiguration
{
    /**
     * India's GSTIN: 2-digit state code, 10-character PAN, entity number, a literal
     * 'Z', then a checksum character.
     */
    public const GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    /**
     * The method to bill under, never null.
     *
     * @param string|null $itemOverride the product's own vat_type, when set
     * @param object|null $branch       a branch record, when the shop has branches
     */
    public static function resolve(
        ?string $itemOverride,
        ?object $branch,
        ?Business $business,
    ): string {
        // Registration is the master switch. A product's saved tax method says
        // how GST is priced when GST applies; it must never make an explicitly
        // unregistered shop charge tax.
        $registered = $branch?->gst_registered ?? $business?->gst_registered;
        if ($registered === false || $registered === 0) {
            return GstPricingMethod::NOT_APPLICABLE;
        }

        if (GstPricingMethod::isValid($itemOverride)) {
            return $itemOverride;
        }

        if ($branch !== null && GstPricingMethod::isValid($branch->default_vat_type ?? null)) {
            return $branch->default_vat_type;
        }

        if ($business !== null) {
            if (GstPricingMethod::isValid($business->default_vat_type)) {
                return $business->default_vat_type;
            }

            $shopType = $business->relationLoaded('category')
                ? $business->category?->default_vat_type
                : $business->category()->value('default_vat_type');

            if (GstPricingMethod::isValid($shopType)) {
                return $shopType;
            }
        }

        return GstPricingMethod::FALLBACK;
    }

    /**
     * Same resolution, but refuses to guess for a GST-registered shop.
     *
     * A registered business bills under a method that appears on a legal document, so
     * silently defaulting there is worse than stopping. Unregistered shops still get an
     * answer, because "no GST" is a complete answer.
     *
     * @throws \RuntimeException when a registered shop has nothing configured
     */
    public static function resolveStrict(
        ?string $itemOverride,
        ?object $branch,
        ?Business $business,
    ): string {
        $isRegistered = ($branch->gst_registered ?? $business?->gst_registered) === true;

        $configured = GstPricingMethod::isValid($itemOverride)
            || GstPricingMethod::isValid($branch->default_vat_type ?? null)
            || GstPricingMethod::isValid($business?->default_vat_type)
            || GstPricingMethod::isValid(
                $business?->relationLoaded('category')
                    ? $business->category?->default_vat_type
                    : $business?->category()->value('default_vat_type')
            );

        if ($isRegistered && ! $configured) {
            throw new \RuntimeException(
                'GST pricing configuration is unavailable. Contact the administrator.'
            );
        }

        return self::resolve($itemOverride, $branch, $business);
    }

    /**
     * The method that applied on a given date.
     *
     * A change carries an effective date, and until that date arrives the shop is
     * still billing under the old method. Resolving as of "now" would apply a
     * future-dated change immediately — the exact thing the effective date exists to
     * prevent, and the reason a quarter's invoices could otherwise disagree with the
     * return filed for it.
     *
     * The previous value is read back from the configuration history, which is why
     * that table records `old_value` rather than only the new one.
     *
     * @param string $onDate the transaction's own date, not today's
     */
    public static function resolveAsOf(
        ?string $itemOverride,
        ?object $branch,
        ?Business $business,
        string $onDate,
    ): string {
        // Resolve once before looking at history. This preserves the inventory
        // item's explicit method, but also lets the registration master switch
        // suppress it when the shop is not registered.
        $current = self::resolve($itemOverride, $branch, $business);

        // An item's own setting is not effective-dated: it is a property of the
        // product, not a policy that changes on a schedule. An explicitly
        // unregistered shop is also always not-applicable. Do not use `$current
        // === not_applicable` as that signal: it can be the *new*, future-dated
        // business method, in which case history still has to restore the old
        // method for an earlier invoice.
        $registered = $branch?->gst_registered ?? $business?->gst_registered;
        if (GstPricingMethod::isValid($itemOverride)
            || $registered === false
            || $registered === 0
        ) {
            return $current;
        }

        if ($business === null) {
            return $current;
        }

        // The app sends a full timestamp for a sale but a plain date for a purchase,
        // and comparing a DATE column against a timestamp string is not reliable.
        // An unparseable date means today, matching the callers' own fallback.
        try {
            $onDate = \Illuminate\Support\Carbon::parse($onDate)->toDateString();
        } catch (\Throwable) {
            $onDate = now()->toDateString();
        }

        // The earliest pending change that had not taken effect by $onDate. Its
        // `old_value` is what the shop was billing under at the time.
        $pending = GstConfigurationHistory::where('business_id', $business->id)
            ->where('field', 'default_vat_type')
            ->whereNotNull('effective_from')
            ->whereDate('effective_from', '>', $onDate)
            ->orderBy('effective_from')
            ->first();

        if ($pending === null) {
            return $current;
        }

        return GstPricingMethod::isValid($pending->old_value)
            ? $pending->old_value
            // The shop had nothing configured before the change, so it was on the
            // same fallback every unconfigured business uses.
            : GstPricingMethod::FALLBACK;
    }

    /**
     * §19: backdating a change into a filed period is its own permission.
     *
     * Separate from changing the configuration at all, because it contradicts a
     * return that has already gone out rather than merely affecting future ones.
     */
    public const BACKDATE_PERMISSION = 'gst.backdate';

    /** §18, verbatim, because it is what the user is shown. */
    public const CLOSED_PERIOD_MESSAGE =
        'The effective date cannot be earlier than the closed accounting period.';

    /**
     * Whether an effective date reaches back into a period already filed.
     *
     * A change dated into a closed period would mean invoices already reported
     * under one method are retrospectively said to have used another — the returns
     * and the ledger would no longer agree, and the discrepancy surfaces at
     * assessment rather than here.
     *
     * A shop that has never closed a period is unaffected, which is every existing
     * shop.
     */
    public static function isBeforeClosedPeriod(
        ?string $effectiveFrom,
        ?Business $business,
    ): bool {
        if (blank($effectiveFrom) || $business?->books_closed_upto === null) {
            return false;
        }

        try {
            $date = \Illuminate\Support\Carbon::parse($effectiveFrom)->startOfDay();
        } catch (\Throwable) {
            return false;
        }

        // The closing date is itself closed, so a change must take effect the day
        // after it at the earliest.
        return $date->lessThanOrEqualTo(
            $business->books_closed_upto->copy()->startOfDay()
        );
    }

    /**
     * Whether this user may date a change into a closed period.
     *
     * §17 allows it "unless the user has special permission", so the owner — who
     * carries every permission — can still correct a genuine mistake.
     */
    public static function mayBackdate(?object $user): bool
    {
        return method_exists($user, 'hasPermission')
            && $user->hasPermission(self::BACKDATE_PERMISSION);
    }

    /**
     * Applies a configuration change and records why.
     *
     * Every changed field is written to the history before the record is updated, so
     * the previous value is captured while it still exists. A completed invoice keeps
     * the method it was billed under regardless — nothing here touches transactions,
     * which is what makes a change safe to make mid-year.
     *
     * @param  array<string, mixed> $changes  new values, keyed by column
     * @return array<int, string>             the fields that actually changed
     */
    public static function apply(
        Model $record,
        array $changes,
        ?int $changedBy = null,
        ?string $reason = null,
        ?string $effectiveFrom = null,
    ): array {
        $applied = [];

        foreach ($changes as $field => $newValue) {
            $oldValue = $record->getAttribute($field);

            // Loose comparison on purpose: a boolean column round-trips as 1/0 and a
            // date as a string, and recording "changed from 1 to true" is noise.
            if ($oldValue == $newValue) {
                continue;
            }

            GstConfigurationHistory::create([
                'business_id'       => $record->business_id ?? $record->getKey(),
                'configurable_type' => $record::class,
                'configurable_id'   => $record->getKey(),
                'field'             => $field,
                'old_value'         => self::stringify($oldValue),
                'new_value'         => self::stringify($newValue),
                'reason'            => $reason,
                'effective_from'    => $effectiveFrom,
                'changed_by'        => $changedBy,
            ]);

            $applied[] = $field;
        }

        if ($applied !== []) {
            $record->fill($changes);

            if ($effectiveFrom !== null && $record->isFillable('gst_effective_from')) {
                $record->gst_effective_from = $effectiveFrom;
            }

            $record->save();
        }

        return $applied;
    }

    /**
     * Whether a change to the pricing method needs a stated reason.
     *
     * Deviating from the shop type's convention is a decision someone should be able
     * to account for later; matching it is not.
     */
    public static function requiresReason(?string $newMethod, ?Business $business): bool
    {
        if (! GstPricingMethod::isValid($newMethod) || $business === null) {
            return false;
        }

        $shopTypeDefault = $business->relationLoaded('category')
            ? $business->category?->default_vat_type
            : $business->category()->value('default_vat_type');

        if (! GstPricingMethod::isValid($shopTypeDefault)) {
            // Nothing to deviate from.
            return false;
        }

        return $newMethod !== $shopTypeDefault;
    }

    private static function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }

    public static function isValidGstin(?string $gstin): bool
    {
        if ($gstin === null) {
            return false;
        }

        return (bool) preg_match(self::GSTIN_PATTERN, strtoupper(trim($gstin)));
    }

    /** The state code a GSTIN belongs to, or null if it is malformed. */
    public static function stateCodeOf(?string $gstin): ?string
    {
        return self::isValidGstin($gstin) ? substr(trim($gstin), 0, 2) : null;
    }

    /**
     * Whether a supply is within one state, which decides CGST+SGST versus IGST.
     *
     * Provided now because it depends only on configuration; the components it feeds
     * are not calculated yet.
     */
    public static function isIntraState(?string $branchState, ?string $placeOfSupply): bool
    {
        if ($branchState === null || $placeOfSupply === null) {
            // Unknown, so treat it as local: the safer assumption, since IGST charged
            // wrongly on a local sale is harder to unwind than the reverse.
            return true;
        }

        return strcasecmp(trim($branchState), trim($placeOfSupply)) === 0;
    }

    /**
     * Reasons a configuration cannot be saved, keyed by field. Empty means valid.
     *
     * @return array<string, string>
     */
    public static function validate(array $attributes): array
    {
        $errors = [];

        $registered = $attributes['gst_registered'] ?? null;
        $gstin      = $attributes['gstin'] ?? null;
        $state      = $attributes['gst_state'] ?? null;
        $method     = $attributes['default_vat_type'] ?? null;

        if ($registered === true) {
            if (blank($gstin)) {
                $errors['gstin'] = 'GSTIN is mandatory for a GST-registered branch.';
            } elseif (! self::isValidGstin($gstin)) {
                $errors['gstin'] = 'The entered GSTIN format is invalid.';
            }

            if (blank($state)) {
                $errors['gst_state'] = 'GST state is mandatory for a GST-registered branch.';
            }

            if ($method === GstPricingMethod::NOT_APPLICABLE) {
                $errors['default_vat_type'] =
                    'GST Not Applicable cannot be selected for a GST-registered branch.';
            }
        }

        // A GSTIN carries its own state code. When it disagrees with the stated state
        // one of the two is wrong, and either way the filing is rejected — months
        // after the invoices went out.
        if (! isset($errors['gstin']) && ! GstStates::matches($gstin, $state)) {
            $errors['gstin'] = 'GSTIN state code does not match the branch state.';
        }

        if ($method !== null && ! GstPricingMethod::isValid($method)) {
            $errors['default_vat_type'] = 'Unknown GST pricing method.';
        }

        return $errors;
    }
}
