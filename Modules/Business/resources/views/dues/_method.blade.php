{{--
    How a single due collection was paid.

    Resolved from the collection itself, never from the sale or purchase it settles:
    a bill raised on card is routinely settled later in cash, so the originating
    invoice's method describes the wrong transaction.

    A split is stored as two amounts on one row rather than as its own payment type,
    so it has to be recognised from the amounts - otherwise it renders as whichever
    single type happened to be attached, understating half the payment.
--}}
@php
    $cash = (float) ($collect->cash_amount ?? 0);
    $upi = (float) ($collect->upi_amount ?? 0);
    $named = $collect->payment_type->name ?? $collect->paymentType ?? null;
@endphp
@if ($cash > 0 && $upi > 0)
    {{ __('Split') }}
    ({{ __('Cash') }} {{ currency_format($cash, 'icon', 2, business_currency()) }},
    {{ __('UPI') }} {{ currency_format($upi, 'icon', 2, business_currency()) }})
@elseif ($cash > 0)
    {{ __('Cash') }}
@elseif ($upi > 0)
    {{ __('UPI') }}
@else
    {{ $named ?? '-' }}
@endif
