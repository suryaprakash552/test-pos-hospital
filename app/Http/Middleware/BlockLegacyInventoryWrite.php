<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses the legacy web panel's inventory writes.
 *
 * ## Why
 *
 * The web module's purchase, sale, and both return controllers move stock by
 * adding to and subtracting from `products.productStock` directly. Not one of
 * them writes `purchase_details.conversionQuantity`, which is the quantity the
 * API, the app, FIFO allocation and every availability check actually read.
 *
 * So a sale made here decrements a tally nobody sells from, and the stock stays
 * available to the counter afterwards. A purchase here inflates that tally and
 * writes purchase rows with no `stock_id`, no batch and no lot quantity, which
 * is stock the shop can never sell and the audit cannot count.
 *
 * The two views of inventory drift apart with every such write, and no amount
 * of care in the API can hold them together while another door writes straight
 * to the tally.
 *
 * ## What this does
 *
 * Blocks the write verbs and sends the operator to the workflow that maintains
 * the ledger properly. Reads and exports are untouched: seeing a purchase list
 * is safe, creating one from here is not.
 *
 * Controlled by `inventory.allow_legacy_web_writes` so the block can be lifted
 * for one deployment without a code change, and so tests can prove both states.
 * It is off by default: the safe state must be the one you get by doing nothing.
 */
class BlockLegacyInventoryWrite
{
    /** Verbs that can change stock. GET and HEAD are read paths and stay open. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Read-only actions that exist only to open a write form.
     *
     * Rendering a purchase form whose Save is refused wastes the operator's
     * time and reads as a broken page rather than a withdrawn feature, so the
     * entry point is turned back with the reason instead.
     */
    private const FORM_ACTIONS = ['.create', '.edit'];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('inventory.allow_legacy_web_writes', false)) {
            return $next($request);
        }

        if (! $this->isBlocked($request)) {
            return $next($request);
        }

        $message = __(
            'This screen can no longer change stock. Purchases, sales and returns '
            .'must be recorded in the app so that batch quantities stay correct. '
            .'This page is available for viewing only.'
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'reason' => 'legacy_inventory_write_disabled',
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()
            ->back()
            ->with('error', $message);
    }

    private function isBlocked(Request $request): bool
    {
        if (in_array($request->method(), self::WRITE_METHODS, true)) {
            return true;
        }

        $name = (string) ($request->route()?->getName() ?? '');

        foreach (self::FORM_ACTIONS as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
