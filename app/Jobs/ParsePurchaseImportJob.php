<?php

namespace App\Jobs;

use App\Models\PurchaseImportBatch;
use App\Services\Import\PurchaseCsvParser;
use App\Services\Import\BillTextParser;
use App\Services\Import\PurchasePdfParser;
use App\Services\Import\PurchaseLineMatcher;
use App\Services\Import\PurchaseSpreadsheetParser;
use App\Services\Import\ImportFailureMessage;
use App\Services\Import\ImportProductIdentifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads an uploaded supplier bill into reviewable lines.
 *
 * Deliberately writes **nothing** but draft lines. The existing bulk upload
 * creates the purchase and increments stock from inside the job, which is why
 * it can never be used for a photographed bill: a misread quantity would become
 * real stock with nobody having seen it. This job stops at "here is what the
 * document appears to say", and a person decides whether that is true.
 *
 * Not retried. Parsing is cheap to redo by hand and a half-written batch is
 * more confusing than a failed one, so a failure is recorded on the batch where
 * the shop can see it rather than silently requeued.
 */
class ParsePurchaseImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * Long enough for a few thousand lines, short enough that a wedged parse
     * frees the worker for the next shop rather than holding it all day.
     */
    public int $timeout = 300;

    public function __construct(private readonly int $batchId)
    {
        $this->onQueue(config('pos.queues.imports', 'default'));
    }

    public function handle(
        PurchaseCsvParser $csvParser,
        PurchasePdfParser $pdfParser,
        PurchaseSpreadsheetParser $spreadsheetParser,
        BillTextParser $textParser,
        PurchaseLineMatcher $matcher,
    ): void {
        $batch = PurchaseImportBatch::find($this->batchId);

        if (! $batch || $batch->status !== 'queued') {
            // Already picked up, already committed, or deleted while waiting.
            return;
        }

        $batch->update(['status' => 'processing']);

        try {
            $contents = Storage::disk($batch->disk)->get($batch->path);

            if ($contents === null) {
                throw new \RuntimeException('The uploaded file could not be read.');
            }

            // Same review afterwards whatever the source; only the reading
            // differs, because a PDF has no columns to match a header against.
            $rows = match ($batch->source) {
                'pdf' => $pdfParser->parse($contents),
                'xlsx' => $spreadsheetParser->parse($contents),
                // Already text: the phone recognised it on the device.
                'photo' => $textParser->parse($contents),
                default => $csvParser->parse($contents),
            };

            if ($rows === []) {
                throw new \RuntimeException(match ($batch->source) {
                    'pdf' => 'No product rows were found. Image-only PDFs are not supported yet. '
                        .'Use a text PDF, Excel or CSV.',
                    'photo' => 'No product rows could be read from that picture. Try again '
                        .'with the bill flat, well lit, and filling the frame.',
                    default => 'No product rows were found. Check that the file has a header row '
                        .'naming the product, quantity and rate columns.',
                });
            }

            $header = $rows[0];
            $documentDate = $this->documentDate($header['document_date'] ?? null);
            $headerUpdates = [];
            if (! $batch->reference_invoice_no && ! empty($header['reference_invoice_no'])) {
                $headerUpdates['reference_invoice_no'] = mb_substr(
                    trim((string) $header['reference_invoice_no']), 0, 100
                );
            }
            if (! $batch->bill_date && $documentDate) {
                $headerUpdates['bill_date'] = $documentDate;
            }
            if ($headerUpdates !== []) {
                $batch->update($headerUpdates);
            }

            $matched = 0;
            $reviewRows = 0;

            foreach ($rows as $index => $row) {
                // Spreadsheet applications commonly retain formatting/formulas far
                // below the last real item. Those rows can still contain a number,
                // but without a product name/code they are not bill lines and must
                // not become confusing "Line 5" review cards.
                if (! ImportProductIdentifier::rowHasProduct($row)) {
                    continue;
                }
                $line = $matcher->match($batch->business_id, $row);

                $batch->lines()->create($line + [
                    'row_number' => $index + 1,
                    'raw' => $row,
                ]);
                $reviewRows++;

                if (($line['match_status'] ?? null) === 'matched') {
                    $matched++;
                }
            }

            if ($reviewRows === 0) {
                throw new \RuntimeException('No named products were found. Remove empty rows or use a text PDF, Excel or CSV.');
            }

            $batch->update([
                'status' => 'ready',
                'total_rows' => $reviewRows,
                'matched_rows' => $matched,
                'parsed_at' => now(),
                'error' => null,
            ]);

            Log::info('Purchase import parsed', [
                'batch_id' => $batch->id,
                'business_id' => $batch->business_id,
                'rows' => $reviewRows,
                'matched' => $matched,
            ]);
        } catch (Throwable $e) {
            // The message reaches the shop, so it says what to do about it. The
            // stack trace stays in the log.
            $batch->update([
                'status' => 'failed',
                'error' => ImportFailureMessage::forUser($e),
            ]);

            Log::error('Purchase import failed', [
                'batch_id' => $batch->id,
                'business_id' => $batch->business_id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // The document has been read, and it holds a supplier's prices. It
            // is not kept beyond the parse: `raw` on each line preserves what it
            // said, which is what anyone would need later.
            $this->discardUpload($batch);
        }
    }

    private function discardUpload(PurchaseImportBatch $batch): void
    {
        if (! $batch->disk || ! $batch->path) {
            return;
        }

        try {
            Storage::disk($batch->disk)->delete($batch->path);
            $batch->update(['path' => null]);
        } catch (Throwable $e) {
            Log::warning('Could not remove a parsed import upload', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function documentDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
