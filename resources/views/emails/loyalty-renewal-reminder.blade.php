{{--
    The renewal reminder email (§5.3).

    Plain and short on purpose. The one thing it has to do is give the customer
    something to act on — which package, and by when — so the body leads with the
    same sentence the SMS carries rather than restating it differently.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your membership is expiring</title>
</head>
<body style="margin:0;padding:24px;background:#f7f7fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#1f2937;">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;padding:24px;">
        <p style="margin:0 0 16px;font-size:16px;">
            {{ $customerName ? 'Dear ' . $customerName . ',' : 'Hello,' }}
        </p>

        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
            {{ $message }}
        </p>

        @if ($packageName && $endDate)
            <table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 16px;">
                <tr>
                    <td style="padding:8px 0;font-size:14px;color:#6b7280;">Package</td>
                    <td style="padding:8px 0;font-size:14px;text-align:right;">{{ $packageName }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;font-size:14px;color:#6b7280;">Valid until</td>
                    <td style="padding:8px 0;font-size:14px;text-align:right;">{{ $endDate }}</td>
                </tr>
            </table>
        @endif

        <p style="margin:0 0 4px;font-size:14px;line-height:1.6;color:#6b7280;">
            To renew, visit us or call the shop.
        </p>

        <p style="margin:16px 0 0;font-size:14px;">{{ $shopName }}</p>
    </div>
</body>
</html>
