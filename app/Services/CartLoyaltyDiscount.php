<?php

namespace App\Services;

use App\Models\LoyaltySubscription;

/**
 * Resolves the loyalty discount for a cart at the moment of billing (§9, §10).
 *
 * Sits between the pure arithmetic in [LoyaltyDiscount] and the sale itself: it
 * decides *whether* a discount applies at all — is there a package, is it current,
 * is the mode automatic or manual, was the entered figure allowed — and returns
 * everything the invoice has to store.
 *
 * The returned array is deliberately complete rather than just an amount. §9 requires
 * the configuration used at billing to be recorded, and §12.6 reports on the base as
 * well as the discount, so a bill can be explained months later without guessing.
 */
class CartLoyaltyDiscount
{
    /**
     * @param float                    $eligibleAmount cart after row discounts, ineligible lines removed
     * @param LoyaltySubscription|null $subscription   the customer's active package, if any
     * @param float|null               $manualAmount   what a user typed, in manual mode
     * @param bool                     $mayEnterManual §10: manual entry is permission-gated
     *
     * @return array{
     *   applied: bool, amount: float, reason: ?string,
     *   eligible_amount: float, maximum_eligible: float,
     *   status: string, package_name: ?string, expired_on: ?string,
     *   snapshot: array<string, mixed>
     * }
     */
    public static function resolve(
        float $eligibleAmount,
        ?LoyaltySubscription $subscription,
        ?float $manualAmount = null,
        bool $mayEnterManual = true,
        ?string $onDate = null,
        ?string $manualReason = null,
        bool $requireReasonBelowMaximum = false,
        bool $mayOverrideAutomatic = false,
    ): array {
        $base = [
            'applied'          => false,
            'amount'           => 0.0,
            'reason'           => null,
            'eligible_amount'  => round(max($eligibleAmount, 0), 2),
            'maximum_eligible' => 0.0,
            'status'           => 'none',
            'package_name'     => null,
            'expired_on'       => null,
            'snapshot'         => [],
        ];

        // §1: not every customer is in the programme, and that is not an error.
        if ($subscription === null) {
            return $base;
        }

        $base['package_name'] = $subscription->package?->name;
        $base['status']       = $subscription->status;
        $base['snapshot']     = [
            'loyalty_subscription_id'     => $subscription->id,
            'loyalty_package_name'        => $subscription->package?->name,
            'loyalty_discount_type'       => $subscription->discount_type,
            'loyalty_discount_value'      => (float) $subscription->discount_value,
            'loyalty_max_discount_amount' => $subscription->max_discount_amount === null
                ? null
                : (float) $subscription->max_discount_amount,
            'loyalty_discount_mode'       => $subscription->discount_mode,
        ];

        // §7: an expired customer is billed as a non-loyalty customer, but the cart
        // still shows the status and the date so a renewal can be offered.
        if (! $subscription->isCurrentlyValid($onDate)) {
            $base['status'] = $subscription->hasExpired($onDate)
                ? LoyaltyPeriod::EXPIRED
                : $subscription->status;
            $base['expired_on'] = $subscription->hasExpired($onDate)
                ? $subscription->end_date->toDateString()
                : null;

            return $base;
        }

        $maximum = LoyaltyDiscount::maximumEligible(
            $eligibleAmount,
            $subscription->discount_type,
            (float) $subscription->discount_value,
            $subscription->max_discount_amount === null
                ? null
                : (float) $subscription->max_discount_amount,
        );

        $base['maximum_eligible'] = $maximum;

        // Loyalty is a checkout choice, never an invisible side effect of
        // selecting a customer. Even packages historically configured as
        // "automatic" now require the client to explicitly submit the amount.
        if ($subscription->discount_mode !== LoyaltyDiscount::MANUAL) {
            if ($manualAmount === null || $manualAmount <= 0) {
                return $base;
            }

            // Selecting the package's exact entitlement is ordinary sale work
            // and does not require package-management/override permission.
            if (abs($manualAmount - $maximum) < 0.005) {
                return array_merge($base, [
                    'applied' => $maximum > 0,
                    'amount' => $maximum,
                ]);
            }

            // §9 also allows a permitted user to depart from the automatic
            // figure. Without this the `loyalty.override` permission was
            // grantable and did nothing: an amount sent against an automatic
            // package was silently ignored, so the cashier saw the entitlement
            // regardless of what they typed.
            //
            // The override still cannot exceed the entitlement or the cart - it
            // permits a *different* figure, not an unbounded one.
            if ($manualAmount !== null && $mayOverrideAutomatic) {
                $refusal = LoyaltyDiscount::rejectManual(
                    $manualAmount,
                    $base['eligible_amount'],
                    $maximum,
                );

                if ($refusal !== null) {
                    return array_merge($base, ['reason' => $refusal]);
                }

                return array_merge($base, [
                    'applied' => $manualAmount > 0,
                    'amount'  => round($manualAmount, 2),
                    'snapshot' => $base['snapshot'] + [
                        // Recorded so the invoice says the figure was a decision
                        // rather than the package's own arithmetic.
                        'loyalty_discount_overridden' => true,
                    ],
                ]);
            }

            return array_merge($base, [
                'reason' => 'Select the full loyalty benefit or ask an authorised user to change it.',
            ]);
        }

        // ── §10: manual ──────────────────────────────────────────────────────
        // Nothing is applied until someone enters a figure — that is the whole
        // difference between the two modes.
        //
        // Zero counts as nothing. The app posts the field whether or not the
        // loyalty switch is on, and `filled()` treats "0" as a value, so a
        // cashier without the manual-discount right was refused every bill for
        // a customer who happens to hold a loyalty package — "You are not
        // authorised to enter a loyalty discount" over a bill with no loyalty
        // discount on it. Entering nothing and entering zero are the same
        // decision; only an actual figure is a decision that needs the right.
        if ($manualAmount === null || $manualAmount <= 0) {
            return $base;
        }

        if (! $mayEnterManual) {
            return array_merge($base, [
                'reason' => 'You are not authorised to enter a loyalty discount.',
            ]);
        }

        $refusal = LoyaltyDiscount::rejectManual(
            $manualAmount,
            $base['eligible_amount'],
            $maximum,
        );

        if ($refusal !== null) {
            return array_merge($base, ['reason' => $refusal]);
        }

        // §10: giving less than the customer is entitled to is a decision someone
        // should be able to account for, so it carries a reason. Giving the full
        // entitlement is simply the package working as configured.
        if ($requireReasonBelowMaximum
            && $manualAmount < $maximum
            && blank($manualReason)
        ) {
            return array_merge($base, [
                'reason' => 'A reason is required when the loyalty discount given is '
                    . 'less than the maximum eligible discount of '
                    . number_format($maximum, 2) . '.',
            ]);
        }

        return array_merge($base, [
            'applied' => $manualAmount > 0,
            'amount'  => round($manualAmount, 2),
        ]);
    }

    /**
     * The columns to persist on the sale.
     *
     * @param array<string, mixed> $resolved output of [resolve]
     * @return array<string, mixed>
     */
    public static function saleColumns(array $resolved, ?int $enteredBy = null): array
    {
        if ($resolved['snapshot'] === []) {
            return ['loyalty_discount_amount' => 0];
        }

        return $resolved['snapshot'] + [
            'loyalty_eligible_amount' => $resolved['eligible_amount'],
            'loyalty_discount_amount' => $resolved['amount'],
            // Only set for a manual entry: an automatic discount was nobody's
            // decision, so attributing it to the cashier would be misleading.
            'loyalty_discount_by' => $resolved['snapshot']['loyalty_discount_mode']
                === LoyaltyDiscount::MANUAL ? $enteredBy : null,
        ];
    }
}
