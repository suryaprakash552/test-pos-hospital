<?php

namespace App\Services;

use App\Models\LoyaltyAudit;
use App\Models\LoyaltyPackage;
use App\Models\LoyaltySubscription;
use Illuminate\Support\Facades\DB;

/**
 * Selling, renewing and upgrading a loyalty subscription (§3, §4, §5).
 *
 * Each operation closes the previous term and opens a new one in a single
 * transaction. Doing it in two steps would allow a crash between them to leave a
 * customer with two active packages — which §11 forbids, and which would double
 * their discount until someone noticed.
 *
 * Nothing here is destructive: the previous row is marked Renewed or Upgraded, never
 * deleted, so §12's reports can still see it.
 */
class LoyaltySubscriptionManager
{
    /**
     * Where a term ends.
     *
     * §2.1: a `fixed` package carries explicit dates rather than a length — a
     * seasonal offer ends on the same calendar day for everyone, so it cannot be
     * derived from when a particular customer happened to buy it. Every other type
     * is calculated from the start date.
     *
     * @throws \RuntimeException when a fixed package is sold without an end date
     */
    private static function termEnd(LoyaltyPackage $package, string $start, array $details): string
    {
        if (! LoyaltyPeriod::needsExplicitEndDate($package->validity_type)) {
            return LoyaltyPeriod::endDate(
                $start,
                $package->validity_type,
                (int) $package->validity_period,
            );
        }

        $end = $details['end_date'] ?? null;

        if (blank($end)) {
            throw new \RuntimeException(
                'This package runs to a fixed date, so an end date is required.'
            );
        }

        if ($end < $start) {
            throw new \RuntimeException('The end date cannot be before the start date.');
        }

        return \Illuminate\Support\Carbon::parse($end)->toDateString();
    }

    /**
     * The subscription currently granting benefits to a customer, if any.
     *
     * Dates as well as status, so an Active row whose term has run out does not
     * keep paying out until something gets round to expiring it.
     */
    public static function activeFor(int $customerId, ?string $onDate = null): ?LoyaltySubscription
    {
        return LoyaltySubscription::query()
            ->where('customer_id', $customerId)
            ->current($onDate)
            ->latest('id')
            ->first();
    }

    /**
     * Sells a package to a customer (§3).
     *
     * @param array{
     *   subscription_date?: string, start_date?: string, amount_received?: float,
     *   payment_mode?: string, payment_reference?: string, created_by?: int,
     *   branch_id?: int
     * } $details
     *
     * @throws \RuntimeException when the customer already holds an active package
     */
    public static function subscribe(
        int $customerId,
        LoyaltyPackage $package,
        array $details = [],
    ): LoyaltySubscription {
        $subscriptionDate = $details['subscription_date'] ?? now()->toDateString();

        if (! $package->isAssignableOn($subscriptionDate)) {
            // §2.2: an inactive or out-of-window package cannot be sold. Existing
            // holders keep theirs — that is why this guards the sale, not the read.
            throw new \RuntimeException(
                'This package is not available for assignment.'
            );
        }

        return DB::transaction(function () use ($customerId, $package, $details, $subscriptionDate) {
            // §11: one active package at a time. Checked inside the transaction so
            // two tills cannot both pass the check and both insert.
            if (self::activeFor($customerId, $subscriptionDate) !== null) {
                throw new \RuntimeException(
                    'This customer already has an active loyalty package. '
                    . 'Renew or upgrade it instead.'
                );
            }

            $start = $details['start_date'] ?? $subscriptionDate;

            $subscription = LoyaltySubscription::create([
                'business_id'        => $package->business_id,
                'customer_id'        => $customerId,
                'loyalty_package_id' => $package->id,
                'subscription_date'  => $subscriptionDate,
                'start_date'         => $start,
                'end_date'           => self::termEnd($package, $start, $details),
                'status'          => LoyaltyPeriod::ACTIVE,
                'package_cost'    => $package->cost,
                'amount_received' => $details['amount_received'] ?? $package->cost,
                'payment_mode'      => $details['payment_mode'] ?? null,
                'payment_reference' => $details['payment_reference'] ?? null,
                'origin'            => 'new',
                'created_by'        => $details['created_by'] ?? auth()->id(),
                'branch_id'         => $details['branch_id'] ?? null,
            ] + $package->discountSnapshot());

            LoyaltyAudit::record(LoyaltyAudit::SUBSCRIBED, $subscription, [
                'customer_id' => $customerId,
                'field'       => 'package',
                'new_value'   => $package->name,
                'reason'      => $details['reason'] ?? null,
            ]);

            return $subscription;
        });
    }

    /**
     * Renews an existing subscription (§5).
     *
     * Renewing early extends rather than restarts, so the customer keeps the days
     * they already paid for; renewing late starts from the renewal date and §5.2 is
     * explicit that the gap earns nothing.
     */
    public static function renew(
        LoyaltySubscription $current,
        array $details = [],
        bool $extendFromEnd = true,
    ): LoyaltySubscription {
        $package = $current->package;

        if (! $package->renewal_allowed) {
            throw new \RuntimeException('This package cannot be renewed.');
        }

        $renewalDate = $details['subscription_date'] ?? now()->toDateString();

        return DB::transaction(function () use ($current, $package, $details, $renewalDate, $extendFromEnd) {
            $start = LoyaltyPeriod::renewalStartDate(
                $current->end_date->toDateString(),
                $renewalDate,
                $extendFromEnd,
            );

            $renewed = LoyaltySubscription::create([
                'business_id'        => $current->business_id,
                'customer_id'        => $current->customer_id,
                'loyalty_package_id' => $package->id,
                'subscription_date'  => $renewalDate,
                'start_date'         => $start,
                'end_date'           => self::termEnd($package, $start, $details),
                'status'          => LoyaltyPeriod::ACTIVE,
                'package_cost'    => $package->cost,
                'amount_received' => $details['amount_received'] ?? $package->cost,
                'payment_mode'      => $details['payment_mode'] ?? null,
                'payment_reference' => $details['payment_reference'] ?? null,
                'previous_subscription_id' => $current->id,
                'origin'                   => 'renewal',
                'change_reason'            => $details['reason'] ?? null,
                'created_by'               => $details['created_by'] ?? auth()->id(),
                'branch_id'                => $details['branch_id'] ?? null,
                // Priced from the package as it stands now: a renewal is a new
                // purchase, so it takes today's terms rather than the old ones.
            ] + $package->discountSnapshot());

            // Marked, not deleted — §5.2 requires the expired term to remain visible.
            $current->update(['status' => LoyaltyPeriod::RENEWED]);

            LoyaltyAudit::record(LoyaltyAudit::RENEWED, $renewed, [
                'customer_id' => $current->customer_id,
                'field'       => 'end_date',
                'old_value'   => $current->end_date->toDateString(),
                'new_value'   => $renewed->end_date->toDateString(),
                'reason'      => $details['reason'] ?? null,
            ]);

            return $renewed;
        });
    }

    /**
     * Moves a customer up to a higher package (§4).
     *
     * The unused value of the current term is credited against the new cost, so the
     * customer is not billed twice for the same days.
     */
    public static function upgrade(
        LoyaltySubscription $current,
        LoyaltyPackage $target,
        array $details = [],
        string $effectiveMethod = LoyaltyUpgrade::IMMEDIATE,
    ): LoyaltySubscription {
        $currentPackage = $current->package;

        $refusal = LoyaltyUpgrade::rejectionReason(
            $current->status,
            (int) $currentPackage->priority,
            (int) $target->priority,
            (bool) $target->status,
            (bool) $target->upgrade_allowed,
        );

        if ($refusal !== null) {
            throw new \RuntimeException($refusal);
        }

        $upgradeDate = $details['subscription_date'] ?? now()->toDateString();

        return DB::transaction(function () use (
            $current, $target, $details, $upgradeDate, $effectiveMethod
        ) {
            // §4.2: which method the shop uses is configuration. `full` charges the
            // complete cost of the new package; `prorata` credits the unused part of
            // the current term against it, which is the worked example in the spec.
            $unused = config('pos.loyalty.upgrade_cost_method', 'prorata') === 'full'
                ? 0.0
                : LoyaltyUpgrade::unusedValue(
                    (float) $current->package_cost,
                    $current->totalDays(),
                    $current->remainingDays($upgradeDate),
                );

            $start = LoyaltyUpgrade::effectiveDate(
                $effectiveMethod,
                $upgradeDate,
                $current->end_date->toDateString(),
                $details['effective_date'] ?? null,
            );

            $upgraded = LoyaltySubscription::create([
                'business_id'        => $current->business_id,
                'customer_id'        => $current->customer_id,
                'loyalty_package_id' => $target->id,
                'subscription_date'  => $upgradeDate,
                'start_date'         => $start,
                'end_date'           => self::termEnd($target, $start, $details),
                'status'          => LoyaltyPeriod::ACTIVE,
                'package_cost'    => $target->cost,
                'amount_received' => $details['amount_received']
                    ?? LoyaltyUpgrade::amountPayable((float) $target->cost, $unused),
                'payment_mode'      => $details['payment_mode'] ?? null,
                'payment_reference' => $details['payment_reference'] ?? null,
                'previous_subscription_id' => $current->id,
                'origin'                   => 'upgrade',
                // §4.4: what the old term was worth, so the arithmetic on the
                // customer's bill can be reconstructed later.
                'adjustment_amount' => $unused,
                'change_reason'     => $details['reason'] ?? null,
                'created_by'        => $details['created_by'] ?? auth()->id(),
                'branch_id'         => $details['branch_id'] ?? null,
            ] + $target->discountSnapshot());

            $current->update(['status' => LoyaltyPeriod::UPGRADED]);

            // §4.4: the previous package, the new one, and the credit given for the
            // unused term — enough to reconstruct what the customer was charged.
            LoyaltyAudit::record(LoyaltyAudit::UPGRADED, $upgraded, [
                'customer_id' => $current->customer_id,
                'field'       => 'package',
                'old_value'   => $current->package?->name,
                'new_value'   => $target->name,
                'reason'      => $details['reason'] ?? null,
            ]);

            return $upgraded;
        });
    }

    /**
     * Marks subscriptions whose term has run out (§3.3).
     *
     * The read path already treats a lapsed term as inactive, so this is
     * housekeeping for reporting rather than something benefits depend on — which
     * is why a shop that never runs it still charges correctly.
     *
     * @return int rows marked
     */
    public static function expireLapsed(?int $businessId = null, ?string $onDate = null): int
    {
        $day = \Illuminate\Support\Carbon::parse($onDate ?? now())->toDateString();

        return LoyaltySubscription::query()
            ->where('status', LoyaltyPeriod::ACTIVE)
            ->whereDate('end_date', '<', $day)
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            ->update(['status' => LoyaltyPeriod::EXPIRED]);
    }
}
