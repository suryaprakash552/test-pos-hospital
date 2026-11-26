<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Jobs\ProcessProductBulkUploadJob;
use App\Jobs\ProcessPurchaseBulkUploadJob;
use App\Jobs\ProcessSaleBulkUploadJob;
use App\Http\Controllers\Controller;

class BulkUploadControler extends Controller
{
    public function store(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            // Set when the upload was started from a category, so every row lands
            // in it and the spreadsheet's category column becomes optional.
            // Scoped to the business so one shop cannot import into another's.
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('business_id', $user->business_id)
                ),
            ],
        ]);
        $disk = config('filesystems.default', 'public');
        $path = $request->file('file')->store("bulk-uploads/products/{$user->business_id}", $disk);

        ProcessProductBulkUploadJob::dispatch(
            $user->business_id,
            $user->id,
            $disk,
            $path,
            $request->integer('category_id') ?: null
        );

        return response()->json([
            'message' => __('Bulk upload queued successfully.'),
            'data' => [
                'status' => 'queued',
                'queue' => 'imports',
                'category_id' => $request->integer('category_id') ?: null,
            ],
        ], 202);
    }

    /**
     * Imports a delivery as one real purchase.
     *
     * Separate from the product import because the two do different things: that one
     * creates catalogue entries, this one creates a transaction that moves stock. It
     * runs through the same PurchaseLineBuilder the API uses, so an imported purchase
     * and a typed one produce identical batches.
     */
    public function purchases(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            // The supplier the delivery came from. Scoped to the business so one
            // shop cannot attribute a purchase to another's supplier.
            'party_id' => [
                'nullable',
                'integer',
                Rule::exists('parties', 'id')->where(
                    fn ($query) => $query->where('business_id', $user->business_id)
                ),
            ],
            'purchaseDate'  => 'nullable|date',
            'invoiceNumber' => 'nullable|string|max:100',
        ]);

        $disk = config('filesystems.default', 'public');
        $path = $request->file('file')->store(
            "bulk-uploads/purchases/{$user->business_id}",
            $disk
        );

        ProcessPurchaseBulkUploadJob::dispatch(
            $user->business_id,
            $user->id,
            $disk,
            $path,
            $request->integer('party_id') ?: null,
            $request->input('purchaseDate'),
            $request->input('invoiceNumber'),
        );

        return response()->json([
            'message' => __('Bulk upload queued successfully.'),
            'data' => [
                'status' => 'queued',
                'queue'  => 'imports',
                'type'   => 'purchase',
            ],
        ], 202);
    }

    /**
     * Imports a sale from a spreadsheet.
     *
     * All or nothing: if any line cannot be fulfilled from stock the whole import is
     * abandoned and nothing is consumed. A half-imported sale under-bills the customer
     * while over-consuming stock, and the document does not say which half is missing.
     */
    public function sales(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(
                    fn ($query) => $query->where('business_id', $user->business_id)
                ),
            ],
            'saleDate'      => 'nullable|date',
            'invoiceNumber' => 'nullable|string|max:100',
        ]);

        $disk = config('filesystems.default', 'public');
        $path = $request->file('file')->store(
            "bulk-uploads/sales/{$user->business_id}",
            $disk
        );

        ProcessSaleBulkUploadJob::dispatch(
            $user->business_id,
            $user->id,
            $disk,
            $path,
            $request->integer('customer_id') ?: null,
            $request->input('saleDate'),
            $request->input('invoiceNumber'),
        );

        return response()->json([
            'message' => __('Bulk upload queued successfully.'),
            'data' => [
                'status' => 'queued',
                'queue'  => 'imports',
                'type'   => 'sale',
            ],
        ], 202);
    }
}
