<?php

namespace App\Services;

use App\Jobs\SendSmsMessageJob;
use App\Jobs\SendUserNotificationJob;
use App\Mail\LoyaltyRenewalReminderMail;
use App\Models\LoyaltySubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * How a renewal reminder reaches someone (§5.3).
 *
 * §5.3 names four channels. They are not equally available, and pretending
 * otherwise is the failure mode worth avoiding: a channel that reports success
 * while sending nothing is worse than one that plainly says it is not configured,
 * because a shop stops chasing renewals it believes were sent.
 *
 * So each channel here either does the thing or says why it cannot:
 *
 * - `sms`      dispatches through the existing SMS job.
 * - `in_app`   notifies the *shop*, not the customer. A customer does not have this
 *              app; the shop does, and a renewal it can see is a renewal someone can
 *              act on.
 * - `email`    mails the customer, when the shop holds an address for them.
 *              Delivery depends on the deployment's own SMTP settings.
 * - `whatsapp` needs a WhatsApp Business API account. Until one is configured this
 *              reports itself unavailable rather than silently doing nothing.
 */
class LoyaltyReminderChannels
{
    public const SMS = 'sms';
    public const IN_APP = 'in_app';
    public const EMAIL = 'email';
    public const WHATSAPP = 'whatsapp';

    /** Every channel §5.3 names, whether or not this deployment can use it. */
    public static function all(): array
    {
        return [self::SMS, self::IN_APP, self::EMAIL, self::WHATSAPP];
    }

    /**
     * The channels this deployment will actually attempt.
     *
     * Configured rather than assumed, because a shop with no SMS credit and no
     * mail server should not generate a failed send per customer per day.
     */
    public static function enabled(): array
    {
        $configured = config('pos.loyalty.reminder_channels', [self::SMS]);

        return array_values(array_intersect($configured, self::available()));
    }

    /** Channels this build can carry out at all. */
    public static function available(): array
    {
        return array_values(array_filter(
            self::all(),
            fn (string $channel) => self::isAvailable($channel),
        ));
    }

    /**
     * Whether [channel] can do anything.
     *
     * WhatsApp is the honest `false`: there is no provider wired to it, so a
     * deployment that enables it would otherwise record sends that never happened.
     */
    public static function isAvailable(string $channel): bool
    {
        return match ($channel) {
            self::SMS, self::IN_APP => true,
            self::EMAIL => filled(config('mail.mailers.' . config('mail.default') . '.host'))
                || config('mail.default') === 'log',
            self::WHATSAPP => false,
            default => false,
        };
    }

    /** Why a channel is unavailable, for an operator reading a command's output. */
    public static function unavailableReason(string $channel): ?string
    {
        if (self::isAvailable($channel)) {
            return null;
        }

        return match ($channel) {
            self::WHATSAPP => 'No WhatsApp Business API provider is configured.',
            self::EMAIL => 'No mail host is configured.',
            default => 'Unknown channel.',
        };
    }

    /**
     * Sends one reminder over [channel].
     *
     * Returns true only when something was actually handed off. A channel with
     * nothing to send to — a customer with no phone, no email — returns false, and
     * the caller counts it as skipped rather than sent.
     */
    public static function send(
        string $channel,
        LoyaltySubscription $subscription,
        string $message,
    ): bool {
        $customer = $subscription->customer;

        return match ($channel) {
            self::SMS => self::sendSms($customer?->phone, $message),
            self::EMAIL => self::sendEmail($customer?->email, $subscription, $message),
            self::IN_APP => self::sendInApp($subscription, $message),
            default => false,
        };
    }

    private static function sendSms(?string $phone, string $message): bool
    {
        if (blank($phone)) {
            return false;
        }

        SendSmsMessageJob::dispatch($phone, $message);

        return true;
    }

    private static function sendEmail(
        ?string $email,
        LoyaltySubscription $subscription,
        string $message,
    ): bool {
        // A shop may hold customers without an address. Not an error, but not a
        // send either.
        if (blank($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            Mail::to($email)->queue(
                new LoyaltyRenewalReminderMail($subscription, $message),
            );

            return true;
        } catch (\Throwable $e) {
            // Logged rather than thrown: one unreachable address must not stop the
            // rest of the night's reminders.
            Log::warning('Loyalty reminder email could not be queued', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Tells the shop, not the customer.
     *
     * The customer does not have this app. What the shop needs is a list of people
     * to call, which is what this puts in front of them.
     */
    private static function sendInApp(
        LoyaltySubscription $subscription,
        string $message,
    ): bool {
        SendUserNotificationJob::dispatch(
            $subscription->customer_id,
            '/loyalty-reports/expired',
            $message,
            $subscription->business_id,
        );

        return true;
    }
}
