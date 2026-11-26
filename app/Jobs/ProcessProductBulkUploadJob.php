<?php

namespace App\Jobs;

use App\Imports\ProductImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ProcessProductBulkUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt only, for the same reason `ProcessPurchaseBulkUploadJob` has
     * always had it: a retry re-runs work that is already committed.
     *
     * `ProductImport` opens its transaction *inside* `collection()` while
     * reading in chunks of 500, so each chunk commits on its own. A failure on
     * row 700 therefore leaves rows 1–500 permanently in the database — and
     * three attempts then re-ran those rows, re-executing every
     * `firstOrCreate` for categories, brands, units and models and every
     * `Stock::updateOrCreate` behind them.
     *
     * A failed import that stopped half way is recoverable: the shop corrects
     * the file and uploads the rest. A failed import that silently applied its
     * first chunk three times is not.
     */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        private readonly int $businessId,
        private readonly int $userId,
        private readonly string $disk,
        private readonly string $path,
        /// Set when the upload was started from a category; every row lands in it.
        private readonly ?int $categoryId = null,
    ) {
        $this->onQueue(config('pos.queues.imports', 'default'));
    }

    public function handle(): void
    {
        Log::info('Product bulk upload started', [
            'business_id' => $this->businessId,
            'user_id' => $this->userId,
            'path' => $this->path,
        ]);

        $import = new ProductImport($this->businessId, $this->categoryId);

        Excel::import($import, $this->path, $this->disk);
        \App\Services\Import\BulkUploadNotifier::send(
            $this->businessId, $this->userId, 'product', true,
            $import->importedCount(), count($import->errors()));

        Storage::disk($this->disk)->delete($this->path);

        Log::info('Product bulk upload finished', [
            'business_id' => $this->businessId,
            'user_id' => $this->userId,
            'imported' => $import->importedCount(),
            // Rows the sheet got wrong — an impossible GST rate, a malformed HSN.
            // Worth surfacing so the file can be corrected rather than leaving the
            // products quietly missing.
            'rejected' => $import->errors(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        \App\Services\Import\BulkUploadNotifier::send($this->businessId, $this->userId, 'product', false);
        Log::error('Product bulk upload failed', [
            'business_id' => $this->businessId,
            'user_id' => $this->userId,
            'path' => $this->path,
            'error' => $exception->getMessage(),
        ]);
    }
}
