<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyPackage;
use App\Models\LoyaltyAudit;
use App\Models\LoyaltySubscription;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltySubscriptionManager;
use App\Services\LoyaltyUpgrade;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Selling, renewing and upgrading a customer's loyalty package (§3, §4, §5).
 *
 * §1: a customer joins nothing on creation — a subscription exists only because
 * someone sold them one, which is why there is no implicit default here.
 */
class LoyaltySubscriptionController extends Controller
{
    public function __construct()
    {
        // Every cashier may check the selected customer's current plan while
        // preparing a sale. Listing all subscriptions remains a management
        // permission and every query below is tenant-scoped.
        $this->middleware('check.permission:loyalty.read')->only('index');
        $this->middleware('check.permission:loyalty.assign')->only('store');
        $this->middleware('check.permission:loyalty.renew')->only('renew');
        $this->middleware('check.permission:loyalty.upgrade')->only('upgrade');
        $this->middleware('check.permission:loyalty.cancel')->only('cancel');
        $this->middleware('check.permission:loyalty.process-refunds')->only('refund');
    }

    /** A customer's whole loyalty history, newest first (§11). */
    public function index(Request $request)
    {
        $subscriptions = LoyaltySubscription::query()
            ->where('business_id', auth()->user()->business_id)
            ->when($request->filled('customer_id'), fn ($q) =>
                $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('status'), fn ($q) =>
                $q->where('status', $request->input('status')))
            ->with(['package:id,name,code,priority', 'customer:id,name,phone'])
            ->latest('id')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data'    => $subscriptions,
        ]);
    }

    /**
     * What the till needs to price a cart for this customer (§9).
     *
     * Returns the package as it was sold, not as the master stands today, and
     * reports an expired term as inactive rather than omitting it — §7 requires the
     * cart to show the status and the expiry date.
     */
    public function show(Customer $customer)
    {
        abort_if($customer->business_id !== auth()->user()->business_id, 404);

        $active = LoyaltySubscriptionManager::activeFor($customer->id);
        $latest = LoyaltySubscription::where('customer_id', $customer->id)
            ->latest('id')->first();

        $shown = $active ?? $latest;

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                'has_active_package' => $active !== null,
                // Renew, upgrade and cancel all address the subscription, not the
                // package, so the till cannot offer any of them without this. It
                // is the term being shown, which for a lapsed customer is the one
                // they would be renewing.
                'subscription_id' => $shown?->id,
                'status' => $shown?->status
                    ?? 'none',
                'package' => $shown?->package?->only(['id', 'name', 'code', 'priority', 'description']),
                'start_date' => $shown?->start_date?->toDateString(),
                'end_date'   => $shown?->end_date?->toDateString(),
                'remaining_days' => $active?->remainingDays() ?? 0,
                // §7: the cart shows when it lapsed so the till can offer a renewal.
                'expired_on' => $shown && $shown->hasExpired()
                    ? $shown->end_date->toDateString()
                    : null,
                'discount_type'       => $active?->discount_type,
                'discount_value'      => $active === null ? null : (float) $active->discount_value,
                'max_discount_amount' => $active?->max_discount_amount === null
                    ? null
                    : (float) $active->max_discount_amount,
                'discount_mode'       => $active?->discount_mode,

                // §11: the products this package does not discount, resolved from
                // the product, brand and category rules into plain ids.
                //
                // Sent with the standing rather than fetched per cart change: the
                // till recalculates on every quantity tap (§8.4), and it can only
                // match the server's figure if it knows the same exclusions. Cart
                // lines carry a product id but not a brand or category, so the
                // expansion has to happen here.
                'excluded_product_ids' => $active === null
                    ? []
                    : $this->excludedProductIds($customer->business_id, $active->loyalty_package_id),
            ],
        ]);
    }

    /**
     * Every product the given package will not discount.
     *
     * Shops exclude a handful of lines — prescriptions, gift cards — so this stays
     * small in practice. A shop that excluded most of its catalogue would be better
     * served by an inclusion list, which is a different feature.
     *
     * @return array<int, int>
     */
    private function excludedProductIds(int $businessId, ?int $packageId): array
    {
        return \App\Services\LoyaltyEligibility::excludedProductIdsForBusiness(
            $businessId,
            $packageId,
        );
    }

    public function store(Request $request)
    {
        $businessId = auth()->user()->business_id;

        $validated = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')
                ->where(fn ($q) => $q->where('business_id', $businessId))],
            'loyalty_package_id' => ['required', Rule::exists('loyalty_packages', 'id')
                ->where(fn ($q) => $q->where('business_id', $businessId))],
            'subscription_date' => ['nullable', 'date'],
            'start_date'        => ['nullable', 'date'],
            // Required only for a fixed-term package (§2.1); the manager refuses
            // the sale if one is needed and missing.
            'end_date'          => ['nullable', 'date'],
            'amount_received'   => ['nullable', 'numeric', 'min:0'],
            'payment_mode'      => ['nullable', 'string', 'max:40'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $package = LoyaltyPackage::findOrFail($validated['loyalty_package_id']);

        // §3.3: the start date normally *is* the subscription date. Moving it
        // changes when benefits begin and when the term runs out, so backdating or
        // post-dating one is its own permission rather than a free-text field.
        $subscriptionDate = $validated['subscription_date'] ?? now()->toDateString();

        if (! blank($validated['start_date'] ?? null)
            && $validated['start_date'] !== $subscriptionDate
            && ! auth()->user()->hasPermission('loyalty.modify-dates')
        ) {
            return response()->json([
                'message' => __('You are not authorised to change the package start date.'),
                'reason'  => 'date_change_not_permitted',
            ], 403);
        }

        try {
            $subscription = LoyaltySubscriptionManager::subscribe(
                (int) $validated['customer_id'],
                $package,
                $validated,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data'    => $subscription,
        ]);
    }

    public function renew(Request $request, LoyaltySubscription $subscription)
    {
        $this->assertOwned($subscription);

        $validated = $request->validate([
            'subscription_date' => ['nullable', 'date'],
            'amount_received'   => ['nullable', 'numeric', 'min:0'],
            'payment_mode'      => ['nullable', 'string', 'max:40'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'reason'            => ['nullable', 'string', 'max:255'],
            // §5.1: extend from the current end, or restart from today.
            'extend_from_end'   => ['nullable', 'boolean'],
        ]);

        try {
            $renewed = LoyaltySubscriptionManager::renew(
                $subscription,
                $validated,
                $request->boolean('extend_from_end', true),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Package renewed successfully.'),
            'data'    => $renewed,
        ]);
    }

    public function upgrade(Request $request, LoyaltySubscription $subscription)
    {
        $this->assertOwned($subscription);

        $businessId = auth()->user()->business_id;

        $validated = $request->validate([
            'loyalty_package_id' => ['required', Rule::exists('loyalty_packages', 'id')
                ->where(fn ($q) => $q->where('business_id', $businessId))],
            'subscription_date' => ['nullable', 'date'],
            'effective_method'  => ['nullable', Rule::in([
                LoyaltyUpgrade::IMMEDIATE,
                LoyaltyUpgrade::AFTER_EXPIRY,
                LoyaltyUpgrade::ON_DATE,
            ])],
            'effective_date'    => ['nullable', 'date'],
            'amount_received'   => ['nullable', 'numeric', 'min:0'],
            'payment_mode'      => ['nullable', 'string', 'max:40'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'reason'            => ['nullable', 'string', 'max:255'],
        ]);

        $target = LoyaltyPackage::findOrFail($validated['loyalty_package_id']);

        try {
            $upgraded = LoyaltySubscriptionManager::upgrade(
                $subscription,
                $target,
                $validated,
                $validated['effective_method'] ?? LoyaltyUpgrade::IMMEDIATE,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Package upgraded successfully.'),
            'data'    => $upgraded,
            // §4.4: the credit given for the unused term, so the amount charged can
            // be explained back to the customer.
            'adjustment_amount' => (float) $upgraded->adjustment_amount,
        ]);
    }

    public function cancel(Request $request, LoyaltySubscription $subscription)
    {
        $this->assertOwned($subscription);

        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        // Marked, never deleted: §11 keeps the history, and a cancelled term must
        // still appear in the reports that count what was collected.
        $previousStatus = $subscription->status;

        $subscription->update([
            'status'        => LoyaltyPeriod::CANCELLED,
            'change_reason' => $request->input('reason'),
        ]);

        LoyaltyAudit::record(LoyaltyAudit::CANCELLED, $subscription, [
            'customer_id' => $subscription->customer_id,
            'field'       => 'status',
            'old_value'   => $previousStatus,
            'new_value'   => LoyaltyPeriod::CANCELLED,
            'reason'      => $request->input('reason'),
        ]);

        return response()->json([
            'message' => __('Package cancelled.'),
            'data'    => $subscription->fresh(),
        ]);
    }

    /**
     * Returns money to the customer for a package.
     *
     * The *amount* is the operator's decision, not this endpoint's. Whether a shop
     * refunds in full, pro-rata, or not at all is a commercial policy that differs
     * between shops, and inventing one here would put money back on a rule nobody
     * agreed to. What is enforced is arithmetic: a shop cannot return more than it
     * took, and cannot refund the same term twice.
     *
     * `suggested_refund` in the response is the unused pro-rata value — the same
     * calculation an upgrade credits — offered as a starting figure, not applied.
     *
     * A refund ends the entitlement. Keeping a discount alive on a package that has
     * been paid back would let a customer be refunded and keep the benefit.
     */
    public function refund(Request $request, LoyaltySubscription $subscription)
    {
        $this->assertOwned($subscription);

        $received = (float) $subscription->amount_received;
        $alreadyRefunded = (float) ($subscription->refund_amount ?? 0);
        $refundable = round($received - $alreadyRefunded, 2);

        if ($refundable <= 0) {
            return response()->json([
                'message' => __('There is nothing left to refund on this package.'),
                'data'    => [
                    'amount_received' => $received,
                    'already_refunded' => $alreadyRefunded,
                ],
            ], 422);
        }

        $validated = $request->validate([
            // Bounded by what was actually collected: the cap is the whole point of
            // validating here rather than trusting the caller.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:' . $refundable],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $amount = round((float) $validated['amount'], 2);

        $previousStatus = $subscription->status;

        $subscription->update([
            'refund_amount'  => round($alreadyRefunded + $amount, 2),
            'refunded_at'    => now(),
            'refunded_by'    => auth()->id(),
            'refund_reason'  => $validated['reason'],
            'status'         => LoyaltyPeriod::CANCELLED,
            'change_reason'  => $validated['reason'],
        ]);

        LoyaltyAudit::record(LoyaltyAudit::REFUNDED, $subscription, [
            'customer_id' => $subscription->customer_id,
            'field'       => 'refund_amount',
            'old_value'   => $alreadyRefunded,
            'new_value'   => round($alreadyRefunded + $amount, 2),
            'reason'      => $validated['reason'],
        ]);

        // Recorded separately because the two facts are separately interesting: a
        // report asks what was refunded, an audit asks why the term ended.
        if ($previousStatus !== LoyaltyPeriod::CANCELLED) {
            LoyaltyAudit::record(LoyaltyAudit::CANCELLED, $subscription, [
                'customer_id' => $subscription->customer_id,
                'field'       => 'status',
                'old_value'   => $previousStatus,
                'new_value'   => LoyaltyPeriod::CANCELLED,
                'reason'      => $validated['reason'],
            ]);
        }

        return response()->json([
            'message' => __('Refund recorded.'),
            'data'    => $subscription->fresh(),
        ]);
    }

    /**
     * What could be refunded, and what a pro-rata refund would come to.
     *
     * Read before refunding so the operator decides against real figures rather
     * than doing the arithmetic in their head at the counter.
     */
    public function refundQuote(LoyaltySubscription $subscription)
    {
        $this->assertOwned($subscription);

        $received = (float) $subscription->amount_received;
        $alreadyRefunded = (float) ($subscription->refund_amount ?? 0);

        $totalDays = $subscription->start_date->diffInDays($subscription->end_date);

        $suggested = \App\Services\LoyaltyUpgrade::unusedValue(
            $received,
            (int) $totalDays,
            max(0, (int) $subscription->remainingDays()),
        );

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                'amount_received'   => round($received, 2),
                'already_refunded'  => round($alreadyRefunded, 2),
                'refundable'        => round(max($received - $alreadyRefunded, 0), 2),
                'remaining_days'    => $subscription->remainingDays(),
                // The unused portion, as an upgrade would credit it. A suggestion —
                // the shop's policy decides what is actually paid back.
                'suggested_refund'  => min($suggested, round(max($received - $alreadyRefunded, 0), 2)),
            ],
        ]);
    }

    private function assertOwned(LoyaltySubscription $subscription): void
    {
        abort_if($subscription->business_id !== auth()->user()->business_id, 404);
    }
}
