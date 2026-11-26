<?php

namespace App\Jobs;

use App\Imports\PurchaseImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Imports one purchase from an uploaded spreadsheet.
 *
 * Runs on the queue because a delivery can carry hundreds of lines and each one may
 * create or lock a stock batch. Not retried automatically: the import creates a real
 * purchase and increments stock, so a retry after a partial failure would double the
 * delivery. A failure is logged for someone to look at instead.
 */
class ProcessPurchaseBulkUploadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** One attempt only — see the class note on double-counting stock. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        private readonly int $businessId,
        private readonly int $userId,
        private readonly string $disk,
        private readonly string $path,
        private readonly ?int $partyId = null,
        private readonly ?string $purchaseDate = null,
        private readonly ?string $invoiceNumber = null,
    ) {
        $this->onQueue(config('pos.queues.imports', 'default'));
    }

    public function handle(): void
    {
        Log::info('Purchase bulk upload started', [
            'business_id' => $this->businessId,
            'user_id'     => $this->userId,
            'path'        => $this->path,
        ]);

        $import = new PurchaseImport(
            $this->businessId,
            $this->userId,
            $this->partyId,
            $this->purchaseDate,
            $this->invoiceNumber,
        );

        try {
            Excel::import($import, $this->path, $this->disk);
            \App\Services\Import\BulkUploadNotifier::send(
                $this->businessId, $this->userId, 'purchase', true,
                $import->importedCount(), count($import->errors()));

            Log::info('Purchase bulk upload finished', [
                'business_id' => $this->businessId,
                'imported'    => $import->importedCount(),
                // Rows the sheet got wrong — an unknown product code, a zero
                // quantity. Worth surfacing so the file can be corrected.
                'rejected'    => $import->errors(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Purchase bulk upload failed', [
                'business_id' => $this->businessId,
                'user_id'     => $this->userId,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            // The upload is a scratch file; keeping it would accumulate customer
            // data on disk for no benefit.
            Storage::disk($this->disk)->delete($this->path);
        }
    }

    public function failed(\Throwable $exception): void
    {
        \App\Services\Import\BulkUploadNotifier::send($this->businessId, $this->userId, 'purchase', false);
    }
}
