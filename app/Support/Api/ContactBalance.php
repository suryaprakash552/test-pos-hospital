<?php

namespace App\Support\Api;

class ContactBalance
{
    public static function openingPayload(?float $openingBalance, string $type, ?float $creditLimit = null): array
    {
        $openingBalance = $openingBalance ?? 0;

        return [
            'due' => $type === 'due' ? $openingBalance : 0,
            'wallet' => $type === 'advance' ? $openingBalance : 0,
            'opening_balance' => $openingBalance,
            'opening_balance_type' => $type,
            'credit_limit' => $creditLimit ?? 0,
        ];
    }

    public static function adjustedPayload(object $model, ?float $openingBalance, string $type, ?float $creditLimit = null): array
    {
        $previousOpening = (float) ($model->opening_balance ?? 0);
        $previousType = (string) ($model->opening_balance_type ?? 'cash');
        $currentOpening = $openingBalance ?? 0;
        $due = (float) ($model->due ?? 0);
        $wallet = (float) ($model->wallet ?? 0);

        if ($previousType === $type) {
            if ($type === 'due') {
                $due += $currentOpening - $previousOpening;
            } elseif ($type === 'advance') {
                $wallet += $currentOpening - $previousOpening;
            }
        } else {
            if ($previousType === 'due') {
                $due -= $previousOpening;
            } elseif ($previousType === 'advance') {
                $wallet -= $previousOpening;
            }

            if ($type === 'due') {
                $due += $currentOpening;
            } elseif ($type === 'advance') {
                $wallet += $currentOpening;
            }
        }

        return [
            'due' => max($due, 0),
            'wallet' => max($wallet, 0),
            'opening_balance' => $currentOpening,
            'opening_balance_type' => $type,
            'credit_limit' => $creditLimit ?? $model->credit_limit ?? 0,
        ];
    }
}
