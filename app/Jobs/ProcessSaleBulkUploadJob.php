<?php

namespace App\Jobs;

use App\Imports\SaleImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Imports one sale from an uploaded spreadsheet.
 *
 * Never retried. The import consumes stock, so a retry after a partial failure would
 * consume it twice — and unlike a failed purchase, that shortfall is invisible until
 * someone tries to sell an item the system thinks it has.
 */
class ProcessSaleBulkUploadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** One attempt only — see the class note on double-consuming stock. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        private readonly int $businessId,
        private readonly int $userId,
        private readonly string $disk,
        private readonly string $path,
        private readonly ?int $customerId = null,
        private readonly ?string $saleDate = null,
        private readonly ?string $invoiceNumber = null,
    ) {
        $this->onQueue(config('pos.queues.imports', 'default'));
    }

    public function handle(): void
    {
        Log::info('Sale bulk upload started', [
            'business_id' => $this->businessId,
            'user_id'     => $this->userId,
        ]);

        $import = new SaleImport(
            $this->businessId,
            $this->userId,
            $this->customerId,
            $this->saleDate,
            $this->invoiceNumber,
        );

        try {
            Excel::import($import, $this->path, $this->disk);
            \App\Services\Import\BulkUploadNotifier::send(
                $this->businessId, $this->userId, 'sale', true,
                $import->importedCount(), count($import->errors()));

            Log::info('Sale bulk upload finished', [
                'business_id' => $this->businessId,
                'sale_id'     => $import->saleId(),
                'lines'       => $import->importedCount(),
            ]);
        } catch (\Throwable $e) {
            // The import is all-or-nothing, so a failure means nothing was consumed.
            Log::error('Sale bulk upload failed; no stock was consumed', [
                'business_id' => $this->businessId,
                'user_id'     => $this->userId,
                'error'       => $e->getMessage(),
                'rejected'    => $import->errors(),
            ]);

            throw $e;
        } finally {
            Storage::disk($this->disk)->delete($this->path);
        }
    }

    public function failed(\Throwable $exception): void
    {
        \App\Services\Import\BulkUploadNotifier::send($this->businessId, $this->userId, 'sale', false);
    }
}
