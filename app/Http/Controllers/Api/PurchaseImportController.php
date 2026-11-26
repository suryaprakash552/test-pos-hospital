<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ParsePurchaseImportJob;
use App\Models\PurchaseImportBatch;
use App\Models\Product;
use App\Services\Import\PurchaseCsvParser;
use App\Services\Import\ImportFailureMessage;
use App\Support\ActiveBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Bringing a supplier bill in, without letting it write stock on its own.
 *
 * The flow is upload → parse → **review** → commit. The review is the point:
 * the existing bulk upload turns a spreadsheet into a purchase inside the job,
 * which cannot be extended to a photographed bill because a misread quantity
 * would become real stock with nobody having seen it.
 *
 * Committing does not create a purchase here either. It assembles the confirmed
 * lines and hands them to the ordinary purchase endpoint, so pricing, GST,
 * stock and idempotency stay in the one place that already gets them right —
 * there is no second way to write a purchase.
 */
class PurchaseImportController extends Controller
{
    /**
     * Accepts a bill for parsing.
     *
     * The upload is treated as hostile: the extension is checked *and* the MIME
     * type is sniffed from the file's own bytes, because a `.csv` extension is
     * a claim by whoever uploaded it. It is stored on a private disk under a
     * generated name — never the supplied filename, which is attacker-chosen
     * text that has no business becoming a path.
     *
     * A PDF widens what is accepted, so it narrows separately rather than being
     * added to the CSV rule: asking for a CSV must not start accepting PDFs.
     *
     * A photographed bill arrives as text, not as a picture. The phone reads it
     * on the device and posts what it read, so the shop's bills are not shipped
     * to a server as images and there is nothing here that decodes untrusted
     * image data. The text is written to the same private disk as an upload so
     * the rest of the flow — parse, review, commit — is identical.
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        // Declared first, because it decides what the request must carry.
        $source = in_array($request->input('source'), ['pdf', 'photo', 'xlsx'], true)
            ? $request->input('source')
            : 'csv';

        $validated = $request->validate([
            'source' => ['nullable', Rule::in(['csv', 'xlsx', 'pdf', 'photo'])],
            // Recognised text, for a photographed bill. Capped: a phone's OCR
            // of one bill is a few kilobytes, and the cap is what stops this
            // being a way to post a megabyte of anything.
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
                // Both: `mimes` trusts the extension, `mimetypes` reads the
                // bytes. A file has to be what it says it is by both accounts.
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
            'party_id' => [
                'required',
                'integer',
                Rule::exists('parties', 'id')->where(
                    fn ($query) => $query->where('business_id', $user->business_id)
                ),
            ],
            'reference_invoice_no' => ['nullable', 'string', 'max:100'],
            'bill_date' => ['nullable', 'date'],
        ]);

        $file = $request->file('file');

        if ($source === 'photo') {
            // Named here rather than by anything the client sent, for the same
            // reason an upload's filename is never used as a path.
            $path = "purchase-imports/{$user->business_id}/".Str::uuid().'.txt';
            Storage::disk('local')->put($path, $validated['text']);
        } else {
            // `store()` generates the name; the original is kept only as a
            // label to show the user. A supplied filename is never used as a
            // path.
            $path = $file->store(
                "purchase-imports/{$user->business_id}",
                ['disk' => 'local']
            );
        }

        $batch = PurchaseImportBatch::create([
            'business_id' => $user->business_id,
            'user_id' => $user->id,
            'branch_id' => ActiveBranch::forWrite($user),
            'party_id' => $validated['party_id'],
            'source' => $source,
            'original_filename' => $file?->getClientOriginalName() ?? __('Photographed bill'),
            'disk' => 'local',
            'path' => $path,
            'status' => 'queued',
            'reference_invoice_no' => $validated['reference_invoice_no'] ?? null,
            'bill_date' => $validated['bill_date'] ?? null,
        ]);

        ParsePurchaseImportJob::dispatch($batch->id);

        return response()->json([
            'message' => __('Upload accepted. Parsing the bill now.'),
            'data' => $this->present($batch->fresh()),
        ], 202);
    }

    /** The shop's own imports, newest first. */
    public function index(Request $request)
    {
        $batches = PurchaseImportBatch::query()
            ->forBusiness(auth()->user()->business_id)
            ->latest()
            ->paginate(min((int) $request->input('per_page', 20), 50));

        $batches->getCollection()->each(function (PurchaseImportBatch $batch): void {
            $batch->error = ImportFailureMessage::fromStored($batch->error);
        });

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $batches,
        ]);
    }

    /**
     * One batch with its lines, for the review screen.
     *
     * Scoped by business rather than looked up by id alone: an import carries a
     * supplier's prices and a shop's stock positions, and a bare `findOrFail`
     * would hand one shop another's document to anyone who guessed an id.
     */
    public function show(int $id)
    {
        $batch = PurchaseImportBatch::query()
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
     * Only the fields a person can reasonably judge from the paper in front of
     * them. Nothing here is a money total: the totals are the server's to
     * compute at commit, from the quantities and rates confirmed here.
     */
    public function updateLine(Request $request, int $id, int $lineId)
    {
        $user = auth()->user();

        $batch = PurchaseImportBatch::query()
            ->forBusiness($user->business_id)
            ->findOrFail($id);

        if (! $batch->isEditable()) {
            return response()->json([
                'message' => __('This import can no longer be changed.'),
                'reason' => 'not_editable',
                'status' => $batch->status,
            ], 409);
        }

        $validated = $request->validate([
            'product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(
                    fn ($query) => $query->where('business_id', $user->business_id)
                ),
            ],
            'batch_no' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'free_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'expire_date' => ['nullable', 'date'],
            'mfg_date' => ['nullable', 'date'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'match_status' => ['nullable', Rule::in(['confirmed', 'skipped'])],
        ]);

        $line = $batch->lines()->findOrFail($lineId);

        // Choosing a product is the act of confirming the line, so the status
        // follows from it rather than having to be sent separately.
        if (array_key_exists('product_id', $validated)
            && $validated['product_id'] !== null
            && ! isset($validated['match_status'])) {
            $validated['match_status'] = 'confirmed';
        }

        $line->update($validated);

        return response()->json([
            'message' => __('Data updated successfully.'),
            'data' => $line->fresh(),
        ]);
    }

    /**
     * Turns the confirmed lines into a purchase.
     *
     * Builds the payload and returns it for the client to post to
     * `/purchase` — the same endpoint the till uses. This controller
     * deliberately does not create the purchase itself: `PurchaseMutationRequest`
     * validates it and `PurchaseCalculationService` recomputes every figure, and
     * a second path into stock is exactly how two sets of totals appear.
     */
    public function commitPayload(int $id)
    {
        $user = auth()->user();

        $batch = PurchaseImportBatch::query()
            ->forBusiness($user->business_id)
            ->with('lines')
            ->findOrFail($id);

        if ($batch->isCommitted()) {
            return response()->json([
                'message' => __('This import has already been committed.'),
                'reason' => 'already_committed',
                'purchase_id' => $batch->purchase_id,
            ], 409);
        }

        if ($batch->status !== 'ready') {
            return response()->json([
                'message' => __('This import is not ready to save. Upload and review the bill again.'),
                'reason' => 'import_not_ready',
                'status' => $batch->status,
            ], 409);
        }

        $committable = $batch->lines->filter->isCommittable();

        if ($committable->isEmpty()) {
            return response()->json([
                'message' => __('No line is ready yet. Match a product and enter a quantity first.'),
                'reason' => 'nothing_to_commit',
            ], 422);
        }

        // Conversion and tax come from the product, not the bill: the shop's own
        // record of how many tablets are in a strip is the one the till sells
        // against, and a supplier's sheet does not get to change it.
        $products = Product::query()
            ->where('business_id', $user->business_id)
            ->whereIn('id', $committable->pluck('product_id'))
            ->with('vat:id,rate')
            ->get()
            ->keyBy('id');

        $lines = [];
        $taxErrors = [];

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

            $conversion = (float) ($product->conversionValue ?: 1);
            $conversion = $conversion > 0 ? $conversion : 1.0;

            $quantity = (float) $line->quantity;
            $free = (float) ($line->free_quantity ?? 0);
            $rate = (float) ($line->purchase_price ?? 0);
            $mrp = (float) ($line->mrp ?? $product->productSalePrice ?? 0);

            $lines[] = [
                'product_id' => $product->id,
                'product_name' => $product->productName,
                'product_type' => $product->product_type,
                'vat_rate' => $inventoryTax,
                'vat_type' => $product->vat_type ?? 'exclusive',
                'quantities' => $quantity,
                'free_quantity' => $free,
                'productPurchasePrice' => $rate,
                'productSalePrice' => $mrp,
                'conversion_purchase_price' => $conversion > 0 ? $rate / $conversion : $rate,
                'conversion_quantities' => ($quantity + $free) * $conversion,
                'conversion_value' => $conversion,
                'conversion_price' => $conversion > 0 ? $mrp / $conversion : $mrp,
                // Preserve the supplier's reviewed line discount. The normal
                // purchase pricing service validates and applies this field;
                // dropping it here made an imported bill more expensive than
                // the document the operator had just approved.
                'discount_percent' => (float) ($line->discount_percent ?? 0),
                'batch_no' => $line->batch_no,
                'expire_date' => optional($line->expire_date)->toDateString()
                    ?? now()->addYear()->toDateString(),
                'mfg_date' => optional($line->mfg_date)->toDateString()
                    ?? now()->toDateString(),
                'upper_selected_unit' => $product->productUpperUnit,
                'lower_selected_unit' => $product->productLowerUnit,
                // Sent because the endpoint requires them, and immediately
                // recomputed by `PurchaseCalculationService`. Nothing the client
                // or this controller says about money survives.
                'total_purchase_price' => $quantity * $rate,
                'total_price' => $quantity * $mrp,
            ];
        }

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                'tax_adjustments' => $taxErrors,
                'party_id' => $batch->party_id,
                'purchaseDate' => optional($batch->bill_date)->toDateString()
                    ?? now()->toDateString(),
                'reference_invoice_no' => $batch->reference_invoice_no,
                'products' => $lines,
                'skipped' => $batch->lines->count() - count($lines),
            ],
        ]);
    }

    /** Records that a batch became a purchase, so it cannot be committed twice. */
    public function markCommitted(Request $request, int $id)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'purchase_id' => [
                'required',
                'integer',
                Rule::exists('purchases', 'id')->where(
                    fn ($query) => $query->where('business_id', $user->business_id)
                ),
            ],
        ]);

        $batch = PurchaseImportBatch::query()
            ->forBusiness($user->business_id)
            ->findOrFail($id);

        if ($batch->isCommitted()) {
            return response()->json([
                'message' => __('This import has already been committed.'),
                'reason' => 'already_committed',
                'purchase_id' => $batch->purchase_id,
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
            'purchase_id' => $validated['purchase_id'],
            'committed_at' => now(),
        ]);

        return response()->json(['message' => __('Data updated successfully.')]);
    }

    public function destroy(int $id)
    {
        $batch = PurchaseImportBatch::query()
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
    private function present(PurchaseImportBatch $batch, bool $withLines = false): array
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
