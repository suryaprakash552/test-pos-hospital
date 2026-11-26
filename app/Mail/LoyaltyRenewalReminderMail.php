<?php

namespace App\Mail;

use App\Models\LoyaltySubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The renewal reminder, by email (§5.3).
 *
 * Carries the same sentence the SMS does. One wording, one place — a customer who
 * gets both should not read two different expiry dates, and a second copy of the
 * text is a second thing to keep correct.
 */
class LoyaltyRenewalReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly LoyaltySubscription $subscription,
        public readonly string $reminder,
    ) {
    }

    public function build(): self
    {
        $shop = $this->subscription->business?->companyName
            ?? config('app.name');

        return $this
            ->subject('Your ' . ($this->subscription->package?->name ?? 'membership') . ' is expiring')
            ->view('emails.loyalty-renewal-reminder', [
                'customerName' => $this->subscription->customer?->name,
                'packageName' => $this->subscription->package?->name,
                'endDate' => $this->subscription->end_date?->toDateString(),
                'message' => $this->reminder,
                'shopName' => $shop,
            ]);
    }
}
