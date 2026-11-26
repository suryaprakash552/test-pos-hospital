<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ParseSaleImportJob;
use App\Models\SaleImportBatch;
use App\Models\Product;
use App\Models\Stock;
use App\Services\Import\PurchaseCsvParser;
use App\Services\Import\ImportFailureMessage;
use App\Support\ActiveBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Bringing a sales bill in, without letting it write a sale on its own.
 *
 * Mirrors PurchaseImportController: upload → parse → **review** → commit.
 * Committing does not create a sale here — it assembles the confirmed
 * lines and hands them to the ordinary sale endpoint, so pricing, GST,
 * stock and idempotency stay in the one place that already gets them
 * right. There is no second way to write a sale.
 */
class SaleImportController extends Controller
{
    /**
     * Accepts a bill for parsing. See PurchaseImportController::store() for
     * why the upload is validated the way it is — the same reasoning
     * applies unchanged here.
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $source = in_array($request->input('source'), ['pdf', 'photo', 'xlsx'], true)
            ? $request->input('source')
            : 'csv';

        $validated = $request->validate([
            'source' => ['nullable', Rule::in(['csv', 'xlsx', 'pdf', 'photo'])],
            'text' => [
                Rule::requiredIf($source === 'photo'),
                'prohibited_unless:source,photo',
                'string',
                'max:120000',
            ],
            'file' => [
                Rule::requiredIf($source !== 'photo'),
                'prohibited_if:source,photo',
                'file',
                ...($source === 'pdf'
                    ? ['mimes:pdf', 'mimetypes:application/pdf', 'max:10240']
                    : ($source === 'xlsx'
                    ? ['mimes:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'max:10240']
                    : [
                        'mimes:csv,txt',
                        'mimetypes:text/plain,text/csv,application/csv',
                        'max:5120',
                    ])),
            ],
            // Unlike a purchase's supplier, a customer is not required — a
            // walk-in sale with no customer on file is ordinary, and the
            // till already allows it.
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(
                    fn ($query) => $query->where('business_id', $user->business_id)
                ),
            ],
            'reference_invoice_no' => ['nullable', 'string', 'max:100'],
            'sale_date' => ['nullable', 'date'],
        ]);

        $file = $request->file('file');

        if ($source === 'photo') {
            $path = "sale-imports/{$user->business_id}/".Str::uuid().'.txt';
            Storage::disk('local')->put($path, $validated['text']);
        } else {
            $path = $file->store(
                "sale-imports/{$user->business_id}",
                ['disk' => 'local']
            );
        }

        $batch = SaleImportBatch::create([
            'business_id' => $user->business_id,
            'user_id' => $user->id,
            'branch_id' => ActiveBranch::forWrite($user),
            'customer_id' => $validated['customer_id'] ?? null,
            'source' => $source,
            'original_filename' => $file?->getClientOriginalName() ?? __('Photographed bill'),
            'disk' => 'local',
            'path' => $path,
            'status' => 'queued',
            'reference_invoice_no' => $validated['reference_invoice_no'] ?? null,
            'sale_date' => $validated['sale_date'] ?? null,
        ]);

        ParseSaleImportJob::dispatch($batch->id);

        return response()->json([
            'message' => __('Upload accepted. Parsing the bill now.'),
            'data' => $this->present($batch->fresh()),
        ], 202);
    }

    /** The shop's own imports, newest first. */
    public function index(Request $request)
    {
        $batches = SaleImportBatch::query()
            ->forBusiness(auth()->user()->business_id)
            ->latest()
            ->paginate(min((int) $request->input('per_page', 20), 50));

        $batches->getCollection()->each(function (SaleImportBatch $batch): void {
            $batch->error = ImportFailureMessage::fromStored($batch->error);
        });

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $batches,
        ]);
    }

    /** One batch with its lines, for the review screen. */
    public function show(int $id)
    {
        $batch = SaleImportBatch::query()
            ->forBusiness(auth()->user()->business_id)
            ->with('lines')
            ->findOrFail($id);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $this->present($batch, withLines: true),
        ]);
    }

    /**
     * Corrects one reviewed line.
     *
     * `stock_id` is validated against the *matched product's own* stock, not
     * merely the business — selling line 3's rate against line 7's batch is
     * exactly the mistake a reviewer picking from a dropdown could otherwise
     * make un-noticed.
     */
    public function updateLine(Request $request, int $id, int $lineId)
    {
        $user = auth()->user();

        $batch = SaleImportBatch::query()
            ->forBusiness($user->business_id)
            ->findOrFail($id);

        if (! $batch->isEditable()) {
            return response()->json([
                'message' => __('This import can no longer be changed.'),
                'reason' => 'not_editable',
                'status' => $batch->status,
            ], 409);
        }

        $line = $batch->lines()->findOrFail($lineId);

        $validated = $request->validate([
            'product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(
                    fn ($query) => $query->where('business_id', $user->business_id)
                ),
            ],
            'stock_id' => [
                'nullable',
                'integer',
                Rule::exists('stocks', 'id')->where(function ($query) use ($user, $request, $line) {
                    $query->where('business_id', $user->business_id)
                        ->where('product_id', $request->input('product_id', $line->product_id));
                }),
            ],
            'batch_no' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'match_status' => ['nullable', Rule::in(['confirmed', 'skipped'])],
        ]);

        // Choosing a product resets the batch — a product picked to replace
        // an unmatched one cannot keep another product's stock row attached.
        if (array_key_exists('product_id', $validated)
            && $validated['product_id'] !== $line->product_id
            && ! array_key_exists('stock_id', $validated)) {
            $validated['stock_id'] = null;
        }

        if ((array_key_exists('product_id', $validated) && $validated['product_id'] !== null
                || array_key_exists('stock_id', $validated) && $validated['stock_id'] !== null)
            && ! isset($validated['match_status'])) {
            $validated['match_status'] = 'confirmed';
        }

        // The review card is rendered from the line snapshot. Updating only
        // product_id left the old imported name on screen after the employee
        // deliberately replaced it, making a successful edit look ignored.
        if (array_key_exists('product_id', $validated)
            && $validated['product_id'] !== null) {
            $validated['product_name'] = Product::query()
                ->where('business_id', $user->business_id)
                ->whereKey($validated['product_id'])
                ->value('productName');
            $validated['note'] = null;
        }

        $line->update($validated);

        return response()->json([
            'message' => __('Data updated successfully.'),
            'data' => $line->fresh(),
        ]);
    }

    /**
     * The batches a matched product could sell from, for the review
     * screen's picker when more than one holds stock.
     */
    public function stockOptions(int $id, int $lineId)
    {
        $user = auth()->user();

        $batch = SaleImportBatch::query()
            ->forBusiness($user->business_id)
            ->findOrFail($id);

        $line = $batch->lines()->findOrFail($lineId);

        if ($line->product_id === null) {
            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => [],
            ]);
        }

        $stocks = Stock::query()
            ->where('business_id', $user->business_id)
            ->where('product_id', $line->product_id)
            ->where(fn ($query) => $query->whereNull('expire_date')
                ->orWhereDate('expire_date', '>=', now()->toDateString()))
            ->orderByRaw('expire_date IS NULL')->orderBy('expire_date')->orderBy('id')
            ->withSum('availableLots as available_quantity', 'conversionQuantity')
            ->get(['id', 'batch_no', 'expire_date'])
            ->filter(fn (Stock $stock) => $stock->available_quantity > 0)
            ->values();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $stocks,
        ]);
    }

    /**
     * Turns the confirmed lines into a sale.
     *
     * Builds the payload and returns it for the client to post to
     * `/sales` — the same endpoint the till uses. This controller
     * deliberately does not create the sale itself: `StoreSaleRequest`
     * validates it and the cart pricing service recomputes every figure.
     */
    public function commitPayload(Request $request, int $id)
    {
        $user = auth()->user();

        $batch = SaleImportBatch::query()
            ->forBusiness($user->business_id)
            ->with('lines')
            ->findOrFail($id);

        if ($batch->isCommitted()) {
            return response()->json([
                'message' => __('This import has already been committed.'),
                'reason' => 'already_committed',
                'sale_id' => $batch->sale_id,
            ], 409);
        }

        if ($batch->status !== 'ready') {
            return response()->json([
                'message' => __('This import is not ready to save. Upload and review the bill again.'),
                'reason' => 'import_not_ready',
                'status' => $batch->status,
            ], 409);
        }

        $automatic = $request->boolean('auto_allocate');
        if ($automatic && $batch->lines->contains(fn ($line) =>
            $line->match_status !== 'skipped'
            && ($line->product_id === null || (float) $line->quantity <= 0))) {
            return response()->json([
                'message' => __('Map every remaining product and enter a positive quantity, or remove the item before preparing the cart.'),
                'reason' => 'unresolved_lines',
            ], 422);
        }
        $committable = $automatic
            ? $batch->lines->filter(fn ($line) => $line->match_status !== 'skipped'
                && $line->product_id !== null && (float) $line->quantity > 0)
            : $batch->lines->filter->isCommittable();

        if ($committable->isEmpty()) {
            return response()->json([
                'message' => __('No line is ready yet. Match a product and a batch first.'),
                'reason' => 'nothing_to_commit',
            ], 422);
        }

        $products = Product::query()
            ->where('business_id', $user->business_id)
            ->whereIn('id', $committable->pluck('product_id'))
            ->with('vat:id,rate')
            ->get()
            ->keyBy('id');
        $stocks = Stock::query()
            ->where('business_id', $user->business_id)
            ->where(fn ($query) => $query->whereNull('expire_date')
                ->orWhereDate('expire_date', '>=', now()->toDateString()))
            ->when($automatic,
                fn ($query) => $query->whereIn('product_id', $committable->pluck('product_id'))
                    ->orderByRaw('expire_date IS NULL')->orderBy('expire_date')->orderBy('id'),
                fn ($query) => $query->whereIn('id', $committable->pluck('stock_id')))
            ->withSum('availableLots as available_quantity', 'conversionQuantity')
            ->get()
            ->keyBy('id');

        $customer = $batch->customer_id
            ? \App\Models\Customer::query()->where('business_id', $user->business_id)->find($batch->customer_id)
            : null;
        $priceResolver = app(\App\Services\Pricing\PriceResolver::class);
        $lines = [];
        $stockErrors = [];
        $taxErrors = [];
        $reserved = [];

        foreach ($committable as $line) {
            $product = $products->get($line->product_id);

            if (! $product) {
                continue;
            }

            $documentTax = $line->tax_percent;
            $inventoryTax = (float) ($product->vat?->rate ?? 0);
            if ($documentTax !== null && abs((float) $documentTax - $inventoryTax) > 0.001) {
                $taxErrors[] = [
                    'line_id' => $line->id,
                    'product_name' => $product->productName,
                    'document_tax_percent' => (float) $documentTax,
                    'inventory_tax_percent' => $inventoryTax,
                ];
            }

            $cartPrice = static function (Stock $stock) use ($priceResolver, $customer, $product): float {
                $packPrice = $priceResolver->forStock($stock, $customer)->toFloat();
                $conversion = (float) ($stock->conversionValue ?: $product->conversionValue ?: 1);
                return round($packPrice / max(1, $conversion), 2);
            };
            $quantity = (float) $line->quantity;
            if ($automatic) {
                $remaining = $quantity;
                $allocated = [];
                $availableTotal = 0.0;
                foreach ($stocks as $candidate) {
                    if ((int) $candidate->product_id !== (int) $line->product_id) {
                        continue;
                    }
                    $available = max(0, (float) $candidate->available_quantity
                        - ($reserved[$candidate->id] ?? 0));
                    $availableTotal += $available;
                    $take = min($remaining, $available);
                    if ($take <= 0) {
                        continue;
                    }
                    $price = $cartPrice($candidate);
                    if ($price <= 0) {
                        $stockErrors[] = [
                            'line_id' => $line->id,
                            'product_name' => $product->productName,
                            'reason' => 'missing_inventory_price',
                        ];
                        continue;
                    }
                    $allocated[] = [
                        'product_id' => $line->product_id,
                        'stock_id' => $candidate->id,
                        'batch_no' => $candidate->batch_no,
                        'available_quantity' => $available,
                        'purchase_price' => (float) ($candidate->productPurchasePrice ?? 0),
                        'quantities' => $take,
                        'price' => $price,
                        'vat_rate' => $inventoryTax,
                        'vat_type' => $product->vat_type,
                        'discount_percent' => (float) ($line->discount_percent ?? 0),
                        'lossProfit' => 0,
                        'product_name' => $product->productName,
                    ];
                    $remaining -= $take;
                }
                if ($remaining > 0.000001) {
                    $stockErrors[] = [
                        'line_id' => $line->id,
                        'product_name' => $product->productName,
                        'requested_quantity' => $quantity,
                        'available_quantity' => $availableTotal,
                        'shortage_quantity' => $remaining,
                    ];
                    continue;
                }
                foreach ($allocated as $allocation) {
                    $reserved[$allocation['stock_id']] =
                        ($reserved[$allocation['stock_id']] ?? 0) + $allocation['quantities'];
                    $lines[] = $allocation;
                }
                continue;
            }
            $stock = $stocks->get($line->stock_id);
            $available = max(0, (float) ($stock?->available_quantity ?? 0)
                - ($reserved[$line->stock_id] ?? 0));

            if (! $stock || $quantity <= 0 || $quantity > $available) {
                $stockErrors[] = [
                    'line_id' => $line->id,
                    'product_name' => $product->productName,
                    'requested_quantity' => $quantity,
                    'available_quantity' => $available,
                ];
                continue;
            }

            $price = $cartPrice($stock);
            if ($price <= 0) {
                $stockErrors[] = [
                    'line_id' => $line->id,
                    'product_name' => $product->productName,
                    'reason' => 'missing_inventory_price',
                ];
                continue;
            }

            $lines[] = [
                'product_id' => $line->product_id,
                'stock_id' => $line->stock_id,
                'batch_no' => $stock?->batch_no,
                'available_quantity' => $available,
                'purchase_price' => (float) ($stock?->productPurchasePrice ?? 0),
                'quantities' => $quantity,
                'price' => $price,
                'vat_rate' => $inventoryTax,
                'vat_type' => $product->vat_type,
                // It remains an ordinary sale-line discount: checkout sends
                // it to CartCalculationService, which enforces 0..100 and
                // prevents it exceeding the line value.
                'discount_percent' => (float) ($line->discount_percent ?? 0),
                // Recomputed by the sale endpoint from the batch's own cost;
                // sent because the endpoint requires it, same as a purchase
                // import's totals — nothing here about money survives.
                'lossProfit' => 0,
                'product_name' => $product->productName,
            ];
            $reserved[$line->stock_id] = ($reserved[$line->stock_id] ?? 0) + $quantity;
        }

        if ($stockErrors !== []) {
            return response()->json([
                'message' => __('Some imported items have insufficient stock or no Inventory selling price. Review them before checkout.'),
                'reason' => 'insufficient_stock',
                'lines' => $stockErrors,
            ], 409);
        }

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                'tax_adjustments' => $taxErrors,
                'customer_id' => $batch->customer_id,
                'saleDate' => optional($batch->sale_date)->toDateString()
                    ?? now()->toDateString(),
                'reference_invoice_no' => $batch->reference_invoice_no,
                'products' => $lines,
                'skipped' => $batch->lines->count() - $committable->count(),
            ],
        ]);
    }

    /** Records that a batch became a sale, so it cannot be committed twice. */
    public function markCommitted(Request $request, int $id)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'sale_id' => [
                'required',
                'integer',
                Rule::exists('sales', 'id')->where(
                    fn ($query) => $query->where('business_id', $user->business_id)
                ),
            ],
        ]);

        $batch = SaleImportBatch::query()
            ->forBusiness($user->business_id)
            ->findOrFail($id);

        if ($batch->isCommitted()) {
            return response()->json([
                'message' => __('This import has already been committed.'),
                'reason' => 'already_committed',
                'sale_id' => $batch->sale_id,
            ], 409);
        }

        if ($batch->status !== 'ready') {
            return response()->json([
                'message' => __('This import is not ready to save. Upload and review the bill again.'),
                'reason' => 'import_not_ready',
                'status' => $batch->status,
            ], 409);
        }

        $batch->update([
            'status' => 'committed',
            'sale_id' => $validated['sale_id'],
            'committed_at' => now(),
        ]);

        return response()->json(['message' => __('Data updated successfully.')]);
    }

    public function destroy(int $id)
    {
        $batch = SaleImportBatch::query()
            ->forBusiness(auth()->user()->business_id)
            ->findOrFail($id);

        if ($batch->isCommitted()) {
            return response()->json([
                'message' => __('A committed import cannot be discarded.'),
                'reason' => 'already_committed',
            ], 409);
        }

        $batch->update(['status' => 'discarded']);

        return response()->json(['message' => __('Data deleted successfully.')]);
    }

    /** @return array<string, mixed> */
    private function present(SaleImportBatch $batch, bool $withLines = false): array
    {
        $data = $batch->toArray();
        $data['error'] = ImportFailureMessage::fromStored($batch->error);
        $data['max_rows'] = PurchaseCsvParser::MAX_ROWS;

        if ($withLines) {
            $data['lines'] = $batch->lines->toArray();
        }

        return $data;
    }
}
