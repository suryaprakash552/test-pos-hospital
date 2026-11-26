<?php

/**
 * One till attempting to buy the last unit. Two of these run at once.
 *
 * Waits on a shared start file so both processes enter the checkout at genuinely
 * the same moment rather than one finishing before the other begins.
 *
 * usage: php oversell_seller.php <user_id> <stock_id> <start_at_epoch_micros>
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;

[$script, $userId, $stockId, $startAt] = $argv;

$user = User::find((int) $userId);
auth()->login($user);

// Spin until the agreed instant so both requests collide.
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$request = Request::create('/api/v1/sales', 'POST', [
    'products' => json_encode([[
        'stock_id' => (int) $stockId,
        'quantities' => 1,
        'price' => 100,
        'lossProfit' => 0,
    ]]),
    'saleDate' => date('Y-m-d'),
    'paymentType' => 'cash',
    'paidAmount' => 100,
    'totalAmount' => 100,
]);

$request->headers->set('Accept', 'application/json');

$response = $kernel->handle($request);

echo json_encode([
    'pid' => getmypid(),
    'status' => $response->getStatusCode(),
]) . PHP_EOL;
