<?php

namespace App\Services;

/**
 * Revises an invoice's loyalty discount when items come back (§11).
 *
 * A discount was granted on a cart of a certain size. Return half the cart and the
 * discount was, in part, given against goods the customer no longer has — leaving it
 * untouched refunds them the full item price while they keep a discount earned on it.
 * On a percentage package that error compounds with every return.
 *
 * Recalculated from the terms **stored on the invoice**, never from the package
 * master: §11 fixes an invoice's terms at billing, and a package edited since must
 * not change what an old sale is owed.
 *
 * The two modes are treated differently on purpose:
 *
 *   - Automatic was the system's arithmetic, so the system redoes it.
 *   - Manual was a person's decision within an entitlement. It is capped down to the
 *     reduced entitlement but never raised — recalculating upward would overrule a
 *     judgement someone made and recorded their name against.
 */
class LoyaltyReturnAdjustment
{
    /**
     * @param float      $originalEligible   the base the discount was granted on
     * @param float      $returnedAmount     value of the goods coming back
     * @param float      $currentDiscount    what the invoice currently allows
     * @param string     $discountType       terms as stored on the invoice
     * @param float|null $maxDiscountAmount  null means no ceiling (§6.4)
     *
     * @return array{eligible_amount: float, discount_amount: float, reversed: float}
     */
    public static function revise(
        float $originalEligible,
        float $returnedAmount,
        float $currentDiscount,
        string $discountType,
        float $discountValue,
        ?float $maxDiscountAmount = null,
        bool $isManual = false,
    ): array {
        $newEligible = round(max($originalEligible - max($returnedAmount, 0), 0), 2);

        $entitlement = LoyaltyDiscount::calculate(
            $newEligible,
            $discountType,
            $discountValue,
            $maxDiscountAmount,
        );

        // A manual figure stands unless the smaller cart no longer supports it.
        $newDiscount = $isManual
            ? round(min($currentDiscount, $entitlement), 2)
            : $entitlement;

        return [
            'eligible_amount' => $newEligible,
            'discount_amount' => $newDiscount,
            // What the shop takes back. Never negative: a return cannot earn the
            // customer a larger discount than they already had.
            'reversed' => round(max($currentDiscount - $newDiscount, 0), 2),
        ];
    }

    /**
     * The whole discount, for an invoice being cancelled outright (§11).
     *
     * Nothing was sold, so nothing was earned.
     */
    public static function reverseAll(float $currentDiscount): array
    {
        return [
            'eligible_amount' => 0.0,
            'discount_amount' => 0.0,
            'reversed'        => round(max($currentDiscount, 0), 2),
        ];
    }
}
