<?php

namespace App\Support\Api;

use InvalidArgumentException;

class SplitPayment
{
    public static function amounts(object $request): array
    {
        $cash = (float) ($request->cash_amount ?? 0);
        $upi = (float) ($request->upi_amount ?? 0);
        $card = (float) ($request->card_amount ?? 0);
        $paid = (float) ($request->paidAmount ?? 0);

        if (strtolower((string) $request->paymentType) === 'split' && round($cash + $upi + $card, 2) !== round($paid, 2)) {
            throw new InvalidArgumentException('Split payment total must equal paid amount');
        }

        return [
            'cash_amount' => $cash,
            'upi_amount' => $upi,
            'card_amount' => $card,
        ];
    }
}
