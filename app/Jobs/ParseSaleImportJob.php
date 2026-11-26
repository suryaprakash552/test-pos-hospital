<?php

namespace App\Jobs;

use App\Models\SaleImportBatch;
use App\Services\Import\PurchaseCsvParser;
use App\Services\Import\BillTextParser;
use App\Services\Import\PurchasePdfParser;
use App\Services\Import\SaleLineMatcher;
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
 * Reads an uploaded sales bill into reviewable lines.
 *
 * Mirrors ParsePurchaseImportJob exactly, one column reused as-is: what a
 * shop's own bill calls "Rate" is what a supplier's calls "Rate" too, so
 * the same format readers (CSV, spreadsheet, PDF, photographed text) work
 * for both directions — only the matcher differs, because a sale also has
 * to decide which existing batch to draw from.
 *
 * Writes nothing but draft lines; a person reviews and confirms before any
 * of it becomes a real sale.
 */
class ParseSaleImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

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
        SaleLineMatcher $matcher,
    ): void {
        $batch = SaleImportBatch::find($this->batchId);

        if (! $batch || $batch->status !== 'queued') {
            return;
        }

        $batch->update(['status' => 'processing']);

        try {
            $contents = Storage::disk($batch->disk)->get($batch->path);

            if ($contents === null) {
                throw new \RuntimeException('The uploaded file could not be read.');
            }

            $rows = match ($batch->source) {
                'pdf' => $pdfParser->parse($contents),
                'xlsx' => $spreadsheetParser->parse($contents),
                'photo' => $textParser->parse($contents),
                default => $csvParser->parse($contents),
            };

            if ($rows === []) {
                throw new \RuntimeException(match ($batch->source) {
                    'pdf' => 'No product rows were found. Image-only PDFs are not supported yet. '
                        .'Use a text PDF, Excel or CSV.',
                    'photo' => 'No product rows could be read from that picture. Try again '
                        .'with the bill flat, well lit, and filling the frame.',
                    default => 'No named products were found. Remove empty rows or use a text PDF, Excel or CSV.',
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
            if (! $batch->sale_date && $documentDate) {
                $headerUpdates['sale_date'] = $documentDate;
            }
            if ($headerUpdates !== []) {
                $batch->update($headerUpdates);
            }

            $matched = 0;
            $reviewRows = 0;

            foreach ($rows as $index => $row) {
                // PDFs often expose totals/empty table cells as numeric rows.
                // They are not products and must never appear as "Line 5" in
                // the employee review (or be eligible for checkout).
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

            Log::info('Sale import parsed', [
                'batch_id' => $batch->id,
                'business_id' => $batch->business_id,
                'rows' => count($rows),
                'matched' => $matched,
            ]);
        } catch (Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'error' => ImportFailureMessage::forUser($e),
            ]);

            Log::error('Sale import failed', [
                'batch_id' => $batch->id,
                'business_id' => $batch->business_id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->discardUpload($batch);
        }
    }

    private function discardUpload(SaleImportBatch $batch): void
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
