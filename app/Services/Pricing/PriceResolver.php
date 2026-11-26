<?php

namespace App\Services\Pricing;

use App\Models\Customer;
use App\Models\Stock;
use App\Support\Money;

/**
 * The selling price of one stock batch, decided by the server.
 *
 * The till sends a price with every cart line, and that price used to be what got
 * written to `sale_details`. Anyone able to call the API could therefore name their
 * own price. The price is a property of the batch and the customer's tier, both of
 * which the server already holds, so it is resolved here instead of accepted.
 *
 * The tier rules match what the app shows the cashier
 * (`_priceForCustomer` in sales_products_list_screen.dart), so an honest client
 * sees the same figure it always did.
 */
class PriceResolver
{
    /**
     * Unit price for a batch, for the given customer (null = walk-in).
     */
    public function forStock(Stock $stock, ?Customer $customer = null): Money
    {
        $sale = $this->positive($stock->productSalePrice);
        $dealer = $this->positive($stock->productDealerPrice);
        $wholesale = $this->positive($stock->productWholeSalePrice);
        $purchase = $this->positive($stock->productPurchasePrice);

        $type = (string) ($customer?->type ?? '');

        // Substring matching, because the stored type carries labels like
        // "Retailer/Dealer" rather than a bare enum.
        if (str_contains($type, 'Dealer')) {
            return $dealer > 0 ? Money::fromFloat($dealer) : Money::fromFloat($sale);
        }

        if (str_contains($type, 'Wholesaler')) {
            return $wholesale > 0 ? Money::fromFloat($wholesale) : Money::fromFloat($sale);
        }

        if (str_contains($type, 'Supplier')) {
            return $purchase > 0 ? Money::fromFloat($purchase) : Money::fromFloat($sale);
        }

        // A retail sale must use the configured inventory sale price. Falling
        // back to cost hides a missing MRP/sale price and can sell stock at cost.
        return Money::fromFloat($sale);
    }

    /**
     * A negative or missing price column counts as "not set", not as a discount.
     */
    private function positive(mixed $value): float
    {
        $value = (float) ($value ?? 0);

        return $value > 0 ? $value : 0.0;
    }
}
