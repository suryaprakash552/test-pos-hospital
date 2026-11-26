<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Models\Customer;
use App\DTOs\SaleDTO;
use Illuminate\Support\Facades\DB;
use App\Helpers\HasUploader;

/**
 * NOT WIRED UP. Nothing references this class; sales are handled by
 * Api\AcnooSaleController and Modules\Business\...\AcnooSaleController.
 *
 * Its stock adjustments also lack the `lockForUpdate()` that the live
 * controllers use, so do not adopt it piecemeal - either finish routing sales
 * through it (carrying the locking over) or delete it. Editing this file alone
 * changes no behaviour.
 */
class SaleService extends BaseService
{
    use HasUploader;

    /**
     * Get sales with filtering and pagination.
     */
    public function getSales(array $filters = [], int $perPage = 100)
    {
        $query = Sale::with([
            'user:id,name,role',
            'customer:id,name,email,phone,type,relationship',
            'details',
            'details.stock:id,batch_no',
            'details.stock.purchaseDetails',
            'details.product:id,productName,category_id,productCode,productPurchasePrice,productStock,product_type,vat_id,vat_type,vat_amount',
            'details.product.category:id,categoryName',
            'saleReturns.details',
            'vat:id,name,rate',
            'doctor:id,name,qualification,specialization',
            'payment_type:id,name',
            'branch:id,name,phone,address',
            'details.product.vat:id,name,rate'
        ]);

        // Apply role-based filtering
        if ($this->isStaff()) {
            $query->where('user_id', $this->authUser()->id);
        } elseif ($this->isShopOwner()) {
            $query->where('business_id', $this->businessId());
        }

        // Apply filters
        if (!empty($filters['invoiceNumber'])) {
            $query->where('invoiceNumber', $filters['invoiceNumber']);
        }

        if (!empty($filters['phone']) && !empty($filters['relationship'])) {
            $query->whereHas('customer', function ($q) use ($filters) {
                $q->where('phone', $filters['phone'])
                  ->where('relationship', $filters['relationship']);
            });
        }

        if (!empty($filters['phone']) && empty($filters['relationship'])) {
            $query->whereHas('customer', function ($q) use ($filters) {
                $q->where('phone', $filters['phone']);
            });
        }

        if (!empty($filters['name'])) {
            $query->whereHas('customer', function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['name'] . '%');
            });
        }

        if (!empty($filters['min_amount'])) {
            $query->where('totalAmount', '>=', (float) $filters['min_amount']);
        }

        if (!empty($filters['returned_sales']) && $filters['returned_sales'] === 'true') {
            $query->whereHas('saleReturns');
        }

        if (!empty($filters['from_date']) && !empty($filters['to_date'])) {
            $query->whereDate('saleDate', '>=', $filters['from_date'])
                  ->whereDate('saleDate', '<=', $filters['to_date']);
        }

        if (!empty($filters['from_date']) && empty($filters['to_date'])) {
            $query->whereDate('saleDate', '>=', $filters['from_date']);
        }

        if (empty($filters['from_date']) && !empty($filters['to_date'])) {
            $query->whereDate('saleDate', '<=', $filters['to_date']);
        }

        if (!empty($filters['doctor_name'])) {
            $query->whereHas('doctor', function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['doctor_name'] . '%');
            });
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Create a new sale.
     */
    public function createSale(SaleDTO $dto): Sale
    {
        return $this->transaction(function () use ($dto) {
            $sale = Sale::create([
                'invoiceNumber' => $dto->invoiceNumber ?? $this->generateInvoiceNumber(),
                'saleDate' => $dto->saleDate,
                'customer_id' => $dto->customer_id,
                'user_id' => $dto->user_id ?? $this->authUser()->id,
                'business_id' => $dto->business_id ?? $this->businessId(),
                'branch_id' => $dto->branch_id ?? $this->branchId(),
                'payment_type_id' => $dto->payment_type_id,
                'doctor_id' => $dto->doctor_id,
                'totalAmount' => $dto->totalAmount,
                'paidAmount' => $dto->paidAmount ?? 0,
                'dueAmount' => $dto->dueAmount ?? 0,
                'discountAmount' => $dto->discountAmount ?? 0,
                'changeAmount' => $dto->changeAmount ?? 0,
                'cashAmount' => $dto->cashAmount ?? 0,
                'upiAmount' => $dto->upiAmount ?? 0,
                'cardAmount' => $dto->cardAmount ?? 0,
                'paymentNote' => $dto->paymentNote,
                'saleNote' => $dto->saleNote,
                'shippingAddress' => $dto->shippingAddress,
                'rounding_option' => $dto->rounding_option,
                'rounded_amount' => $dto->rounded_amount,
                'order_management_id' => $dto->order_management_id,
            ]);

            // Create sale details
            if (!empty($dto->details)) {
                foreach ($dto->details as $detail) {
                    SaleDetails::create([
                        'sale_id' => $sale->id,
                        'product_id' => $detail['product_id'],
                        'stock_id' => $detail['stock_id'] ?? null,
                        'quantities' => $detail['quantities'],
                        'price' => $detail['price'],
                        'discount' => $detail['discount'] ?? 0,
                        'subtotal' => $detail['subtotal'],
                    ]);

                    // Update stock
                    if (!empty($detail['stock_id'])) {
                        $stock = Stock::find($detail['stock_id']);
                        if ($stock) {
                            $stock->decrement('productStock', $detail['quantities']);
                        }
                    }
                }
            }

            // Update customer due
            if ($dto->customer_id && $dto->dueAmount > 0) {
                $customer = Customer::find($dto->customer_id);
                if ($customer) {
                    $customer->increment('due', $dto->dueAmount);
                }
            }

            return $sale->fresh();
        });
    }

    /**
     * Update an existing sale.
     */
    public function updateSale(Sale $sale, SaleDTO $dto): Sale
    {
        return $this->transaction(function () use ($sale, $dto) {
            $sale->update([
                'saleDate' => $dto->saleDate,
                'customer_id' => $dto->customer_id,
                'payment_type_id' => $dto->payment_type_id,
                'doctor_id' => $dto->doctor_id,
                'totalAmount' => $dto->totalAmount,
                'paidAmount' => $dto->paidAmount,
                'dueAmount' => $dto->dueAmount,
                'discountAmount' => $dto->discountAmount,
                'changeAmount' => $dto->changeAmount,
                'cashAmount' => $dto->cashAmount,
                'upiAmount' => $dto->upiAmount,
                'cardAmount' => $dto->cardAmount,
                'paymentNote' => $dto->paymentNote,
                'saleNote' => $dto->saleNote,
                'shippingAddress' => $dto->shippingAddress,
                'rounding_option' => $dto->rounding_option,
                'rounded_amount' => $dto->rounded_amount,
            ]);

            return $sale->fresh();
        });
    }

    /**
     * Delete a sale.
     */
    public function deleteSale(Sale $sale): bool
    {
        return $this->transaction(function () use ($sale) {
            // Restore stock
            foreach ($sale->details as $detail) {
                if ($detail->stock_id) {
                    $stock = Stock::find($detail->stock_id);
                    if ($stock) {
                        $stock->increment('productStock', $detail->quantities);
                    }
                }
            }

            // Update customer due
            if ($sale->customer_id && $sale->dueAmount > 0) {
                $customer = Customer::find($sale->customer_id);
                if ($customer) {
                    $customer->decrement('due', $sale->dueAmount);
                }
            }

            return $sale->delete();
        });
    }

    /**
     * Generate invoice number.
     */
    protected function generateInvoiceNumber(): string
    {
        $prefix = 'INV-';
        $date = now()->format('Ymd');
        $lastSale = Sale::where('invoiceNumber', 'like', $prefix . $date . '%')
                       ->orderBy('id', 'desc')
                       ->first();

        if ($lastSale) {
            $lastNumber = (int) substr($lastSale->invoiceNumber, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $date . '-' . $newNumber;
    }

    /**
     * Search sale by invoice ID.
     */
    public function searchByInvoiceId(string $invoiceId)
    {
        return Sale::with(['customer', 'details.product', 'details.stock'])
                   ->where('invoiceNumber', $invoiceId)
                   ->where('business_id', $this->businessId())
                   ->first();
    }

    /**
     * Get product report.
     */
    public function getProductReport(array $filters = [])
    {
        $query = SaleDetails::with(['sale', 'product', 'product.category'])
                           ->whereHas('sale', function ($q) {
                               if ($this->isStaff()) {
                                   $q->where('user_id', $this->authUser()->id);
                               } else {
                                   $q->where('business_id', $this->businessId());
                               }
                           });

        if (!empty($filters['from_date']) && !empty($filters['to_date'])) {
            $query->whereHas('sale', function ($q) use ($filters) {
                $q->whereDate('saleDate', '>=', $filters['from_date'])
                  ->whereDate('saleDate', '<=', $filters['to_date']);
            });
        }

        return $query->get();
    }
}
